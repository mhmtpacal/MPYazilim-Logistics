<?php

declare(strict_types=1);

namespace MPYazilim\Logistics;

use MPYazilim\Logistics\Builders\PttShipmentBuilder;
use MPYazilim\Logistics\Carriers\Ptt\PttCarrierAdapter;

final class MPLogistics
{
    public static function ptt(): PttShipmentBuilder
    {
        return new PttShipmentBuilder(new PttCarrierAdapter());
    }
}
