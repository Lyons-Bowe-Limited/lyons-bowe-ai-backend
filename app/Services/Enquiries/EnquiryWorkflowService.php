<?php

namespace App\Services\Enquiries;

use App\Data\Enquiries\StepResult;
use App\Models\Enquiry;
use App\Models\EnquiryAnswer;
use App\Models\EnquiryWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EnquiryWorkflowService
{
    public function __construct(
        private readonly WorkflowDefinitionService $definitions,
        private readonly WorkflowBranchResolver $branchResolver,
        private readonly WorkflowAnswerValidator $answerValidator,
        private readonly EnquiryReferenceService $references,
        private readonly EnquiryEventService $events,
    ) {
    }

    /**
     * Start a new enquiry and return the first workflow step.
     */
    public function start(
        string $practiceArea,
        ?User $user = null,
        ?int $conversationId = null,
        ?string $workflowKey = null,
        string $priority = 'normal',
    ): StepResult {
        $workflowKey ??= $this->resolveWorkflowKey(
            $practiceArea
        );

        $definition = $this->definitions->get($workflowKey);
        $firstStep = $this->definitions->getFirstStep(
            $workflowKey
        );

        return DB::transaction(function () use (
            $practiceArea,
            $user,
            $conversationId,
            $workflowKey,
            $priority,
            $definition,
            $firstStep,
        ): StepResult {
            $enquiry = Enquiry::query()->create([
                'reference' => $this->references->generate(
                    $practiceArea
                ),

                'user_id' => $user?->id,
                'conversation_id' => $conversationId,

                'practice_area' => $practiceArea,
                'workflow_key' => $workflowKey,

                'status' => 'in_progress',
                'priority' => $priority,

                /*
                * Reuse information already collected during registration.
                */
                'client_name' => $user?->name,
                'client_email' => $user?->email,
                'client_phone' => $user?->phone
                    ?? $user?->mobile
                    ?? null,

                'completion_percentage' => 0,
                'started_at' => now(),
                'last_activity_at' => now(),

                'metadata' => [
                    'client_details_source' => 'user_account',
                ],
            ]);

            $workflow = $enquiry->workflow()->create([
                'workflow_key' => $workflowKey,
                'workflow_version' =>
                    (string) ($definition['version'] ?? '1.0'),
                'status' => 'in_progress',
                'current_step_key' => $firstStep['key'],
                'answered_steps' => 0,
                'total_applicable_steps' =>
                    $this->definitions->countSteps(
                        $workflowKey
                    ),
                'state' => [
                    'visited_steps' => [],
                    'completed_steps' => [],
                ],
                'started_at' => now(),
            ]);

            $this->events->workflowStarted(
                enquiry: $enquiry,
                workflow: $workflow,
                performedBy: $user?->id,
            );

            return $this->buildResult(
                enquiry: $enquiry,
                workflow: $workflow,
            );
        });
    }

    /**
     * Save an answer and advance the workflow.
     *
     * @throws ValidationException
     */
    public function submitAnswer(
        Enquiry $enquiry,
        string $stepKey,
        mixed $answer,
        ?int $performedBy = null,
    ): StepResult {
        return DB::transaction(function () use (
            $enquiry,
            $stepKey,
            $answer,
            $performedBy,
        ): StepResult {
            $enquiry = Enquiry::query()
                ->whereKey($enquiry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $workflow = EnquiryWorkflow::query()
                ->where('enquiry_id', $enquiry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertWorkflowCanAcceptAnswer(
                $enquiry,
                $workflow,
                $stepKey,
            );

            $step = $this->definitions->getStep(
                $workflow->workflow_key,
                $stepKey,
            );

            $validatedAnswer = $this->answerValidator->validate(
                step: $step,
                answer: $answer,
            );

            $existingAnswer = EnquiryAnswer::query()
                ->where('workflow_id', $workflow->id)
                ->where('step_key', $stepKey)
                ->latest('revision')
                ->first();

            $revision = ($existingAnswer?->revision ?? 0) + 1;

            EnquiryAnswer::query()->create([
                'enquiry_id' => $enquiry->id,
                'workflow_id' => $workflow->id,

                'step_key' => $stepKey,

                'question_key' => $step['key'],
                'question_text' => $step['question'],
                'answer_type' => $step['type'],

                'answer' => [
                    'value' => $validatedAnswer,
                ],

                'normalised_answer' => [
                    'value' => $validatedAnswer,
                ],

                'metadata' => [
                    'workflow_version' => $workflow->workflow_version,
                ],

                'revision' => $revision,

                'answered_at' => now(),
            ]);

            $this->events->answerRecorded(
                enquiry: $enquiry,
                workflow: $workflow,
                stepKey: $stepKey,
                questionKey: $step['key'],
                answer: $validatedAnswer,
                revision: $revision,
                performedBy: $performedBy,
            );

            $nextStepKey = $this->branchResolver->resolve(
                workflowKey: $workflow->workflow_key,
                currentStepKey: $stepKey,
                answer: $validatedAnswer,
            );

            $state = $workflow->state ?? [];

            $visitedSteps = $state['visited_steps'] ?? [];
            $completedSteps = $state['completed_steps'] ?? [];

            if (! in_array($stepKey, $visitedSteps, true)) {
                $visitedSteps[] = $stepKey;
            }

            if (! in_array($stepKey, $completedSteps, true)) {
                $completedSteps[] = $stepKey;
            }

            $state['visited_steps'] = $visitedSteps;
            $state['completed_steps'] = $completedSteps;

            $answeredSteps = count($completedSteps);

            if ($nextStepKey === null) {
                return $this->completeWorkflow(
                    enquiry: $enquiry,
                    workflow: $workflow,
                    state: $state,
                    answeredSteps: $answeredSteps,
                    performedBy: $performedBy,
                    previousStepKey: $stepKey,
                );
            }

            $totalApplicableSteps = max(
                $workflow->total_applicable_steps,
                $answeredSteps + 1,
            );

            $progress = $this->calculateProgress(
                answeredSteps: $answeredSteps,
                totalSteps: $totalApplicableSteps,
                completed: false,
            );

            $workflow->update([
                'current_step_key' => $nextStepKey,
                'answered_steps' => $answeredSteps,
                'total_applicable_steps' =>
                    $totalApplicableSteps,
                'state' => $state,
                'last_activity_at' => now(),
            ]);

            $enquiry->update([
                'completion_percentage' => $progress,
                'last_activity_at' => now(),
            ]);

            $this->events->stepChanged(
                enquiry: $enquiry,
                workflow: $workflow,
                fromStepKey: $stepKey,
                toStepKey: $nextStepKey,
                performedBy: $performedBy,
            );

            return $this->buildResult(
                enquiry: $enquiry->fresh(),
                workflow: $workflow->fresh(),
            );
        });
    }

    /**
     * Get the current state of an enquiry.
     */
    public function getCurrentState(
        Enquiry $enquiry
    ): StepResult {
        $workflow = $enquiry->workflow;

        if ($workflow === null) {
            throw new RuntimeException(
                'This enquiry does not have a workflow.'
            );
        }

        return $this->buildResult(
            enquiry: $enquiry,
            workflow: $workflow,
        );
    }

    /**
     * Complete the workflow.
     */
    private function completeWorkflow(
        Enquiry $enquiry,
        EnquiryWorkflow $workflow,
        array $state,
        int $answeredSteps,
        ?int $performedBy,
        string $previousStepKey,
    ): StepResult {
        $previousEnquiryStatus = $enquiry->status;

        $workflow->update([
            'status' => 'completed',
            'current_step_key' => null,
            'answered_steps' => $answeredSteps,
            'total_applicable_steps' => $answeredSteps,
            'state' => $state,
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);

        $enquiry->update([
            'status' => 'completed',
            'completion_percentage' => 100,
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->events->stepChanged(
            enquiry: $enquiry,
            workflow: $workflow,
            fromStepKey: $previousStepKey,
            toStepKey: null,
            performedBy: $performedBy,
        );

        $this->events->statusChanged(
            enquiry: $enquiry,
            workflow: $workflow,
            fromStatus: $previousEnquiryStatus,
            toStatus: 'completed',
            performedBy: $performedBy,
        );

        $this->events->workflowCompleted(
            enquiry: $enquiry,
            workflow: $workflow,
            performedBy: $performedBy,
        );

        return $this->buildResult(
            enquiry: $enquiry->fresh(),
            workflow: $workflow->fresh(),
        );
    }

    /**
     * Build the standard workflow response.
     */
    private function buildResult(
        Enquiry $enquiry,
        EnquiryWorkflow $workflow,
    ): StepResult {
        $completed = $workflow->status === 'completed';

        $currentStepKey = $completed
            ? null
            : $workflow->current_step_key;

        $currentStep = $currentStepKey === null
            ? null
            : $this->definitions->getStep(
                $workflow->workflow_key,
                $currentStepKey,
            );

        return new StepResult(
            enquiry: $enquiry,
            workflow: $workflow,
            currentStepKey: $currentStepKey,
            currentStep: $currentStep,
            completed: $completed,
            progress: (int) $enquiry->completion_percentage,
        );
    }

    /**
     * Prevent invalid or out-of-order submissions.
     */
    private function assertWorkflowCanAcceptAnswer(
        Enquiry $enquiry,
        EnquiryWorkflow $workflow,
        string $stepKey,
    ): void {
        if (
            $enquiry->status === 'completed'
            || $workflow->status === 'completed'
        ) {
            throw ValidationException::withMessages([
                'workflow' => [
                    'This enquiry workflow has already been completed.',
                ],
            ]);
        }

        if ($workflow->current_step_key !== $stepKey) {
            throw ValidationException::withMessages([
                'step_key' => [
                    sprintf(
                        'The current workflow step is "%s".',
                        $workflow->current_step_key
                    ),
                ],
            ]);
        }
    }

    /**
     * Calculate workflow completion percentage.
     */
    private function calculateProgress(
        int $answeredSteps,
        int $totalSteps,
        bool $completed,
    ): int {
        if ($completed) {
            return 100;
        }

        if ($totalSteps <= 0) {
            return 0;
        }

        return min(
            99,
            (int) floor(
                ($answeredSteps / $totalSteps) * 100
            ),
        );
    }

    /**
     * Resolve the default workflow for a practice area.
     */
    private function resolveWorkflowKey(
        string $practiceArea
    ): string {
        return match ($practiceArea) {
            'wills_and_probate' => 'will_enquiry_v1',

            default => throw ValidationException::withMessages([
                'practice_area' => [
                    'No enquiry workflow has been configured for '
                    ."practice area \"{$practiceArea}\".",
                ],
            ]),
        };
    }
}