<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Carriers\HepsiJet;

use InvalidArgumentException;
use MPYazilim\Logistics\Contracts\CarrierAdapterInterface;
use RuntimeException;

final class HepsiJetCarrierAdapter implements CarrierAdapterInterface
{
    private string $baseUrl = 'https://integration.hepsijet.com';
    private string $token = '';
    private int $tokenExpire = 0;
    private int $tokenTtlSeconds = 1800;

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $account, array $payload, bool $testMode = false, bool $isReturn = false): array
    {
        if ($isReturn) {
            throw new RuntimeException('HepsiJet iade desteklemiyor');
        }

        $auth = $this->resolveAccount($account);
        $this->baseUrl = $this->resolveBaseUrl($testMode);

        $body = array_merge($payload, [
            'company' => [
                'name' => $auth['company_name'],
                'abbreviationCode' => $auth['company_code'],
            ],
        ]);

        return $this->requestWithAuth('POST', '/delivery/sendDeliveryOrderEnhanced', $body, $auth);
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function kargoTakip(array $account, string $barcode, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $this->baseUrl = $this->resolveBaseUrl($testMode);

        return $this->requestWithAuth(
            'POST',
            '/deliveryTransaction/getDeliveryTracking',
            ['deliveries' => [['customerDeliveryNo' => $barcode]]],
            $auth
        );
    }

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function takipLinkOlustur(array $account, array $payload, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $this->baseUrl = $this->resolveBaseUrl($testMode);

        return $this->requestWithAuth('POST', '/delivery/integration/track', $payload, $auth);
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function kargoSil(array $account, string $barcode, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $this->baseUrl = $this->resolveBaseUrl($testMode);

        return $this->requestWithAuth(
            'POST',
            '/delivery/deleteDeliveryOrder/' . rawurlencode($barcode),
            [],
            $auth
        );
    }

    /**
     * @param array{username:string,password:string,company_name:string,company_code:string} $auth
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function requestWithAuth(
        string $method,
        string $path,
        ?array $body,
        array $auth,
        bool $allowRetry = true
    ): array {
        $this->ensureToken($auth);

        try {
            return $this->request(
                $method,
                $this->baseUrl . $path,
                $body,
                ['X-Auth-Token: ' . $this->token]
            );
        } catch (RuntimeException $e) {
            if ($allowRetry && $e->getCode() === 401) {
                $this->refreshTokenWithLock($auth, true);

                return $this->requestWithAuth($method, $path, $body, $auth, false);
            }

            throw $e;
        }
    }

    /**
     * @param array{username:string,password:string,company_name:string,company_code:string} $auth
     */
    private function ensureToken(array $auth): void
    {
        if ($this->hasUsableInMemoryToken()) {
            return;
        }

        $this->refreshTokenWithLock($auth, false);
    }

    /**
     * @param array{username:string,password:string,company_name:string,company_code:string} $auth
     */
    private function refreshTokenWithLock(array $auth, bool $force): void
    {
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hepsijet_token_refresh.lock';
        $fp = @fopen($lockFile, 'c+');

        if ($fp === false) {
            if (!$force) {
                $this->loadTokenFromCache($auth);
                if ($this->hasUsableInMemoryToken()) {
                    return;
                }
            }

            $this->refreshToken($auth);

            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                if (!$force) {
                    $this->loadTokenFromCache($auth);
                    if ($this->hasUsableInMemoryToken()) {
                        return;
                    }
                }

                $this->refreshToken($auth);

                return;
            }

            if (!$force) {
                $this->loadTokenFromCache($auth);
                if ($this->hasUsableInMemoryToken()) {
                    return;
                }
            }

            $this->refreshToken($auth);
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * @param array{username:string,password:string,company_name:string,company_code:string} $auth
     */
    private function refreshToken(array $auth): void
    {
        $response = $this->request(
            'GET',
            $this->baseUrl . '/auth/getToken',
            null,
            ['Authorization: Basic ' . base64_encode($auth['username'] . ':' . $auth['password'])]
        );

        if (($response['status'] ?? null) !== 'OK') {
            throw new RuntimeException('HepsiJet token alinamadi');
        }

        $token = $response['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('HepsiJet token response beklenen formatta degil');
        }

        $this->token = $token;
        $this->tokenExpire = time() + $this->tokenTtlSeconds;
        $this->persistTokenToCache($auth, $this->token, $this->tokenExpire);
    }

    /**
     * @param array{username:string,password:string,company_name:string,company_code:string} $auth
     */
    private function loadTokenFromCache(array $auth): void
    {
        $cacheFile = $this->cacheFilePath();
        if (!is_file($cacheFile)) {
            return;
        }

        $raw = @file_get_contents($cacheFile);
        if (!is_string($raw) || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        $item = $decoded[$this->cacheKey($auth)] ?? null;
        if (!is_array($item)) {
            return;
        }

        $token = $item['token'] ?? null;
        $expire = $item['expire'] ?? null;
        if (!is_string($token) || $token === '' || !is_int($expire)) {
            return;
        }

        if ($expire - time() <= 300) {
            return;
        }

        $this->token = $token;
        $this->tokenExpire = $expire;
    }

    /**
     * @param array{username:string,password:string,company_name:string,company_code:string} $auth
     */
    private function persistTokenToCache(array $auth, string $token, int $expire): void
    {
        $cacheFile = $this->cacheFilePath();
        $decoded = [];

        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $parsed = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        $decoded[$this->cacheKey($auth)] = [
            'token' => $token,
            'expire' => $expire,
        ];

        @file_put_contents($cacheFile, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function hasUsableInMemoryToken(): bool
    {
        return $this->token !== '' && ($this->tokenExpire - time() > 300);
    }

    /**
     * @param array{username:string,password:string,company_name:string,company_code:string} $auth
     */
    private function cacheKey(array $auth): string
    {
        return sha1($this->baseUrl . '|' . $auth['username'] . '|' . $auth['company_code']);
    }

    private function cacheFilePath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hepsijet_token_cache.json';
    }

    private function resolveBaseUrl(bool $testMode): string
    {
        return $testMode ? 'https://integration-apitest.hepsijet.com' : 'https://integration.hepsijet.com';
    }

    /**
     * @param array<string,mixed> $account
     * @return array{username:string,password:string,company_name:string,company_code:string}
     */
    private function resolveAccount(array $account): array
    {
        $username = $account['username'] ?? null;
        $password = $account['password'] ?? null;
        $companyName = $account['company_name'] ?? null;
        $companyCode = $account['company_code'] ?? null;

        if (!is_string($username) || $username === '') {
            throw new InvalidArgumentException('account.username zorunludur');
        }
        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('account.password zorunludur');
        }
        if (!is_string($companyName) || $companyName === '') {
            throw new InvalidArgumentException('account.company_name zorunludur');
        }
        if (!is_string($companyCode) || $companyCode === '') {
            throw new InvalidArgumentException('account.company_code zorunludur');
        }

        return [
            'username' => $username,
            'password' => $password,
            'company_name' => $companyName,
            'company_code' => $companyCode,
        ];
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<int,string> $headers
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_merge(
                ['Accept: application/json', 'Content-Type: application/json'],
                $headers
            ),
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            throw new RuntimeException('HepsiJet cURL hatasi: ' . ($err !== '' ? $err : 'unknown'));
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('HepsiJet gecersiz JSON: ' . mb_substr($raw, 0, 300));
        }

        if ($http >= 400) {
            throw new RuntimeException('HepsiJet HTTP ' . $http . ': ' . mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE), 0, 300), $http);
        }

        return $json;
    }
}

