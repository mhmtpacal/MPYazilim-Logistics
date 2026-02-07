<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Carriers\Ptt;

use InvalidArgumentException;
use MPYazilim\Logistics\Contracts\CarrierAdapterInterface;
use RuntimeException;
use SoapClient;
use Throwable;

final class PttCarrierAdapter implements CarrierAdapterInterface
{
    private const TRACKING_WSDL = 'https://pttws.ptt.gov.tr/GonderiTakipV2%s/services/Sorgu?wsdl';
    private const UPLOAD_WSDL = 'https://pttws.ptt.gov.tr/PttVeriYukleme%s/services/Sorgu?wsdl';

    /** @var array<string,SoapClient> */
    private array $clients = [];

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $account, array $payload, bool $testMode = false, bool $isReturn = false): array
    {
        $auth = $this->resolveAuth($account);
        $data = $this->normalizeUploadPayload($payload);

        try {
            $soap = $this->soap('upload', sprintf(self::UPLOAD_WSDL, $testMode ? 'Test' : ''));

            $response = $soap->kabulEkle2([
                'input' => [
                    'dosyaAdi' => $data['dosyaAdi'],
                    'gonderiTip' => $data['gonderiTip'],
                    'gonderiTur' => $data['gonderiTur'],
                    'kullanici' => $data['kullanici'] ?? 'PttWs',
                    'musteriId' => $isReturn ? $auth['username'] : $auth['posta_ceki'],
                    'sifre' => $auth['password'],
                    'dongu' => $data['dongu'],
                ],
            ]);

            return $this->responseToArray($response);
        } catch (Throwable $e) {
            throw new RuntimeException('PTT send hatasi: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function barkodTakip(string $username, string $password, string $barcode, bool $testMode = false): array
    {
        try {
            $soap = $this->soap('tracking', sprintf(self::TRACKING_WSDL, $testMode ? 'Test' : ''));

            $response = $soap->gonderiSorgu([
                'input' => [
                    'kullanici' => $username,
                    'sifre' => $password,
                    'barkod' => $barcode,
                ],
            ]);

            return $this->responseToArray($response);
        } catch (Throwable $e) {
            throw new RuntimeException('PTT barkodTakip hatasi: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function referansTakip(string $username, string $password, string $referansNo, bool $testMode = false): array
    {
        try {
            $soap = $this->soap('reference-tracking', sprintf(self::TRACKING_WSDL, $testMode ? 'Test' : ''));

            $response = $soap->gonderiSorgu_referansNo([
                'input' => [
                    'kullanici' => $username,
                    'sifre' => $password,
                    'referansNo' => $referansNo,
                ],
            ]);

            return $this->responseToArray($response);
        } catch (Throwable $e) {
            throw new RuntimeException('PTT referansTakip hatasi: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function kargoSil(string $username, string $password, string $barkod, string $dosyaAdi, bool $testMode = false): array
    {
        try {
            $soap = $this->soap('delete', sprintf(self::UPLOAD_WSDL, $testMode ? 'Test' : ''));

            $response = $soap->barkodVeriSil([
                'inpDelete' => [
                    'barcode' => $barkod,
                    'dosyaAdi' => $dosyaAdi,
                    'musteriId' => $username,
                    'sifre' => $password,
                ],
            ]);

            return $this->responseToArray($response);
        } catch (Throwable $e) {
            throw new RuntimeException('PTT kargoSil hatasi: ' . $e->getMessage(), 0, $e);
        }
    }

    private function soap(string $key, string $wsdl): SoapClient
    {
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $timeout = 20;
        @ini_set('default_socket_timeout', (string) $timeout);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'PHPSoapClient',
            ],
        ]);

        $this->clients[$key] = new SoapClient($wsdl, [
            'trace' => 0,
            'exceptions' => true,
            'connection_timeout' => 4,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => $context,
            'keep_alive' => false,
        ]);

        return $this->clients[$key];
    }

    /**
     * @param array<string,mixed> $account
     * @return array{username:string,password:string,posta_ceki:string}
     */
    private function resolveAuth(array $account): array
    {
        $username = $account['username'] ?? null;
        $password = $account['password'] ?? null;
        $postaCeki = $account['posta_ceki'] ?? null;

        if (!is_string($username) || $username === '') {
            throw new InvalidArgumentException('account.username zorunludur');
        }

        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('account.password zorunludur');
        }

        if (!is_string($postaCeki) || $postaCeki === '') {
            throw new InvalidArgumentException('account.posta_ceki zorunludur');
        }

        return ['username' => $username, 'password' => $password, 'posta_ceki' => $postaCeki];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{dosyaAdi:string,gonderiTip:string,gonderiTur:string,kullanici?:string,dongu:array<int,array<string,mixed>>}
     */
    private function normalizeUploadPayload(array $payload): array
    {
        foreach (['dosyaAdi', 'gonderiTip', 'gonderiTur', 'dongu'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException(sprintf('payload.%s zorunludur', $field));
            }
        }

        if (!is_array($payload['dongu']) || $payload['dongu'] === []) {
            throw new InvalidArgumentException('payload.dongu en az 1 kayit icermelidir');
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function responseToArray(object $response): array
    {
        if (property_exists($response, 'return')) {
            return (array) $response->return;
        }

        return (array) $response;
    }
}
