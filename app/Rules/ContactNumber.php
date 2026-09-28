<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ContactNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value) || ! is_string($value)) {
            return;
        }

        $trimmed = trim($value);

        if (! preg_match('/^\+?(?:\d|\(\d)[0-9\s().-]*\d$/', $trimmed)) {
            $fail('The :attribute must be a valid contact number.');

            return;
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            $fail('The :attribute must be a valid contact number.');
        }
    }
}
