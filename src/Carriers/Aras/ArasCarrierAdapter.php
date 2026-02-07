<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Carriers\Aras;

use InvalidArgumentException;
use MPYazilim\Logistics\Contracts\CarrierAdapterInterface;
use RuntimeException;
use SimpleXMLElement;
use SoapClient;
use Throwable;

final class ArasCarrierAdapter implements CarrierAdapterInterface
{
    private const ORDER_WSDL = 'https://customerws.araskargo.com.tr/arascargoservice.asmx?WSDL';
    private const QUERY_WSDL = 'https://customerservices.araskargo.com.tr/ArasCargoCustomerIntegrationService/ArasCargoIntegrationService.svc?wsdl';

    private ?SoapClient $orderClient = null;
    private ?SoapClient $queryClient = null;

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $account, array $payload, bool $testMode = false, bool $isReturn = false): array
    {
        $auth = $this->resolveAccount($account);
        $order = $this->normalizeOrderPayload($payload, $auth);

        try {
            $response = $this->orderClient()->SetOrder([
                'orderInfo' => ['Order' => $order],
                'userName' => $auth['username'],
                'password' => $auth['password'],
            ]);

            if (!isset($response->SetOrderResult->OrderResultInfo)) {
                return [];
            }

            return (array) $response->SetOrderResult->OrderResultInfo;
        } catch (Throwable $e) {
            throw new RuntimeException('Aras KargoyaGonder hatasi: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function kargoTakip(array $account, string $trackingNo): array
    {
        $auth = $this->resolveAccount($account);
        $trackingNo = trim($trackingNo);

        if ($trackingNo === '') {
            return $this->emptyTrackingResult();
        }

        $loginInfo = '<LoginInfo>'
            . '<UserName>' . htmlspecialchars($auth['username'], ENT_XML1) . '</UserName>'
            . '<Password>' . htmlspecialchars($auth['password'], ENT_XML1) . '</Password>'
            . '<CustomerCode>' . htmlspecialchars($auth['customer_code'], ENT_XML1) . '</CustomerCode>'
            . '</LoginInfo>';

        $queryInfo = '<QueryInfo>'
            . '<QueryType>39</QueryType>'
            . '<IntegrationCode>' . htmlspecialchars($trackingNo, ENT_XML1) . '</IntegrationCode>'
            . '</QueryInfo>';

        try {
            $response = $this->queryClient()->GetQueryXML([
                'loginInfo' => $loginInfo,
                'queryInfo' => $queryInfo,
            ]);

            $xmlStr = isset($response->GetQueryXMLResult) ? (string) $response->GetQueryXMLResult : '';
            if ($xmlStr === '') {
                return $this->emptyTrackingResult();
            }

            $xml = new SimpleXMLElement($xmlStr);
            $collection = $xml->Collection ?? null;
            if (!$collection) {
                return $this->emptyTrackingResult();
            }

            return [
                'TipKodu' => (string) ($collection->TIP_KODU ?? ''),
                'DurumKodu' => (string) ($collection->DURUM_KODU ?? ''),
                'Desi' => (string) ($collection->KG_DESI ?? ''),
                'Tutar' => (string) ($collection->TUTAR ?? ''),
                'Durum' => (string) ($collection->DURUMU ?? ''),
            ];
        } catch (Throwable $e) {
            throw new RuntimeException('Aras KargoTakip hatasi [' . $trackingNo . ']: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function barkodSil(array $account, string $integrationCode): array
    {
        $auth = $this->resolveAccount($account);
        $integrationCode = trim($integrationCode);
        if ($integrationCode === '') {
            throw new InvalidArgumentException('integrationCode bos birakilamaz');
        }

        try {
            $response = $this->orderClient()->CancelDispatch([
                'userName' => $auth['username'],
                'password' => $auth['password'],
                'integrationCode' => $integrationCode,
            ]);

            return (array) $response;
        } catch (Throwable $e) {
            throw new RuntimeException('Aras BarkodSil hatasi [' . $integrationCode . ']: ' . $e->getMessage(), 0, $e);
        }
    }

    private function orderClient(): SoapClient
    {
        if ($this->orderClient instanceof SoapClient) {
            return $this->orderClient;
        }

        $this->orderClient = $this->soap(self::ORDER_WSDL);

        return $this->orderClient;
    }

    private function queryClient(): SoapClient
    {
        if ($this->queryClient instanceof SoapClient) {
            return $this->queryClient;
        }

        $this->queryClient = $this->soap(self::QUERY_WSDL);

        return $this->queryClient;
    }

    private function soap(string $wsdl): SoapClient
    {
        $timeout = 8;
        @ini_set('default_socket_timeout', (string) $timeout);

        $context = stream_context_create([
            'http' => ['timeout' => $timeout],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        return new SoapClient($wsdl, [
            'trace' => 0,
            'exceptions' => true,
            'connection_timeout' => 3,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'stream_context' => $context,
            'keep_alive' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $account
     * @return array{username:string,password:string,customer_code:string}
     */
    private function resolveAccount(array $account): array
    {
        $username = $account['username'] ?? null;
        $password = $account['password'] ?? null;
        $customerCode = $account['customer_code'] ?? null;

        if (!is_string($username) || $username === '') {
            throw new InvalidArgumentException('account.username zorunludur');
        }

        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('account.password zorunludur');
        }

        if (!is_string($customerCode) || $customerCode === '') {
            throw new InvalidArgumentException('account.customer_code zorunludur');
        }

        return [
            'username' => $username,
            'password' => $password,
            'customer_code' => $customerCode,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{username:string,password:string,customer_code:string} $auth
     * @return array<string,mixed>
     */
    private function normalizeOrderPayload(array $payload, array $auth): array
    {
        $defaults = [
            'UserName' => $auth['username'],
            'Password' => $auth['password'],
            'TradingWaybillNumber' => '',
            'IntegrationCode' => '',
            'ReceiverName' => '',
            'ReceiverAddress' => '',
            'ReceiverPhone1' => '',
            'ReceiverCityName' => '',
            'ReceiverTownName' => '',
            'PayorTypeCode' => 0,
            'IsWorldWide' => 0,
            'IsCod' => 0,
            'CodAmount' => 0,
            'CodCollectionType' => 0,
            'PieceDetails' => ['BarcodeNumber' => ''],
        ];

        $normalized = array_merge($defaults, $payload);

        if (!isset($normalized['PieceDetails']) || !is_array($normalized['PieceDetails'])) {
            $normalized['PieceDetails'] = ['BarcodeNumber' => ''];
        }

        $required = [
            'TradingWaybillNumber',
            'IntegrationCode',
            'ReceiverName',
            'ReceiverAddress',
            'ReceiverPhone1',
            'ReceiverCityName',
            'ReceiverTownName',
        ];

        foreach ($required as $field) {
            $value = $normalized[$field] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                throw new InvalidArgumentException(sprintf('payload.%s zorunludur', $field));
            }
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyTrackingResult(): array
    {
        return [
            'TipKodu' => '',
            'DurumKodu' => '',
            'Desi' => '',
            'Tutar' => '',
            'Durum' => '',
        ];
    }
}
