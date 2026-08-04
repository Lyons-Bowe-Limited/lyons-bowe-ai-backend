<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enquiries\StartEnquiryRequest;
use App\Models\Enquiry;
use App\Services\Enquiries\EnquiryWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnquiryController extends Controller
{
    public function __construct(
        private readonly EnquiryWorkflowService $workflowService,
    ) {
    }

    /**
     * Start a new enquiry workflow.
     */
    public function store(
        StartEnquiryRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $result = $this->workflowService->start(
            practiceArea: $validated['practice_area'],
            user: $request->user(),
            conversationId:
                $validated['conversation_id'] ?? null,
            workflowKey:
                $validated['workflow_key'] ?? null,
            priority:
                $validated['priority'] ?? 'normal',
        );

        return response()->json([
            'message' => 'Enquiry workflow started successfully.',
            'data' => $result->toArray(),
        ], 201);
    }

    /**
     * Return the current workflow state.
     */
    public function show(
        Request $request,
        Enquiry $enquiry
    ): JsonResponse {
        $this->authoriseEnquiryAccess(
            request: $request,
            enquiry: $enquiry,
        );

        $result = $this->workflowService->getCurrentState(
            $enquiry
        );

        return response()->json([
            'message' => 'Enquiry retrieved successfully.',
            'data' => $result->toArray(),
        ]);
    }

    /**
     * Prevent one authenticated user from accessing
     * another user's enquiry.
     */
    private function authoriseEnquiryAccess(
        Request $request,
        Enquiry $enquiry
    ): void {
        $user = $request->user();

        if ($user === null) {
            return;
        }

        if (
            $enquiry->user_id !== null
            && (int) $enquiry->user_id !== (int) $user->id
        ) {
            abort(
                403,
                'You are not authorised to access this enquiry.'
            );
        }
    }
}