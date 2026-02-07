<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Carriers\Dhl;

use InvalidArgumentException;
use MPYazilim\Logistics\Contracts\CarrierAdapterInterface;
use RuntimeException;

final class DhlCarrierAdapter implements CarrierAdapterInterface
{
    private string $baseUrl = 'https://api.mngkargo.com.tr';
    private string $token = '';
    private int $tokenExpiresAt = 0;
    private int $tokenTtlSeconds = 1800;

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $account, array $payload, bool $testMode = false, bool $isReturn = false): array
    {
        $auth = $this->resolveAccount($account);
        $this->baseUrl = $testMode ? 'https://testapi.mngkargo.com.tr' : 'https://api.mngkargo.com.tr';

        $path = $isReturn
            ? '/mngapi/api/standardcmdapi/createReturnOrder'
            : '/mngapi/api/standardcmdapi/createOrder';

        return $this->request('POST', $path, $payload, true, $auth);
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function kargoDurum(array $account, string $trackingNo, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $this->baseUrl = $testMode ? 'https://testapi.mngkargo.com.tr' : 'https://api.mngkargo.com.tr';

        $response = $this->request(
            'GET',
            '/mngapi/api/standardqueryapi/getshipment/' . rawurlencode($trackingNo),
            null,
            true,
            $auth
        );

        if (isset($response[0]['shipment']) && is_array($response[0]['shipment'])) {
            $shipment = $response[0]['shipment'];

            return [
                'Durum' => 1,
                'DurumKodu' => $shipment['shipmentStatusCode'] ?? '',
                'Desi' => $shipment['totalDesi'] ?? '',
                'Tutar' => $shipment['finalTotal'] ?? '',
                'TakipNo' => $shipment['shipmentId'] ?? '',
                'TeslimatTarih' => $shipment['shipmentDateTime'] ?? '',
            ];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function kargoHareketleri(array $account, string $trackingNo, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $this->baseUrl = $testMode ? 'https://testapi.mngkargo.com.tr' : 'https://api.mngkargo.com.tr';

        $response = $this->request(
            'GET',
            '/mngapi/api/standardqueryapi/trackshipment/' . rawurlencode($trackingNo),
            null,
            true,
            $auth
        );

        if (isset($response['type'])) {
            return [];
        }

        return $response;
    }

    /**
     * @param array<string,mixed> $account
     * @return array{username:string,password:string,client_id:string,client_secret:string}
     */
    private function resolveAccount(array $account): array
    {
        $username = $account['username'] ?? null;
        $password = $account['password'] ?? null;
        $clientId = $account['client_id'] ?? null;
        $clientSecret = $account['client_secret'] ?? null;

        if (!is_string($username) || $username === '') {
            throw new InvalidArgumentException('account.username zorunludur');
        }
        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('account.password zorunludur');
        }
        if (!is_string($clientId) || $clientId === '') {
            throw new InvalidArgumentException('account.client_id zorunludur');
        }
        if (!is_string($clientSecret) || $clientSecret === '') {
            throw new InvalidArgumentException('account.client_secret zorunludur');
        }

        return [
            'username' => $username,
            'password' => $password,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    /**
     * @param array{username:string,password:string,client_id:string,client_secret:string} $auth
     */
    private function refreshToken(array $auth): void
    {
        $payload = [
            'customerNumber' => $auth['username'],
            'password' => $auth['password'],
            'identityType' => 1,
        ];

        $res = $this->request('POST', '/mngapi/api/token', $payload, false, $auth, false);

        if (!isset($res['jwt']) || !is_string($res['jwt']) || $res['jwt'] === '') {
            throw new RuntimeException('DHL(MNG) token response beklenen formatta degil');
        }

        $this->token = $res['jwt'];
        $this->tokenExpiresAt = $this->resolveExpireTimestamp($res['jwtExpireDate'] ?? null);
        $this->persistTokenToCache($auth, $this->token, $this->tokenExpiresAt);
    }

    /**
     * @param array{username:string,password:string,client_id:string,client_secret:string} $auth
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body,
        bool $authRequired,
        array $auth,
        bool $allowRetry = true
    ): array {
        if ($authRequired && !$this->hasUsableInMemoryToken()) {
            $this->refreshTokenWithLock($auth);
        }

        $url = $this->baseUrl . $path;

        $headers = [
            'X-IBM-Client-Id: ' . $auth['client_id'],
            'X-IBM-Client-Secret: ' . $auth['client_secret'],
            'accept: application/json',
            'content-type: application/json',
        ];

        if ($authRequired) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, strtoupper($method) === 'GET' ? 20 : 10);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            throw new RuntimeException('DHL(MNG) cURL error: ' . ($err !== '' ? $err : 'unknown'));
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('DHL(MNG) invalid JSON (HTTP ' . $http . '): ' . mb_substr($raw, 0, 300));
        }

        if ($http === 401 && $authRequired && $allowRetry) {
            $this->refreshTokenWithLock($auth);

            return $this->request($method, $path, $body, $authRequired, $auth, false);
        }

        if ($http >= 400) {
            throw new RuntimeException('DHL(MNG) HTTP ' . $http . ': ' . mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE), 0, 300));
        }

        return $json;
    }

    /**
     * @param array{username:string,password:string,client_id:string,client_secret:string} $auth
     */
    private function refreshTokenWithLock(array $auth): void
    {
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dhl_mng_token_refresh.lock';
        $fp = @fopen($lockFile, 'c+');

        if ($fp === false) {
            $this->loadTokenFromCache($auth);
            if ($this->hasUsableInMemoryToken()) {
                return;
            }
            $this->refreshToken($auth);

            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                $this->loadTokenFromCache($auth);
                if ($this->hasUsableInMemoryToken()) {
                    return;
                }
                $this->refreshToken($auth);

                return;
            }

            $this->loadTokenFromCache($auth);
            if ($this->hasUsableInMemoryToken()) {
                return;
            }

            $this->refreshToken($auth);
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * @param array{username:string,password:string,client_id:string,client_secret:string} $auth
     */
    private function loadTokenFromCache(array $auth): void
    {
        $cacheFile = $this->cacheFilePath();
        if (!is_file($cacheFile)) {
            return;
        }

        $raw = @file_get_contents($cacheFile);
        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        $key = $this->cacheKey($auth);
        $item = $decoded[$key] ?? null;
        if (!is_array($item)) {
            return;
        }

        $token = $item['token'] ?? null;
        $expiresAt = $item['expires_at'] ?? null;

        if (!is_string($token) || $token === '' || !is_int($expiresAt)) {
            return;
        }

        if ($expiresAt <= time() + 60) {
            return;
        }

        $this->token = $token;
        $this->tokenExpiresAt = $expiresAt;
    }

    /**
     * @param array{username:string,password:string,client_id:string,client_secret:string} $auth
     */
    private function persistTokenToCache(array $auth, string $token, int $expiresAt): void
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
            'expires_at' => $expiresAt,
        ];

        @file_put_contents($cacheFile, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * @param array{username:string,password:string,client_id:string,client_secret:string} $auth
     */
    private function cacheKey(array $auth): string
    {
        return sha1($auth['username'] . '|' . $auth['client_id']);
    }

    private function cacheFilePath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dhl_mng_token_cache.json';
    }

    private function hasUsableInMemoryToken(): bool
    {
        return $this->token !== '' && $this->tokenExpiresAt > time() + 60;
    }

    private function resolveExpireTimestamp(mixed $jwtExpireDate): int
    {
        if (is_string($jwtExpireDate) && $jwtExpireDate !== '') {
            $ts = strtotime($jwtExpireDate);
            if ($ts !== false && $ts > 0) {
                return (int) $ts;
            }
        }

        return time() + $this->tokenTtlSeconds;
    }
}
