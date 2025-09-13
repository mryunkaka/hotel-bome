<?php

namespace App\Enums;

enum ReservationMethod: string
{
    case PHONE   = 'PHONE';
    case WA_SMS  = 'WA/SMS';
    case EMAIL   = 'EMAIL';
    case LETTER  = 'LETTER';
    case PERSONAL = 'PERSONAL';
    case OTA     = 'OTA';
    case OTHER   = 'OTHER';

    public static function labels(): array
    {
        return [
            self::PHONE->value    => 'Phone',
            self::WA_SMS->value   => 'WA/SMS',
            self::EMAIL->value    => 'Email',
            self::LETTER->value   => 'Letter',
            self::PERSONAL->value => 'Personal',
            self::OTA->value      => 'OTA',
            self::OTHER->value    => 'Other',
        ];
    }
}
