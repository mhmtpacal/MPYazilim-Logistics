<?php

declare(strict_types=1);

namespace MPYazilim\Logistics;

use MPYazilim\Logistics\Builders\DhlShipmentBuilder;
use MPYazilim\Logistics\Builders\PttShipmentBuilder;
use MPYazilim\Logistics\Carriers\Dhl\DhlCarrierAdapter;
use MPYazilim\Logistics\Carriers\Ptt\PttCarrierAdapter;

final class MPLogistics
{
    public static function ptt(): PttShipmentBuilder
    {
        return new PttShipmentBuilder(new PttCarrierAdapter());
    }

    public static function dhl(): DhlShipmentBuilder
    {
        return new DhlShipmentBuilder(new DhlCarrierAdapter());
    }
}
