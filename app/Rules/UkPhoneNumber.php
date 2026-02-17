<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UkPhoneNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Handle null or empty values (should be caught by 'required' rule, but just in case)
        if (empty($value) || !is_string($value)) {
            return; // Let the 'required' rule handle this
        }
        
        // Remove spaces, dashes, and parentheses for validation
        $cleaned = preg_replace('/[\s\-\(\)]/', '', $value);
        
        // Only validate UK format if number starts with 0 or 44 (or +44)
        if (!preg_match('/^(0|44|\+44)/', $cleaned)) {
            // Not a UK number, skip UK-specific validation
            // The basic 'string|max:20' rules will still apply
            return;
        }
        
        // Validate UK phone number format
        // Domestic format: 0XXXXXXXXXX (11 digits)
        // International format: 44XXXXXXXXXX or +44XXXXXXXXXX (12-13 characters)
        if (preg_match('/^0\d{10}$/', $cleaned)) {
            // Valid domestic format (0 + 10 digits)
            return;
        }
        
        if (preg_match('/^(\+?44)\d{10}$/', $cleaned)) {
            // Valid international format (44 or +44 + 10 digits)
            return;
        }
        
        // If it starts with 0 or 44 but doesn't match valid formats, fail validation
        $fail('The :attribute must be a valid UK phone number format.');
    }
}
