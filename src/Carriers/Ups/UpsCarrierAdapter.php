<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Carriers\Ups;

use InvalidArgumentException;
use MPYazilim\Logistics\Contracts\CarrierAdapterInterface;
use RuntimeException;
use SoapClient;
use Throwable;

final class UpsCarrierAdapter implements CarrierAdapterInterface
{
    private const QUERY_WSDL = 'https://ws.ups.com.tr/QueryPackageInfo/wsQueryPackagesInfo.asmx?WSDL';
    private const SHIPMENT_WSDL = 'https://ws.ups.com.tr/wsCreateShipment/wsCreateShipment.asmx?WSDL';

    private ?SoapClient $queryClient = null;
    private ?SoapClient $shipmentClient = null;
    private ?string $querySessionId = null;
    private ?string $shipmentSessionId = null;

    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $account, array $payload, bool $testMode = false, bool $isReturn = false): array
    {
        if ($isReturn) {
            throw new RuntimeException('UPS iade desteklemiyor');
        }

        $auth = $this->resolveAccount($account);
        $shipment = $this->normalizeShipmentPayload($payload);

        try {
            $response = $this->shipmentClient()->CreateShipment_Type2([
                'SessionID' => $this->sessionForShipment($auth),
                'ShipmentInfo' => $shipment,
                'ReturnLabelLink' => true,
                'ReturnLabelImage' => true,
            ]);

            return $this->responseToArray($response);
        } catch (Throwable $e) {
            throw new RuntimeException('UPS KargoyaGonder hatasi: ' . $e->getMessage(), 0, $e);
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
            throw new InvalidArgumentException('trackingNo bos birakilamaz');
        }

        try {
            $response = $this->queryClient()->GetTransactionsByTrackingNumber_V1([
                'SessionID' => $this->sessionForQuery($auth),
                'InformationLevel' => 1,
                'TrackingNumber' => $trackingNo,
            ]);

            return $this->responseToArray($response);
        } catch (Throwable $e) {
            throw new RuntimeException('UPS KargoTakip hatasi [' . $trackingNo . ']: ' . $e->getMessage(), 0, $e);
        }
    }

    private function queryClient(): SoapClient
    {
        if ($this->queryClient instanceof SoapClient) {
            return $this->queryClient;
        }

        $this->queryClient = $this->soap(self::QUERY_WSDL);

        return $this->queryClient;
    }

    private function shipmentClient(): SoapClient
    {
        if ($this->shipmentClient instanceof SoapClient) {
            return $this->shipmentClient;
        }

        $this->shipmentClient = $this->soap(self::SHIPMENT_WSDL);

        return $this->shipmentClient;
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
     * @param array{customer_number:string,username:string,password:string} $auth
     */
    private function sessionForQuery(array $auth): string
    {
        if (is_string($this->querySessionId) && $this->querySessionId !== '') {
            return $this->querySessionId;
        }

        try {
            $response = $this->queryClient()->Login_V1([
                'CustomerNumber' => $auth['customer_number'],
                'UserName' => $auth['username'],
                'Password' => $auth['password'],
            ]);

            $sessionId = isset($response->Login_V1Result->SessionID) ? (string) $response->Login_V1Result->SessionID : '';
            if ($sessionId === '') {
                throw new RuntimeException('UPS SessionID bos dondu (query)');
            }

            $this->querySessionId = $sessionId;

            return $this->querySessionId;
        } catch (Throwable $e) {
            throw new RuntimeException('UPS Login hatasi (query): ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{customer_number:string,username:string,password:string} $auth
     */
    private function sessionForShipment(array $auth): string
    {
        if (is_string($this->shipmentSessionId) && $this->shipmentSessionId !== '') {
            return $this->shipmentSessionId;
        }

        try {
            $response = $this->shipmentClient()->Login_Type1([
                'CustomerNumber' => $auth['customer_number'],
                'UserName' => $auth['username'],
                'Password' => $auth['password'],
            ]);

            $sessionId = isset($response->Login_Type1Result->SessionID) ? (string) $response->Login_Type1Result->SessionID : '';
            if ($sessionId === '') {
                throw new RuntimeException('UPS SessionID bos dondu (shipment)');
            }

            $this->shipmentSessionId = $sessionId;

            return $this->shipmentSessionId;
        } catch (Throwable $e) {
            throw new RuntimeException('UPS Login hatasi (shipment): ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string,mixed> $account
     * @return array{customer_number:string,username:string,password:string}
     */
    private function resolveAccount(array $account): array
    {
        $customerNumber = $account['customer_number'] ?? null;
        $username = $account['username'] ?? null;
        $password = $account['password'] ?? null;

        if (!is_string($customerNumber) || $customerNumber === '') {
            throw new InvalidArgumentException('account.customer_number zorunludur');
        }
        if (!is_string($username) || $username === '') {
            throw new InvalidArgumentException('account.username zorunludur');
        }
        if (!is_string($password) || $password === '') {
            throw new InvalidArgumentException('account.password zorunludur');
        }

        return [
            'customer_number' => $customerNumber,
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeShipmentPayload(array $payload): array
    {
        $required = [
            'ShipperAccountNumber',
            'ShipperName',
            'ShipperAddress',
            'ShipperCityCode',
            'ShipperAreaCode',
            'ConsigneeName',
            'ConsigneeContactName',
            'ConsigneeAddress',
            'ConsigneeCityCode',
            'ConsigneeAreaCode',
            'ConsigneePhoneNumber',
            'ConsigneeMobilePhoneNumber',
            'PackageType',
            'ServiceLevel',
            'PaymentType',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException(sprintf('payload.%s zorunludur', $field));
            }
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function responseToArray(object $response): array
    {
        return json_decode(json_encode($response, JSON_UNESCAPED_UNICODE), true) ?: [];
    }
}
