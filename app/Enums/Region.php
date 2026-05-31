<?php

namespace App\Enums;

enum Region: string
{
    case US = 'US';
    case UK = 'UK';

    public function baseDomain(): string
    {
        return match ($this) {
            self::US => 'api.audible.com',
            self::UK => 'api.audible.co.uk',
        };
    }
}
