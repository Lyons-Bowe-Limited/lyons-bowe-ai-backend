<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum PracticeArea: string
{
    case Property = 'property_law';
    case Family = 'family_law';
    case Wills = 'wills_and_probate';
    case Unclassified = 'unclassified';

    public static function normalise(?string $value): self
    {
        return self::tryFrom(self::key($value)) ?? self::Unclassified;
    }

    public static function key(?string $value): string
    {
        return Str::of($value ?? '')->lower()->replace([' ', '-'], '_')->toString();
    }
}
