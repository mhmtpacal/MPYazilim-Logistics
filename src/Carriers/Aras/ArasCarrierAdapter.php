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
    private const ORDER_WSDL_LIVE = 'https://customerws.araskargo.com.tr/arascargoservice.asmx?WSDL';
    private const ORDER_WSDL_TEST = 'https://customerservicestest.araskargo.com.tr/arascargoservice/arascargoservice.asmx?WSDL';
    private const QUERY_WSDL_LIVE = 'https://customerservices.araskargo.com.tr/ArasCargoCustomerIntegrationService/ArasCargoIntegrationService.svc?wsdl';
    private const QUERY_WSDL_TEST = 'https://customerservicestest.araskargo.com.tr/ArasCargoCustomerIntegrationService/ArasCargoIntegrationService.svc?wsdl';

    private ?SoapClient $orderClientLive = null;
    private ?SoapClient $orderClientTest = null;
    private ?SoapClient $queryClientLive = null;
    private ?SoapClient $queryClientTest = null;

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $account, array $payload, bool $testMode = false, bool $isReturn = false): array
    {
        $auth = $this->resolveAccount($account);
        $order = $this->normalizeOrderPayload($payload, $auth);
        $client = $this->orderClient($testMode);

        try {
            $response = $client->SetOrder([
                'orderInfo' => ['Order' => $order],
                'userName' => $auth['username'],
                'password' => $auth['password'],
            ]);
            $this->logSoapExchange($client, 'SetOrder', [
                'testMode' => $testMode,
                'integrationCode' => (string) ($order['IntegrationCode'] ?? ''),
            ]);

            if (!isset($response->SetOrderResult->OrderResultInfo)) {
                return [];
            }

            return (array) $response->SetOrderResult->OrderResultInfo;
        } catch (Throwable $e) {
            $this->logSoapExchange($client, 'SetOrder', [
                'testMode' => $testMode,
                'integrationCode' => (string) ($order['IntegrationCode'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Aras KargoyaGonder hatasi: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function kargoTakip(array $account, string $trackingNo, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $trackingNo = trim($trackingNo);
        $client = $this->queryClient($testMode);

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
            . '<CustomerCode>' . htmlspecialchars($auth['customer_code'], ENT_XML1) . '</CustomerCode>'
            . '<IntegrationCode>' . htmlspecialchars($trackingNo, ENT_XML1) . '</IntegrationCode>'
            . '</QueryInfo>';

        try {
            $response = $client->__soapCall('GetQueryXML', [
                new \SoapVar(
                    '<tem:GetQueryXML xmlns:tem="http://tempuri.org/">'
                    . '<tem:loginInfo><![CDATA[' . $loginInfo . ']]></tem:loginInfo>'
                    . '<tem:queryInfo><![CDATA[' . $queryInfo . ']]></tem:queryInfo>'
                    . '</tem:GetQueryXML>',
                    XSD_ANYXML
                ),
            ]);
            $this->logSoapExchange($client, 'GetQueryXML', [
                'testMode' => $testMode,
                'integrationCode' => $trackingNo,
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
            $this->logSoapExchange($client, 'GetQueryXML', [
                'testMode' => $testMode,
                'integrationCode' => $trackingNo,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Aras KargoTakip hatasi [' . $trackingNo . ']: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function barkodSil(array $account, string $integrationCode, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $integrationCode = trim($integrationCode);
        if ($integrationCode === '') {
            throw new InvalidArgumentException('integrationCode bos birakilamaz');
        }
        $client = $this->orderClient($testMode);

        try {
            $response = $client->CancelDispatch([
                'userName' => $auth['username'],
                'password' => $auth['password'],
                'integrationCode' => $integrationCode,
            ]);
            $this->logSoapExchange($client, 'CancelDispatch', [
                'testMode' => $testMode,
                'integrationCode' => $integrationCode,
            ]);

            return (array) $response;
        } catch (Throwable $e) {
            $this->logSoapExchange($client, 'CancelDispatch', [
                'testMode' => $testMode,
                'integrationCode' => $integrationCode,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Aras BarkodSil hatasi [' . $integrationCode . ']: ' . $e->getMessage(), 0, $e);
        }
    }

    private function orderClient(bool $testMode): SoapClient
    {
        if ($testMode) {
            if ($this->orderClientTest instanceof SoapClient) {
                return $this->orderClientTest;
            }

            $this->orderClientTest = $this->soap(self::ORDER_WSDL_TEST);
            return $this->orderClientTest;
        }

        if ($this->orderClientLive instanceof SoapClient) {
            return $this->orderClientLive;
        }

        $this->orderClientLive = $this->soap(self::ORDER_WSDL_LIVE);
        return $this->orderClientLive;
    }

    private function queryClient(bool $testMode): SoapClient
    {
        if ($testMode) {
            if ($this->queryClientTest instanceof SoapClient) {
                return $this->queryClientTest;
            }

            $this->queryClientTest = $this->soap(self::QUERY_WSDL_TEST);
            return $this->queryClientTest;
        }

        if ($this->queryClientLive instanceof SoapClient) {
            return $this->queryClientLive;
        }

        $this->queryClientLive = $this->soap(self::QUERY_WSDL_LIVE);
        return $this->queryClientLive;
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
            'trace' => 1,
            'exceptions' => true,
            'connection_timeout' => 3,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'stream_context' => $context,
            'keep_alive' => false,
        ]);
    }

    private function soapLogPath(): string
    {
        if (defined('WRITEPATH')) {
            return rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'aras-soap.log';
        }

        return getcwd() . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'aras-soap.log';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logSoapExchange(SoapClient $client, string $action, array $context = []): void
    {
        $requestXml = method_exists($client, '__getLastRequest') ? (string) $client->__getLastRequest() : '';
        $responseXml = method_exists($client, '__getLastResponse') ? (string) $client->__getLastResponse() : '';
        $requestHeaders = method_exists($client, '__getLastRequestHeaders') ? (string) $client->__getLastRequestHeaders() : '';
        $responseHeaders = method_exists($client, '__getLastResponseHeaders') ? (string) $client->__getLastResponseHeaders() : '';
        $path = $this->soapLogPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $entry = [
            '=== ' . date('Y-m-d H:i:s') . ' Aras SOAP ' . $action . ' ===',
            'Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'RequestHeaders:',
            $this->maskSoapSecrets($requestHeaders),
            'RequestXml:',
            $this->maskSoapSecrets($requestXml),
            'ResponseHeaders:',
            $responseHeaders,
            'ResponseXml:',
            $this->maskSoapSecrets($responseXml),
            '',
        ];

        @error_log(implode(PHP_EOL, $entry) . PHP_EOL, 3, $path);
    }

    private function maskSoapSecrets(string $xml): string
    {
        if ($xml === '') {
            return $xml;
        }

        $patterns = [
            '/(<Password>)(.*?)(<\/Password>)/is',
            '/(<password>)(.*?)(<\/password>)/is',
        ];

        return (string) preg_replace($patterns, '$1***$3', $xml);
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
            'PayorTypeCode' => 1,
            'IsWorldWide' => 0,
            'IsCod' => 0,
            'CodAmount' => 0,
            'CodCollectionType' => 0,
            'PieceDetails' => [],
        ];

        $normalized = array_merge($defaults, $payload);

        $normalized['PieceDetails'] = $this->normalizePieceDetails($normalized['PieceDetails'] ?? []);

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

        $this->assertStringLength((string) $normalized['TradingWaybillNumber'], 'TradingWaybillNumber', 1, 16);
        $this->assertStringLength((string) $normalized['IntegrationCode'], 'IntegrationCode', 2, 32);
        $this->assertStringLength((string) $normalized['ReceiverName'], 'ReceiverName', 1, 100);
        $this->assertStringLength((string) $normalized['ReceiverAddress'], 'ReceiverAddress', 1, 250);
        $this->assertStringLength((string) $normalized['ReceiverCityName'], 'ReceiverCityName', 1, 40);
        $this->assertStringLength((string) $normalized['ReceiverTownName'], 'ReceiverTownName', 1, 16);

        $this->assertPhoneNumber((string) $normalized['ReceiverPhone1'], 'ReceiverPhone1');
        $this->assertOptionalPhoneNumber($normalized['ReceiverPhone2'] ?? null, 'ReceiverPhone2');
        $this->assertOptionalPhoneNumber($normalized['ReceiverPhone3'] ?? null, 'ReceiverPhone3');

        $normalized['PayorTypeCode'] = $this->assertInInt($normalized['PayorTypeCode'] ?? null, 'PayorTypeCode', [1, 2]);
        $normalized['IsWorldWide'] = $this->assertInInt($normalized['IsWorldWide'] ?? null, 'IsWorldWide', [0, 1]);
        $normalized['IsCod'] = $this->assertInInt($normalized['IsCod'] ?? null, 'IsCod', [0, 1]);

        if (array_key_exists('PieceCount', $normalized) && $normalized['PieceCount'] !== '' && $normalized['PieceCount'] !== null) {
            $pieceCount = filter_var($normalized['PieceCount'], FILTER_VALIDATE_INT);
            if ($pieceCount === false || $pieceCount < 1 || $pieceCount > 99) {
                throw new InvalidArgumentException('payload.PieceCount 1-99 araliginda integer olmalidir');
            }
            $normalized['PieceCount'] = $pieceCount;
        }

        $pieceDetails = $normalized['PieceDetails']['PieceDetail'] ?? [];
        if (is_array($pieceDetails) && $pieceDetails !== []) {
            $pieceCountFromDetails = count($pieceDetails);

            if (!isset($normalized['PieceCount'])) {
                $normalized['PieceCount'] = $pieceCountFromDetails;
            } elseif ((int) $normalized['PieceCount'] !== $pieceCountFromDetails) {
                throw new InvalidArgumentException('payload.PieceCount ile PieceDetails adetleri eslesmelidir');
            }
        } else {
            unset($normalized['PieceDetails']);
        }

        if ((int) $normalized['IsCod'] === 1) {
            $normalized['CodCollectionType'] = $this->assertInInt($normalized['CodCollectionType'] ?? null, 'CodCollectionType', [0, 1]);
            if (!is_numeric($normalized['CodAmount'] ?? null)) {
                throw new InvalidArgumentException('payload.CodAmount sayisal olmalidir');
            }

            $codAmount = (float) $normalized['CodAmount'];
            if ($codAmount <= 5 || $codAmount > 5000) {
                throw new InvalidArgumentException('payload.CodAmount 5\'ten buyuk ve 5000\'den kucuk/esit olmalidir');
            }
            $normalized['CodAmount'] = $codAmount;

            if ((int) $normalized['PayorTypeCode'] === 2) {
                throw new InvalidArgumentException('Alici odemeli tahsilatli kargo desteklenmez (PayorTypeCode=2, IsCod=1)');
            }
        } else {
            $normalized['CodAmount'] = is_numeric($normalized['CodAmount'] ?? null) ? (float) $normalized['CodAmount'] : 0.0;
            $normalized['CodCollectionType'] = 0;
        }

        return $normalized;
    }

    /**
     * @param mixed $pieceDetails
     * @return array{PieceDetail:array<int,array<string,mixed>>}
     */
    private function normalizePieceDetails(mixed $pieceDetails): array
    {
        if (!is_array($pieceDetails) || $pieceDetails === []) {
            return ['PieceDetail' => []];
        }

        if (isset($pieceDetails['PieceDetail']) && is_array($pieceDetails['PieceDetail'])) {
            $details = $pieceDetails['PieceDetail'];
        } else {
            $details = $pieceDetails;
        }

        $isAssoc = array_keys($details) !== range(0, count($details) - 1);
        if ($isAssoc) {
            $details = [$details];
        }

        $normalized = [];
        foreach ($details as $index => $detail) {
            if (!is_array($detail)) {
                throw new InvalidArgumentException(sprintf('payload.PieceDetails[%d] gecerli bir nesne olmalidir', $index));
            }

            $barcode = trim((string) ($detail['BarcodeNumber'] ?? ''));
            if ($barcode === '') {
                throw new InvalidArgumentException(sprintf('payload.PieceDetails[%d].BarcodeNumber zorunludur', $index));
            }
            $this->assertStringLength($barcode, 'PieceDetails.BarcodeNumber', 1, 64);

            $item = ['BarcodeNumber' => $barcode];

            if (array_key_exists('ProductNumber', $detail) && $detail['ProductNumber'] !== null && $detail['ProductNumber'] !== '') {
                $productNumber = (string) $detail['ProductNumber'];
                $this->assertStringLength($productNumber, 'PieceDetails.ProductNumber', 1, 32);
                $item['ProductNumber'] = $productNumber;
            }

            if (array_key_exists('Description', $detail) && $detail['Description'] !== null && $detail['Description'] !== '') {
                $description = (string) $detail['Description'];
                $this->assertStringLength($description, 'PieceDetails.Description', 1, 64);
                $item['Description'] = $description;
            }

            if (array_key_exists('Weight', $detail) && $detail['Weight'] !== null && $detail['Weight'] !== '') {
                if (!is_numeric($detail['Weight'])) {
                    throw new InvalidArgumentException(sprintf('payload.PieceDetails[%d].Weight sayisal olmalidir', $index));
                }
                $item['Weight'] = (string) (float) $detail['Weight'];
            }

            if (array_key_exists('VolumetricWeight', $detail) && $detail['VolumetricWeight'] !== null && $detail['VolumetricWeight'] !== '') {
                if (!is_numeric($detail['VolumetricWeight'])) {
                    throw new InvalidArgumentException(sprintf('payload.PieceDetails[%d].VolumetricWeight sayisal olmalidir', $index));
                }
                $item['VolumetricWeight'] = (string) (float) $detail['VolumetricWeight'];
            }

            $normalized[] = $item;
        }

        return ['PieceDetail' => $normalized];
    }

    private function assertStringLength(string $value, string $field, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException(sprintf('payload.%s uzunlugu %d-%d araliginda olmalidir', $field, $min, $max));
        }
    }

    private function assertPhoneNumber(string $value, string $field): void
    {
        if (!preg_match('/^\d{10}$/', $value)) {
            throw new InvalidArgumentException(sprintf('payload.%s 10 haneli sayisal deger olmalidir', $field));
        }
    }

    private function assertOptionalPhoneNumber(mixed $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $phone = (string) $value;
        $this->assertPhoneNumber($phone, $field);
    }

    /**
     * @param array<int,int> $allowed
     */
    private function assertInInt(mixed $value, string $field, array $allowed): int
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false || !in_array($intVal, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('payload.%s sadece [%s] degerlerini alabilir', $field, implode(', ', $allowed)));
        }

        return $intVal;
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
