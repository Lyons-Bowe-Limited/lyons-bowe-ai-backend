<?php

namespace App\Services\Enquiries;

use Illuminate\Support\Str;

class WorkflowAnswerInterpreter
{
    public function interpret(
        array $step,
        string $message
    ): array {
        return match ($step['type'] ?? null) {
            'boolean' => $this->interpretBoolean(
                $step,
                $message
            ),

            'single_choice' => $this->interpretSingleChoice(
                $step,
                $message
            ),

            'integer' => $this->interpretInteger($message),

            'text',
            'textarea',
            'email' => $this->successful(
                trim($message)
            ),

            'repeatable_group' =>
                $this->interpretRepeatableGroup(
                    $step,
                    $message
                ),

            'object' => $this->interpretObject(
                $step,
                $message
            ),

            default => $this->failed(
                'I did not quite understand that answer. '
                .'Could you please rephrase it?'
            ),
        };
    }

    private function interpretBoolean(
        array $step,
        string $message
    ): array {
        $normalised = $this->normalise($message);
        $stepKey = $step['key'] ?? '';

        /*
         * Check clear negative language before positive language.
         */
        $negativePatterns = [
            '/\bno\b/',
            '/\bnope\b/',
            '/\bnot currently\b/',
            '/\bi do not\b/',
            '/\bi dont\b/',
            '/\bi don\'t\b/',
            '/\bi have not\b/',
            '/\bi haven\'t\b/',
            '/\bwe do not\b/',
            '/\bwe dont\b/',
            '/\bwe don\'t\b/',
            '/\bjust me\b/',
            '/\bonly me\b/',
            '/\bnone\b/',
        ];

        if ($this->matchesAny(
            $normalised,
            $negativePatterns
        )) {
            return $this->successful(false);
        }

        $positivePatterns = [
            '/\byes\b/',
            '/\byeah\b/',
            '/\byep\b/',
            '/\bcorrect\b/',
            '/\bi do\b/',
            '/\bi have\b/',
            '/\bwe do\b/',
            '/\bwe have\b/',
            '/\bboth of us\b/',
            '/\bmy partner and i\b/',
            '/\bmy spouse and i\b/',
        ];

        if ($this->matchesAny(
            $normalised,
            $positivePatterns
        )) {
            return $this->successful(true);
        }

        /*
         * Step-specific natural-language interpretation.
         */
        if (
            $stepKey === 'joint_will'
            && (
                str_contains($normalised, 'for both of us')
                || str_contains($normalised, 'for the two of us')
                || str_contains($normalised, 'one for each of us')
                || str_contains($normalised, 'mirror wills')
            )
        ) {
            return $this->successful(true);
        }

        if (
            $stepKey === 'children'
            && preg_match(
                '/\b(?:have|got)\s+\d+\s+children?\b/',
                $normalised
            )
        ) {
            return $this->successful(true);
        }

        if (
            $stepKey === 'property_owner'
            && (
                preg_match(
                    '/\bi own\s+(?:a|an|one|\d+)\s+propert/',
                    $normalised
                )
                || str_contains(
                    $normalised,
                    'i have a property'
                )
            )
        ) {
            return $this->successful(true);
        }

        if (
            $stepKey === 'existing_will'
            && (
                str_contains(
                    $normalised,
                    'i already have a will'
                )
                || str_contains(
                    $normalised,
                    'i currently have a will'
                )
            )
        ) {
            return $this->successful(true);
        }

        return $this->failed(
            'Just so I record that correctly, '
            .'would that be a yes or a no?'
        );
    }

