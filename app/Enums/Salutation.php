<?php

namespace App\Enums;

enum Salutation: string
{
    case MR = 'MR';
    case MRS = 'MRS';
    case MISS = 'MISS';

    public static function labels(): array
    {
        return [
            self::MR->value   => 'MR.',
            self::MRS->value  => 'MRS.',
            self::MISS->value => 'MISS',
        ];
    }
}
