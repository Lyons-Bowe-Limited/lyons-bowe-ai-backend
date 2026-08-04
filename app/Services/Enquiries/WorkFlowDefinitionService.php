<?php

namespace App\Services\Enquiries;

use InvalidArgumentException;

class WorkflowDefinitionService
{
    /**
     * Get a complete workflow definition.
     *
     * @throws InvalidArgumentException
     */
    public function get(string $workflowKey): array
    {
        $workflow = config(
            "enquiry-workflows.workflows.{$workflowKey}"
        );

        if (! is_array($workflow)) {
            throw new InvalidArgumentException(
                "The enquiry workflow [{$workflowKey}] does not exist."
            );
        }

        $this->validateDefinition($workflowKey, $workflow);

        return $workflow;
    }

    /**
     * Get the configured default workflow.
     */
    public function getDefault(): array
    {
        $workflowKey = config('enquiry-workflows.default');

        if (! is_string($workflowKey) || $workflowKey === '') {
            throw new InvalidArgumentException(
                'No default enquiry workflow has been configured.'
            );
        }

        return $this->get($workflowKey);
    }

    /**
     * Determine whether a workflow exists.
     */
    public function exists(string $workflowKey): bool
    {
        return is_array(
            config("enquiry-workflows.workflows.{$workflowKey}")
        );
    }

    /**
     * Get a step from a workflow.
     *
     * @throws InvalidArgumentException
     */
    public function getStep(
        string $workflowKey,
        string $stepKey
    ): array {
        $workflow = $this->get($workflowKey);

        $step = $workflow['steps'][$stepKey] ?? null;

        if (! is_array($step)) {
            throw new InvalidArgumentException(
                "The step [{$stepKey}] does not exist in workflow "
                . "[{$workflowKey}]."
            );
        }

        return $step;
    }

    /**
     * Get the first step in a workflow.
     */
    public function getFirstStep(string $workflowKey): array
    {
        $workflow = $this->get($workflowKey);
        $firstStepKey = $workflow['first_step'];

        return $this->getStep($workflowKey, $firstStepKey);
    }

    /**
     * Return every step in the workflow.
     */
    public function getSteps(string $workflowKey): array
    {
        return $this->get($workflowKey)['steps'];
    }

    /**
     * Return the total number of defined steps.
     */
    public function countSteps(string $workflowKey): int
    {
        return count($this->getSteps($workflowKey));
    }

    /**
     * Check the basic integrity of a workflow definition.
     *
     * @throws InvalidArgumentException
     */
    private function validateDefinition(
        string $workflowKey,
        array $workflow
    ): void {
        $requiredFields = [
            'key',
            'name',
            'practice_area',
            'version',
            'first_step',
            'steps',
        ];

        foreach ($requiredFields as $requiredField) {
            if (! array_key_exists($requiredField, $workflow)) {
                throw new InvalidArgumentException(
                    "Workflow [{$workflowKey}] is missing the "
                    . "[{$requiredField}] configuration value."
                );
            }
        }

        if (! is_array($workflow['steps']) || $workflow['steps'] === []) {
            throw new InvalidArgumentException(
                "Workflow [{$workflowKey}] must contain at least one step."
            );
        }

        if (! isset($workflow['steps'][$workflow['first_step']])) {
            throw new InvalidArgumentException(
                "The first step [{$workflow['first_step']}] does not "
                . "exist in workflow [{$workflowKey}]."
            );
        }

        foreach ($workflow['steps'] as $stepKey => $step) {
            $this->validateStep(
                workflowKey: $workflowKey,
                stepKey: $stepKey,
                step: $step,
                availableSteps: array_keys($workflow['steps']),
            );
        }
    }

    /**
     * Validate an individual workflow step.
     *
     * @throws InvalidArgumentException
     */
    private function validateStep(
        string $workflowKey,
        string $stepKey,
        array $step,
        array $availableSteps
    ): void {
        foreach (['key', 'question_key', 'question', 'type'] as $field) {
            if (! array_key_exists($field, $step)) {
                throw new InvalidArgumentException(
                    "Step [{$stepKey}] in workflow [{$workflowKey}] "
                    . "is missing [{$field}]."
                );
            }
        }

        if ($step['key'] !== $stepKey) {
            throw new InvalidArgumentException(
                "The configured key [{$step['key']}] does not match "
                . "the step array key [{$stepKey}]."
            );
        }

        $this->validateNextStep(
            workflowKey: $workflowKey,
            stepKey: $stepKey,
            next: $step['next'] ?? null,
            availableSteps: $availableSteps,
        );
    }

    /**
     * Validate direct and conditional next-step definitions.
     *
     * A null next value marks the final workflow step.
     *
     * @throws InvalidArgumentException
     */
    private function validateNextStep(
        string $workflowKey,
        string $stepKey,
        string|array|null $next,
        array $availableSteps
    ): void {
        if ($next === null) {
            return;
        }

        if (is_string($next)) {
            $this->assertStepExists(
                workflowKey: $workflowKey,
                currentStepKey: $stepKey,
                nextStepKey: $next,
                availableSteps: $availableSteps,
            );

            return;
        }

        $rules = $next['rules'] ?? [];
        $default = $next['default'] ?? null;

        if (! is_array($rules)) {
            throw new InvalidArgumentException(
                "Conditional rules for step [{$stepKey}] must be an array."
            );
        }

        foreach ($rules as $rule) {
            $ruleStep = $rule['step'] ?? null;

            if (! is_string($ruleStep)) {
                throw new InvalidArgumentException(
                    "A branching rule for step [{$stepKey}] does not "
                    . 'contain a valid target step.'
                );
            }

            $this->assertStepExists(
                workflowKey: $workflowKey,
                currentStepKey: $stepKey,
                nextStepKey: $ruleStep,
                availableSteps: $availableSteps,
            );
        }

        if ($default !== null) {
            if (! is_string($default)) {
                throw new InvalidArgumentException(
                    "The default branch for step [{$stepKey}] "
                    . 'must be a step key or null.'
                );
            }

            $this->assertStepExists(
                workflowKey: $workflowKey,
                currentStepKey: $stepKey,
                nextStepKey: $default,
                availableSteps: $availableSteps,
            );
        }
    }

    /**
     * Confirm that a target step exists.
     *
     * @throws InvalidArgumentException
     */
    private function assertStepExists(
        string $workflowKey,
        string $currentStepKey,
        string $nextStepKey,
        array $availableSteps
    ): void {
        if (! in_array($nextStepKey, $availableSteps, true)) {
            throw new InvalidArgumentException(
                "Step [{$currentStepKey}] in workflow [{$workflowKey}] "
                . "points to missing step [{$nextStepKey}]."
            );
        }
    }
}