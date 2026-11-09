<?php

namespace App\Services\Enquiries;

use Carbon\CarbonImmutable;

class UrgencyDetectionService
{
    /** Retain only typed signals: no summary, excerpts or arbitrary AI metadata. */
    public function normalise(mixed $urgency): array
    {
        $urgency = is_array($urgency) ? $urgency : [];
        $signals = [];
        foreach (is_array($urgency['signals'] ?? null) ? $urgency['signals'] : [] as $signal) {
            if (! is_array($signal) || ! in_array($signal['type'] ?? null, config('enquiry-priority.signal_types'), true)
                || ! is_numeric($signal['confidence'] ?? null) || $signal['confidence'] < 0 || $signal['confidence'] > 1) {
                continue;
            }
            $signals[] = [
                'type' => $signal['type'], 'confidence' => (float) $signal['confidence'],
                'immediate' => is_bool($signal['immediate'] ?? null) ? $signal['immediate'] : null,
                'deadline_at' => $this->deadline($signal['deadline_at'] ?? null)?->toIso8601String(),
            ];
        }

        return ['signals' => array_slice($signals, 0, 20)];
    }

    /** High precision fallback for a client's direct instruction, not emotional language. */
    public function explicitRequest(string $message): ?array
    {
        $message = mb_strtolower(trim($message));
        if (preg_match('/\b(?:not urgent|no longer urgent|isn.t urgent|is not urgent|if this is urgent)\b/u', $message)) {
            return null;
        }
        if (preg_match('/(?:^|[.!?;\n]\s*)(?:(?:please[, ]+)?(?:this|it|my (?:matter|case|situation)|the (?:matter|case)) is (?:very |really )?urgent|i need urgent (?:legal )?help)\b/u', $message)) {
            return ['type' => 'explicit_urgent', 'confidence' => 1.0, 'immediate' => true, 'deadline_at' => null];
        }

        return null;
    }

    public function deadline(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d))?$/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value, config('enquiry-priority.timezone'));

            return strlen($value) === 10 ? $date->endOfDay() : $date;
        } catch (\Throwable) {
            return null;
        }
    }
}
