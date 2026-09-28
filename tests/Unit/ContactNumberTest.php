<?php

namespace Tests\Unit;

use App\Rules\ContactNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContactNumberTest extends TestCase
{
    #[DataProvider('validContactNumbers')]
    public function test_it_accepts_valid_contact_numbers(string $number): void
    {
        $this->assertValidationPasses($number);
    }

    #[DataProvider('invalidContactNumbers')]
    public function test_it_rejects_invalid_contact_numbers(string $number): void
    {
        $this->assertValidationFails($number);
    }

    public static function validContactNumbers(): array
    {
        return [
            ['07123 456789'],
            ['+44 7123 456789'],
            ['+1 (555) 123-4567'],
            ['(555) 123-4567'],
            ['+27 82 123 4567'],
            ['00353 86 123 4567'],
        ];
    }

    public static function invalidContactNumbers(): array
    {
        return [
            ['call me'],
            ['123456'],
            ['+1234567890123456'],
            ['+44 7123 ABCDEF'],
            ['++44 7123 456789'],
            ['--07123 456789'],
        ];
    }

    private function assertValidationPasses(string $number): void
    {
        $failed = false;

        (new ContactNumber)->validate('contact_number', $number, function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    private function assertValidationFails(string $number): void
    {
        $failed = false;

        (new ContactNumber)->validate('contact_number', $number, function () use (&$failed): void {
            $failed = true;
        });

        $this->assertTrue($failed);
    }
}
