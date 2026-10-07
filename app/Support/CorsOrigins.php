<?php

namespace App\Support;

class CorsOrigins
{
    /** @return list<string> */
    public static function build(?string $frontendUrl, ?string $additionalOrigins): array
    {
        $origins = [
            'http://localhost:3000',
            $frontendUrl,
            ...explode(',', (string) $additionalOrigins),
        ];

        return array_values(array_unique(array_filter(
            array_map(fn ($origin) => trim((string) $origin), $origins),
            fn (string $origin) => $origin !== '',
        )));
    }
}