    private function interpretSingleChoice(
        array $step,
        string $message
    ): array {
        $normalised = $this->normalise($message);
        $stepKey = (string) ($step['key'] ?? '');

        /*
        * Common natural-language aliases.
        */
        $aliases = [
            'relationship_status' => [
                'widow' => 'widowed',
                'widower' => 'widowed',
                'i am a widow' => 'widowed',
                'i am a widower' => 'widowed',
                'living together' => 'cohabiting',
                'living with my partner' => 'cohabiting',
                'not married but together' => 'cohabiting',
            ],

            'family_law_support' => [
                'help with divorce' => 'divorce',
                'divorce support' => 'divorce',
                'help with my children' => 'child_arrangements',
                'child arrangement' => 'child_arrangements',
                'child arrangements' => 'child_arrangements',
                'family arrangement' => 'family_arrangements',
                'family arrangements' => 'family_arrangements',
                'selling my house' => 'selling_property',
                'selling my property' => 'selling_property',
                'none' => 'no',
                'nothing else' => 'no',
            ],

            'probate_support' => [
                'probate help' => 'probate',
                'help with probate' => 'probate',
                'lasting power of attorney' => 'lpa',
                'power of attorney' => 'lpa',
                'something different' => 'something_else',
                'none' => 'no',
                'nothing else' => 'no',
            ],

            'declaration_of_trust_support' => [
                'not relevant' => 'not_applicable',
                'does not apply' => 'not_applicable',
                'n/a' => 'not_applicable',
                'na' => 'not_applicable',
            ],

            'will_delivery_method' => [
                'online will' => 'online',
                'do it online' => 'online',
                'online please' => 'online',
                'video call' => 'virtual',
                'teams meeting' => 'virtual',
                'virtual meeting' => 'virtual',
                'virtual appointment' => 'virtual',
                'in person' => 'face_to_face',
                'at an office' => 'face_to_face',
                'office appointment' => 'face_to_face',
                'face to face' => 'face_to_face',
            ],

            'cruk_confirmation' => [
                'online will' => 'online',
                'do it online' => 'online',
                'online please' => 'online',
                'video call' => 'virtual',
                'teams meeting' => 'virtual',
                'virtual meeting' => 'virtual',
                'virtual appointment' => 'virtual',
                'in person' => 'face_to_face',
                'at an office' => 'face_to_face',
                'office appointment' => 'face_to_face',
                'face to face' => 'face_to_face',
            ],
        ];

        foreach ($aliases[$stepKey] ?? [] as $phrase => $value) {
            if (
                $normalised === $phrase
                || str_contains($normalised, $phrase)
            ) {
                return $this->successful($value);
            }
        }

        foreach ($step['options'] ?? [] as $option) {
            $value = (string) ($option['value'] ?? '');
            $label = (string) ($option['label'] ?? '');

            $normalisedValue = $this->normalise(
                str_replace('_', ' ', $value)
            );

            $normalisedLabel = $this->normalise($label);

            if (
                $normalised === $normalisedValue
                || $normalised === $normalisedLabel
                || (
                    $normalisedValue !== ''
                    && str_contains(
                        $normalised,
                        $normalisedValue
                    )
                )
                || (
                    $normalisedLabel !== ''
                    && str_contains(
                        $normalised,
                        $normalisedLabel
                    )
                )
            ) {
                return $this->successful($value);
            }
        }

        $availableOptions = collect(
            $step['options'] ?? []
        )
            ->pluck('label')
            ->filter()
            ->implode(', ');

        return $this->failed(
            $availableOptions !== ''
                ? "I just need to record one of these options: {$availableOptions}."
                : 'Please choose one of the available options.'
        );
    }

    private function interpretInteger(
        string $message
    ): array {
        if (preg_match('/\b(\d+)\b/', $message, $matches)) {
            return $this->successful(
                (int) $matches[1]
            );
        }

        return $this->failed(
            'Could you provide that as a whole number?'
        );
    }

    private function interpretRepeatableGroup(
        array $step,
        string $message
    ): array {
        $fields = $step['fields'] ?? [];

        if (
            isset($fields['name'])
            && isset($fields['age'])
        ) {
            $records = $this->extractChildren($message);

            if ($records !== []) {
                return $this->successful($records);
            }

            return $this->failed(
                'I need each child’s name and age. '
                .'You can write it naturally, for example: '
                .'“Holly is 8 years old and Colt is 4 years old.”'
            );
        }

        return $this->failed(
            'Could you provide those details as a clear list?'
        );
    }

    private function extractChildren(
        string $message
    ): array {
        $records = [];

        /*
         * Supports:
         * Holly is 8 years old
         * Holly is 8
         * Holly, 8
         * Holly aged 8
         */
        preg_match_all(
            '/
                \b
                ([a-z][a-z\'-]*(?:\s+[a-z][a-z\'-]*){0,2})
                \s*
                (?:
                    ,\s*
                    |
                    is\s+
                    |
                    aged?\s+
                    |
                    age\s+
                )
                (\d{1,3})
                (?:\s+years?\s+old)?
                \b
            /ix',
            $message,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = trim(
                preg_replace(
                    '/^(and|my child is|my children are)\s+/i',
                    '',
                    $match[1]
                )
            );

            $age = (int) $match[2];

            if (
                $name !== ''
                && $age >= 0
                && $age <= 120
            ) {
                $records[] = [
                    'name' => Str::title($name),
                    'age' => $age,
                ];
            }
        }

        return $records;
    }

    private function interpretObject(
        array $step,
        string $message
    ): array {
        return $this->failed(
            'Could you provide all of the requested details?'
        );
    }

    private function matchesAny(
        string $value,
        array $patterns
    ): bool {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function successful(
        mixed $answer
    ): array {
        return [
            'success' => true,
            'answer' => $answer,
            'clarification' => null,
        ];
    }

    private function failed(
        string $clarification
    ): array {
        return [
            'success' => false,
            'answer' => null,
            'clarification' => $clarification,
        ];
    }

    private function normalise(
        string $value
    ): string {
        return Str::of($value)
            ->lower()
            ->replaceMatches(
                '/[^\pL\pN\s@.+\'-]/u',
                ' '
            )
            ->squish()
            ->toString();
    }
}