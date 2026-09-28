<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enquiries\SubmitEnquiryAnswerRequest;
use App\Models\Enquiry;
use App\Services\Enquiries\EnquiryWorkflowService;
use Illuminate\Http\JsonResponse;

class EnquiryAnswerController extends Controller
{
    public function __construct(
        private readonly EnquiryWorkflowService $workflowService,
    ) {
    }

    /**
     * Save an answer and advance the workflow.
     */
    public function store(
        SubmitEnquiryAnswerRequest $request,
        Enquiry $enquiry
    ): JsonResponse {
        $this->authoriseEnquiryAccess(
            requestUserId: $request->user()?->id,
            enquiry: $enquiry,
        );

        $validated = $request->validated();

        $result = $this->workflowService->submitAnswer(
            enquiry: $enquiry,
            stepKey: $validated['step_key'],
            answer: $validated['answer'],
            performedBy: $request->user()?->id,
        );

        return response()->json([
            'message' => $result->completed
                ? 'Enquiry workflow completed successfully.'
                : 'Answer saved successfully.',

            'data' => $result->toArray(),
        ]);
    }

    private function authoriseEnquiryAccess(
        ?int $requestUserId,
        Enquiry $enquiry
    ): void {
        if ($requestUserId === null) {
            return;
        }

        if (
            $enquiry->user_id !== null
            && (int) $enquiry->user_id !== $requestUserId
        ) {
            abort(
                403,
                'You are not authorised to access this enquiry.'
            );
        }
    }
}