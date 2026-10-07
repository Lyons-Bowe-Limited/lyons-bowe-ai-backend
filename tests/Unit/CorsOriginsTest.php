<?php

namespace Tests\Unit;

use App\Support\CorsOrigins;
use PHPUnit\Framework\TestCase;

class CorsOriginsTest extends TestCase
{
    public function test_it_includes_frontend_and_trimmed_additional_origins_without_empties_or_duplicates(): void
    {
        $origins = CorsOrigins::build(
            'https://staging.lyonsbowe.ai',
            ' https://staging-team.lyonsbowe.ai, ,https://team.lyonsbowe.ai,https://staging.lyonsbowe.ai,http://localhost:3000 ',
        );

        $this->assertSame([
            'http://localhost:3000',
            'https://staging.lyonsbowe.ai',
            'https://staging-team.lyonsbowe.ai',
            'https://team.lyonsbowe.ai',
        ], $origins);
    }

    public function test_empty_environment_values_still_allow_local_frontend_development(): void
    {
        $this->assertSame(
            ['http://localhost:3000'],
            CorsOrigins::build('', ''),
        );
    }
}
