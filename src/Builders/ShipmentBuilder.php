<?php

declare(strict_types=1);

namespace MPYazilim\Logistics\Builders;

use BadMethodCallException;
use MPYazilim\Logistics\Contracts\CarrierAdapterInterface;

final class ShipmentBuilder
{
    /** @var array<string,mixed> */
    private array $account = [];

    /** @var array<string,mixed> */
    private array $payload = [];

    private bool $testMode = false;

    public function __construct(
        private readonly CarrierAdapterInterface $adapter
    ) {
    }

    /**
     * @param array<string,mixed> $account
     */
    public function account(array $account): self
    {
        $this->account = $account;

        return $this;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function payload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function test(bool $enabled = true): self
    {
        $this->testMode = $enabled;

        return $this;
    }

    /**
     * Standart sevk.
     *
     * @return array<string,mixed>
     */
    public function send(): array
    {
        return $this->adapter->send($this->account, $this->payload, $this->testMode, false);
    }

    /**
     * Iade sevk.
     *
     * @return array<string,mixed>
     */
    public function iade(): array
    {
        return $this->adapter->send($this->account, $this->payload, $this->testMode, true);
    }

    /**
     * Kullanici tarafinda ->return() kullanimini destekler.
     *
     * @param array<int,mixed> $arguments
     * @return array<string,mixed>
     */
    public function __call(string $name, array $arguments): array
    {
        if ($name === 'return') {
            return $this->iade();
        }

        throw new BadMethodCallException(sprintf('Undefined method: %s::%s()', self::class, $name));
    }
}

