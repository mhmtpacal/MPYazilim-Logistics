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
    private const MP_ORDER_WSDL_LIVE = 'https://integration.araskargo.com.tr/mporder/IntegrationService.svc?wsdl';
    private const MP_ORDER_WSDL_TEST = 'https://integrationtest.araskargo.com.tr/mpordertest/IntegrationService.svc?wsdl';
    private const QUERY_WSDL_LIVE = 'https://customerservices.araskargo.com.tr/ArasCargoCustomerIntegrationService/ArasCargoIntegrationService.svc?wsdl';
    private const QUERY_WSDL_TEST = 'https://customerservicestest.araskargo.com.tr/ArasCargoCustomerIntegrationService/ArasCargoIntegrationService.svc?wsdl';

    private ?SoapClient $orderClientLive = null;
    private ?SoapClient $orderClientTest = null;
    private ?SoapClient $mpOrderClientLive = null;
    private ?SoapClient $mpOrderClientTest = null;
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
        $order = $isReturn
            ? $this->normalizeMpOrderPayload($payload)
            : $this->normalizeOrderPayload($payload, $auth);
        $client = $isReturn
            ? $this->mpOrderClient($testMode)
            : $this->orderClient($testMode);
        $action = $isReturn ? 'ArasMPOrder' : 'SetOrder';

        try {
            $response = $isReturn
                ? $client->ArasMPOrder([
                    'model' => $order,
                    'customerInfo' => [
                        'CustomerCode' => $auth['customer_code'],
                        'Password' => $auth['password'],
                        'UserName' => $auth['username'],
                    ],
                ])
                : $client->SetOrder([
                    'orderInfo' => ['Order' => $order],
                    'userName' => $auth['username'],
                    'password' => $auth['password'],
                ]);
            $this->logSoapExchange($client, $action, [
                'testMode' => $testMode,
                'isReturn' => $isReturn,
                'integrationCode' => (string) ($order['IntegrationCode'] ?? ''),
            ]);

            if ($isReturn) {
                return $this->normalizeAnySoapResponse(
                    $response->ArasMPOrderResult ?? $response
                );
            }

            if (!isset($response->SetOrderResult->OrderResultInfo)) {
                return [];
            }

            return (array) $response->SetOrderResult->OrderResultInfo;
        } catch (Throwable $e) {
            $this->logSoapExchange($client, $action, [
                'testMode' => $testMode,
                'isReturn' => $isReturn,
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
                'KargoTakipNo' => (string) ($collection->KARGO_TAKIP_NO ?? '')
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

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public function getBarcode(array $account, string $integrationCode, bool $testMode = false): array
    {
        $auth = $this->resolveAccount($account);
        $integrationCode = trim($integrationCode);
        if ($integrationCode === '') {
            throw new InvalidArgumentException('integrationCode bos birakilamaz');
        }
        $client = $this->orderClient($testMode);

        try {
            $response = $client->GetBarcode([
                'Username' => $auth['username'],
                'Password' => $auth['password'],
                'integrationCode' => $integrationCode,
            ]);
            $this->logSoapExchange($client, 'GetBarcode', [
                'testMode' => $testMode,
                'integrationCode' => $integrationCode,
            ]);

            $result = $response->GetBarcodeResult ?? null;
            if (!$result) {
                return [];
            }

            return $this->normalizeBarcodeResponse($result);
        } catch (Throwable $e) {
            $this->logSoapExchange($client, 'GetBarcode', [
                'testMode' => $testMode,
                'integrationCode' => $integrationCode,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Aras GetBarcode hatasi [' . $integrationCode . ']: ' . $e->getMessage(), 0, $e);
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

    private function mpOrderClient(bool $testMode): SoapClient
    {
        if ($testMode) {
            if ($this->mpOrderClientTest instanceof SoapClient) {
                return $this->mpOrderClientTest;
            }

            $this->mpOrderClientTest = $this->soap(self::MP_ORDER_WSDL_TEST);
            return $this->mpOrderClientTest;
        }

        if ($this->mpOrderClientLive instanceof SoapClient) {
            return $this->mpOrderClientLive;
        }

        $this->mpOrderClientLive = $this->soap(self::MP_ORDER_WSDL_LIVE);
        return $this->mpOrderClientLive;
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

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeMpOrderPayload(array $payload): array
    {
        $defaults = [
            'CodeExpireDate' => '',
            'ConfigurationId' => '',
            'IntegrationCode' => '',
            'LovCollectionType' => 1,
            'LovPayOrType' => 1,
            'MainServiceCode' => '',
            'ExtServiceCodeList' => [],
            'PieceCount' => 1,
            'ReceiverAddressInfo' => [],
            'SenderAddressInfo' => [],
            'TradingWaybillNumber' => '',
            'Volume' => null,
            'Weight' => null,
        ];

        $normalized = array_merge($defaults, $payload);

        foreach ([
            'ConfigurationId',
            'IntegrationCode',
            'MainServiceCode',
        ] as $field) {
            $value = $normalized[$field] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                throw new InvalidArgumentException(sprintf('payload.%s zorunludur', $field));
            }
        }

        $this->assertStringLength((string) $normalized['ConfigurationId'], 'ConfigurationId', 1, 64);
        $this->assertStringLength((string) $normalized['IntegrationCode'], 'IntegrationCode', 1, 64);
        $this->assertStringLength((string) $normalized['MainServiceCode'], 'MainServiceCode', 1, 32);

        if (($normalized['CodeExpireDate'] ?? '') !== '') {
            $timestamp = strtotime((string) $normalized['CodeExpireDate']);
            if ($timestamp === false) {
                throw new InvalidArgumentException('payload.CodeExpireDate gecerli bir tarih olmalidir');
            }

            $normalized['CodeExpireDate'] = date('Y-m-d\TH:i:s', $timestamp);
        } else {
            unset($normalized['CodeExpireDate']);
        }

        $normalized['LovCollectionType'] = $this->assertPositiveInt($normalized['LovCollectionType'] ?? null, 'LovCollectionType');
        $normalized['LovPayOrType'] = $this->assertPositiveInt($normalized['LovPayOrType'] ?? null, 'LovPayOrType');
        $normalized['PieceCount'] = $this->assertPositiveInt($normalized['PieceCount'] ?? null, 'PieceCount');

        $normalized['ReceiverAddressInfo'] = $this->normalizeMpAddressInfo(
            $normalized['ReceiverAddressInfo'] ?? [],
            'ReceiverAddressInfo'
        );
        $normalized['SenderAddressInfo'] = $this->normalizeMpAddressInfo(
            $normalized['SenderAddressInfo'] ?? [],
            'SenderAddressInfo'
        );

        $normalized['ExtServiceCodeList'] = $this->normalizeMpServiceCodes(
            $normalized['ExtServiceCodeList'] ?? []
        );

        foreach (['Volume', 'Weight'] as $field) {
            if (!array_key_exists($field, $normalized) || $normalized[$field] === null || $normalized[$field] === '') {
                unset($normalized[$field]);
                continue;
            }

            if (!is_numeric($normalized[$field])) {
                throw new InvalidArgumentException(sprintf('payload.%s sayisal olmalidir', $field));
            }

            $normalized[$field] = (float) $normalized[$field];
        }

        if (($normalized['TradingWaybillNumber'] ?? '') === '') {
            unset($normalized['TradingWaybillNumber']);
        } else {
            $this->assertStringLength((string) $normalized['TradingWaybillNumber'], 'TradingWaybillNumber', 1, 64);
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function normalizeMpAddressInfo(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('payload.%s gecerli bir nesne olmalidir', $field));
        }

        $normalized = $value;

        foreach (['Address', 'CityName', 'Name', 'TownName'] as $requiredField) {
            $itemValue = $normalized[$requiredField] ?? null;
            if (!is_scalar($itemValue) || trim((string) $itemValue) === '') {
                throw new InvalidArgumentException(sprintf('payload.%s.%s zorunludur', $field, $requiredField));
            }
        }

        $this->assertStringLength((string) $normalized['Address'], $field . '.Address', 1, 250);
        $this->assertStringLength((string) $normalized['CityName'], $field . '.CityName', 1, 40);
        $this->assertStringLength((string) $normalized['TownName'], $field . '.TownName', 1, 40);
        $this->assertStringLength((string) $normalized['Name'], $field . '.Name', 1, 100);

        foreach (['PhoneNumber', 'MobilePhone'] as $phoneField) {
            if (!array_key_exists($phoneField, $normalized) || $normalized[$phoneField] === null || $normalized[$phoneField] === '') {
                continue;
            }

            $digits = preg_replace('/\D+/', '', (string) $normalized[$phoneField]);
            if (!is_string($digits) || $digits === '' || strlen($digits) < 10 || strlen($digits) > 11) {
                throw new InvalidArgumentException(sprintf('payload.%s.%s 10-11 haneli sayisal deger olmalidir', $field, $phoneField));
            }

            $normalized[$phoneField] = $digits;
        }

        foreach (['AddressId', 'TaxNumber'] as $optionalField) {
            if (array_key_exists($optionalField, $normalized) && $normalized[$optionalField] !== null) {
                $normalized[$optionalField] = (string) $normalized[$optionalField];
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<string,array<int,string>>
     */
    private function normalizeMpServiceCodes(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($items as $index => $item) {
            if (!is_scalar($item) || trim((string) $item) === '') {
                throw new InvalidArgumentException(sprintf('payload.ExtServiceCodeList[%d] bos olamaz', $index));
            }

            $normalized[] = trim((string) $item);
        }

        return ['string' => $normalized];
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

    private function assertPositiveInt(mixed $value, string $field): int
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false || $intVal < 1) {
            throw new InvalidArgumentException(sprintf('payload.%s pozitif integer olmalidir', $field));
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

    /**
     * @return array<string,mixed>
     */
    private function normalizeBarcodeResponse(object $result): array
    {
        return [
            'Images' => $this->normalizeSoapScalarList($result->Images ?? null),
            'ZebraZpl' => $this->normalizeSoapScalarList($result->ZebraZpl ?? null),
            'ZebraEpl' => $this->normalizeSoapScalarList($result->ZebraEpl ?? null),
            'ZebraPdf' => $this->normalizeSoapScalarList($result->ZebraPdf ?? null),
            'BarcodeModelLst' => $this->normalizeSoapObjectList($result->BarcodeModelLst ?? null, 'BarcodeModel'),
            'Message' => (string) ($result->Message ?? ''),
            'ResultCode' => (int) ($result->ResultCode ?? 0),
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function normalizeSoapScalarList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = $this->extractSoapScalarValues($value);
        if ($items !== []) {
            return $items;
        }

        if (is_scalar($value)) {
            return [(string) $value];
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    private function extractSoapScalarValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);
            return $string === '' ? [] : [$string];
        }

        if (is_array($value)) {
            $results = [];
            foreach ($value as $item) {
                $results = [...$results, ...$this->extractSoapScalarValues($item)];
            }

            return $results;
        }

        if (is_object($value)) {
            $results = [];
            foreach (get_object_vars($value) as $item) {
                $results = [...$results, ...$this->extractSoapScalarValues($item)];
            }

            return $results;
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeSoapObjectList(mixed $value, string $property): array
    {
        if (!is_object($value) || !isset($value->{$property})) {
            return [];
        }

        $items = $value->{$property};
        if (!is_array($items)) {
            $items = [$items];
        }

        return array_map(
            static fn (mixed $item): array => is_object($item) ? get_object_vars($item) : (array) $item,
            $items
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeAnySoapResponse(mixed $value): array
    {
        if (is_object($value)) {
            return $this->normalizeAnySoapArray(get_object_vars($value));
        }

        if (is_array($value)) {
            return $this->normalizeAnySoapArray($value);
        }

        if ($value === null) {
            return [];
        }

        return ['value' => $value];
    }

    /**
     * @param array<string|int,mixed> $value
     * @return array<string,mixed>
     */
    private function normalizeAnySoapArray(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = match (true) {
                is_object($item) => $this->normalizeAnySoapResponse($item),
                is_array($item) => array_is_list($item)
                    ? array_map(
                        fn (mixed $listItem): mixed => is_object($listItem) || is_array($listItem)
                            ? $this->normalizeAnySoapResponse($listItem)
                            : $listItem,
                        $item
                    )
                    : $this->normalizeAnySoapArray($item),
                default => $item,
            };
        }

        return $normalized;
    }
}
