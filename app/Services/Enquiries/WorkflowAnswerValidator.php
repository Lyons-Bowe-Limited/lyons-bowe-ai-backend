<?php

namespace App\Services\Enquiries;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WorkflowAnswerValidator
{
    /**
     * Validate and return a clean answer for a workflow step.
     *
     * @throws ValidationException
     */
    public function validate(
        array $step,
        mixed $answer
    ): mixed {
        $type = $step['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new InvalidArgumentException(
                'The workflow step does not contain a valid answer type.'
            );
        }

        return match ($type) {
            'boolean' => $this->validateBoolean($step, $answer),
            'single_choice' => $this->validateSingleChoice($step, $answer),
            'text' => $this->validateText($step, $answer),
            'textarea' => $this->validateText($step, $answer),
            'integer' => $this->validateInteger($step, $answer),
            'email' => $this->validateEmail($step, $answer),
            'object' => $this->validateObject($step, $answer),
            'repeatable_group' => $this->validateRepeatableGroup(
                $step,
                $answer
            ),
            default => throw new InvalidArgumentException(
                "Unsupported workflow answer type [{$type}]."
            ),
        };
    }

    /**
     * Validate a boolean answer.
     *
     * Supports true, false, 1, 0, "true", "false", "1" and "0".
     */
    private function validateBoolean(
        array $step,
        mixed $answer
    ): ?bool {
        $this->validateRequiredAnswer($step, $answer);

        if ($answer === null || $answer === '') {
            return null;
        }

        $normalised = filter_var(
            $answer,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($normalised === null) {
            throw ValidationException::withMessages([
                'answer' => [
                    'The answer must be either yes or no.',
                ],
            ]);
        }

        return $normalised;
    }

    /**
     * Validate a single-choice answer.
     */
    private function validateSingleChoice(
        array $step,
        mixed $answer
    ): mixed {
        $this->validateRequiredAnswer($step, $answer);

        if ($answer === null || $answer === '') {
            return null;
        }

        if (! is_string($answer) && ! is_int($answer)) {
            throw ValidationException::withMessages([
                'answer' => [
                    'The selected answer is invalid.',
                ],
            ]);
        }

        $allowedValues = collect($step['options'] ?? [])
            ->pluck('value')
            ->all();

        if (! in_array($answer, $allowedValues, true)) {
            throw ValidationException::withMessages([
                'answer' => [
                    'The selected answer is not one of the available options.',
                ],
            ]);
        }

        return $answer;
    }

    /**
     * Validate a text or textarea answer.
     */
    private function validateText(
        array $step,
        mixed $answer
    ): ?string {
        $this->validateRequiredAnswer($step, $answer);

        if ($answer === null || $answer === '') {
            return null;
        }

        if (! is_string($answer)) {
            throw ValidationException::withMessages([
                'answer' => [
                    'The answer must be text.',
                ],
            ]);
        }

        $answer = trim($answer);

        $rules = ['string'];

        if (isset($step['minimum_length'])) {
            $rules[] = 'min:'.(int) $step['minimum_length'];
        }

        if (isset($step['maximum_length'])) {
            $rules[] = 'max:'.(int) $step['maximum_length'];
        }

        $validator = Validator::make(
            ['answer' => $answer],
            ['answer' => $rules],
            [
                'answer.min' => 'The answer is too short.',
                'answer.max' => 'The answer is too long.',
            ]
        );

        $validator->validate();

        return $answer;
    }

    /**
     * Validate an integer answer.
     */
    private function validateInteger(
        array $step,
        mixed $answer
    ): ?int {
        $this->validateRequiredAnswer($step, $answer);

        if ($answer === null || $answer === '') {
            return null;
        }

        $rules = ['integer'];

        if (isset($step['minimum'])) {
            $rules[] = 'min:'.(int) $step['minimum'];
        }

        if (isset($step['maximum'])) {
            $rules[] = 'max:'.(int) $step['maximum'];
        }

        $validator = Validator::make(
            ['answer' => $answer],
            ['answer' => $rules],
            [
                'answer.integer' => 'The answer must be a whole number.',
                'answer.min' => 'The answer is below the minimum allowed value.',
                'answer.max' => 'The answer exceeds the maximum allowed value.',
            ]
        );

        $validator->validate();

        return (int) $answer;
    }

    /**
     * Validate an email answer.
     */
    private function validateEmail(
        array $step,
        mixed $answer
    ): ?string {
        $this->validateRequiredAnswer($step, $answer);

        if ($answer === null || $answer === '') {
            return null;
        }

        $validator = Validator::make(
            ['answer' => $answer],
            [
                'answer' => [
                    'string',
                    'email:rfc',
                    'max:'.(int) ($step['maximum_length'] ?? 255),
                ],
            ],
            [
                'answer.email' => 'Please enter a valid email address.',
            ]
        );

        $validator->validate();

        return mb_strtolower(trim((string) $answer));
    }

    /**
     * Validate an object containing configured fields.
     */
    private function validateObject(
        array $step,
        mixed $answer
    ): ?array {
        $this->validateRequiredAnswer($step, $answer);

        if ($answer === null || $answer === []) {
            return null;
        }

        if (! is_array($answer)) {
            throw ValidationException::withMessages([
                'answer' => [
                    'The answer must contain the requested details.',
                ],
            ]);
        }

        $fields = $step['fields'] ?? [];

        if (! is_array($fields) || $fields === []) {
            throw new InvalidArgumentException(
                'The object step does not contain any configured fields.'
            );
        }

        $validated = [];

        foreach ($fields as $fieldKey => $fieldDefinition) {
            $fieldValue = $answer[$fieldKey] ?? null;

            try {
                $validated[$fieldKey] = $this->validateField(
                    fieldDefinition: $fieldDefinition,
                    value: $fieldValue,
                );
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(
                    $this->prefixErrors(
                        errors: $exception->errors(),
                        prefix: "answer.{$fieldKey}"
                    )
                );
            }
        }

        return $validated;
    }

    /**
     * Validate a repeatable collection of configured fields.
     */
    private function validateRepeatableGroup(
        array $step,
        mixed $answer
    ): ?array {
        $this->validateRequiredAnswer($step, $answer);

        if ($answer === null || $answer === []) {
            return null;
        }

        if (! is_array($answer)) {
            throw ValidationException::withMessages([
                'answer' => [
                    'The answer must be a list.',
                ],
            ]);
        }

        if (! array_is_list($answer)) {
            throw ValidationException::withMessages([
                'answer' => [
                    'The answer must be a list of records.',
                ],
            ]);
        }

        $minimumItems = (int) ($step['minimum_items'] ?? 0);
        $maximumItems = $step['maximum_items'] ?? null;

        if (count($answer) < $minimumItems) {
            throw ValidationException::withMessages([
                'answer' => [
                    "Please provide at least {$minimumItems} record(s).",
                ],
            ]);
        }

        if (
            $maximumItems !== null
            && count($answer) > (int) $maximumItems
        ) {
            throw ValidationException::withMessages([
                'answer' => [
                    "Please provide no more than {$maximumItems} record(s).",
                ],
            ]);
        }

        $fields = $step['fields'] ?? [];

        if (! is_array($fields) || $fields === []) {
            throw new InvalidArgumentException(
                'The repeatable group does not contain any configured fields.'
            );
        }

        $validatedRows = [];

        foreach ($answer as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "answer.{$index}" => [
                        'Each record must contain the requested details.',
                    ],
                ]);
            }

            $validatedRow = [];

            foreach ($fields as $fieldKey => $fieldDefinition) {
                $fieldValue = $row[$fieldKey] ?? null;

                try {
                    $validatedRow[$fieldKey] = $this->validateField(
                        fieldDefinition: $fieldDefinition,
                        value: $fieldValue,
                    );
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(
                        $this->prefixErrors(
                            errors: $exception->errors(),
                            prefix: "answer.{$index}.{$fieldKey}"
                        )
                    );
                }
            }

            $validatedRows[] = $validatedRow;
        }

        return $validatedRows;
    }

    /**
     * Validate a nested object or repeatable-group field.
     */
    private function validateField(
        array $fieldDefinition,
        mixed $value
    ): mixed {
        $fieldStep = [
            ...$fieldDefinition,
            'question' => $fieldDefinition['label'] ?? 'Field',
        ];

        return $this->validate($fieldStep, $value);
    }

    /**
     * Validate required answers before type-specific validation.
     */
    private function validateRequiredAnswer(
        array $step,
        mixed $answer
    ): void {
        if (! ($step['required'] ?? false)) {
            return;
        }

        $isEmpty = $answer === null
            || $answer === ''
            || $answer === [];

        if ($isEmpty) {
            throw ValidationException::withMessages([
                'answer' => [
                    'An answer is required for this question.',
                ],
            ]);
        }
    }

    /**
     * Prefix nested validation error keys.
     */
    private function prefixErrors(
        array $errors,
        string $prefix
    ): array {
        $prefixed = [];

        foreach ($errors as $messages) {
            $prefixed[$prefix] = array_values($messages);
        }

        return $prefixed;
    }
}