<?php

namespace App\Support;

use Illuminate\Support\Str;

class StaffInvitationToken
{
    public static function generate(): string
    {
        return Str::random(64);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function activationUrl(string $token): string
    {
        return rtrim((string) config('staff.frontend_url'), '/')
            .'/activate-account?'.http_build_query([
                'token' => $token,
            ], '', '&', PHP_QUERY_RFC3986);
    }
}
