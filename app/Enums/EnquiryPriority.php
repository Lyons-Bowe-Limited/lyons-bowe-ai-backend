<?php

namespace App\Enums;

enum EnquiryPriority: string
{
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function rank(): int
    {
        return match ($this) {
            self::Normal => 0, self::High => 1, self::Urgent => 2
        };
    }
}
