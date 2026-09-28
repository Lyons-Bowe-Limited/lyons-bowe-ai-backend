<?php

namespace App\Services\Enquiries;

use InvalidArgumentException;

class WorkflowBranchResolver
{
    public function __construct(
        private readonly WorkflowDefinitionService $definitions,
    ) {
    }

    /**
     * Resolve the next step key from the current step and answer.
     */
    public function resolve(
        string $workflowKey,
        string $currentStepKey,
        mixed $answer
    ): ?string {
        $step = $this->definitions->getStep(
            workflowKey: $workflowKey,
            stepKey: $currentStepKey,
        );

        $next = $step['next'] ?? null;

        if ($next === null) {
            return null;
        }

        if (is_string($next)) {
            return $next;
        }

        if (! is_array($next)) {
            throw new InvalidArgumentException(
                "The next-step definition for [{$currentStepKey}] "
                . 'must be a step key, branching configuration or null.'
            );
        }

        return $this->resolveConditionalBranch(
            currentStepKey: $currentStepKey,
            branching: $next,
            answer: $answer,
        );
    }

    /**
     * Resolve a conditional branch.
     */
    private function resolveConditionalBranch(
        string $currentStepKey,
        array $branching,
        mixed $answer
    ): ?string {
        $rules = $branching['rules'] ?? [];
        $default = $branching['default'] ?? null;

        if (! is_array($rules)) {
            throw new InvalidArgumentException(
                "Branching rules for step [{$currentStepKey}] "
                . 'must be an array.'
            );
        }

        foreach ($rules as $rule) {
            if ($this->matchesRule($answer, $rule)) {
                return $rule['step'];
            }
        }

        return $default;
    }

    /**
     * Determine whether an answer matches a branching rule.
     */
    private function matchesRule(
        mixed $answer,
        array $rule
    ): bool {
        $operator = $rule['operator'] ?? null;
        $expectedValue = $rule['value'] ?? null;

        return match ($operator) {
            'equals' => $this->equals(
                actual: $answer,
                expected: $expectedValue,
            ),

            'not_equals' => ! $this->equals(
                actual: $answer,
                expected: $expectedValue,
            ),

            'in' => $this->isIn(
                actual: $answer,
                expectedValues: $expectedValue,
            ),

            'not_in' => ! $this->isIn(
                actual: $answer,
                expectedValues: $expectedValue,
            ),

            'contains' => $this->contains(
                actual: $answer,
                expected: $expectedValue,
            ),

            'greater_than' => $this->compareNumbers(
                actual: $answer,
                expected: $expectedValue,
                comparison: 'greater_than',
            ),

            'greater_than_or_equal' => $this->compareNumbers(
                actual: $answer,
                expected: $expectedValue,
                comparison: 'greater_than_or_equal',
            ),

            'less_than' => $this->compareNumbers(
                actual: $answer,
                expected: $expectedValue,
                comparison: 'less_than',
            ),

            'less_than_or_equal' => $this->compareNumbers(
                actual: $answer,
                expected: $expectedValue,
                comparison: 'less_than_or_equal',
            ),

            'is_empty' => $this->isEmpty($answer),

            'is_not_empty' => ! $this->isEmpty($answer),

            default => throw new InvalidArgumentException(
                "Unsupported workflow branching operator [{$operator}]."
            ),
        };
    }

    /**
     * Compare values while safely handling booleans submitted as strings.
     */
    private function equals(
        mixed $actual,
        mixed $expected
    ): bool {
        return $this->normaliseComparableValue($actual)
            === $this->normaliseComparableValue($expected);
    }

    /**
     * Check whether an answer is present in an allowed set.
     */
    private function isIn(
        mixed $actual,
        mixed $expectedValues
    ): bool {
        if (! is_array($expectedValues)) {
            throw new InvalidArgumentException(
                'The value for an in/not_in rule must be an array.'
            );
        }

        $normalisedActual = $this->normaliseComparableValue($actual);

        $normalisedExpected = array_map(
            fn (mixed $value): mixed =>
                $this->normaliseComparableValue($value),
            $expectedValues,
        );

        return in_array(
            $normalisedActual,
            $normalisedExpected,
            true,
        );
    }

    /**
     * Check whether a string or array contains a value.
     */
    private function contains(
        mixed $actual,
        mixed $expected
    ): bool {
        if (is_array($actual)) {
            $normalisedExpected =
                $this->normaliseComparableValue($expected);

            foreach ($actual as $value) {
                if (
                    $this->normaliseComparableValue($value)
                    === $normalisedExpected
                ) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($actual)) {
            return false;
        }

        return str_contains(
            mb_strtolower($actual),
            mb_strtolower((string) $expected),
        );
    }

    /**
     * Compare numeric values.
     */
    private function compareNumbers(
        mixed $actual,
        mixed $expected,
        string $comparison
    ): bool {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        $actualValue = (float) $actual;
        $expectedValue = (float) $expected;

        return match ($comparison) {
            'greater_than' =>
                $actualValue > $expectedValue,

            'greater_than_or_equal' =>
                $actualValue >= $expectedValue,

            'less_than' =>
                $actualValue < $expectedValue,

            'less_than_or_equal' =>
                $actualValue <= $expectedValue,

            default => false,
        };
    }

    /**
     * Determine whether an answer should be treated as empty.
     */
    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * Normalise common API values before strict comparison.
     */
    private function normaliseComparableValue(
        mixed $value
    ): mixed {
        if (is_string($value)) {
            $trimmedValue = trim($value);
            $lowercaseValue = mb_strtolower($trimmedValue);

            return match ($lowercaseValue) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $trimmedValue,
            };
        }

        return $value;
    }
}