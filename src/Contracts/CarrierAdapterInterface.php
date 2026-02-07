<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Contracts;

interface CarrierAdapterInterface
{
    /**
     * @param array<string,mixed> $account
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $account, array $payload, bool $testMode = false, bool $isReturn = false): array;
}

