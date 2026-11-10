<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Enums\EnquiryPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\AssignEnquiryRequest;
use App\Http\Requests\Internal\ChangeEnquiryPriorityRequest;
use App\Http\Requests\Internal\CorrectEnquiryClassificationRequest;
use App\Http\Requests\Internal\ListEnquiriesRequest;
use App\Http\Resources\Internal\EnquiryListItemResource;
use App\Http\Resources\InternalEnquiryResource;
use App\Models\Enquiry;
use App\Models\StaffUser;
use App\Services\Enquiries\EnquiryPriorityService;
use App\Services\Enquiries\EnquiryQueueQuery;
use App\Services\Enquiries\InternalEnquiryService;
use App\Services\StaffAuthorisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EnquiryController extends Controller
{
    public function index(ListEnquiriesRequest $request, EnquiryQueueQuery $queues): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return EnquiryListItemResource::collection($queues->filter(Enquiry::query()->with('assignedStaff'), $filters)
            ->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function counts(EnquiryQueueQuery $queues): JsonResponse
    {
        return response()->json(['data' => $queues->counts(Enquiry::query())]);
    }

    public function show(Request $request, Enquiry $enquiry, StaffAuthorisationService $authorisation): InternalEnquiryResource
    {
        $staff = $request->user();
        $allowed = $staff instanceof StaffUser && (
            $authorisation->allows($staff, 'enquiries.view')
            || ($authorisation->allows($staff, 'work.view-own') && $enquiry->assigned_staff_id !== null && (int) $enquiry->assigned_staff_id === (int) $staff->id)
        );
        abort_unless($allowed, 403, 'You are not authorised to access this enquiry.');

        return $this->resource($enquiry);
    }

    public function assign(AssignEnquiryRequest $request, Enquiry $enquiry, InternalEnquiryService $service): InternalEnquiryResource
    {
        $id = $request->validated('assigned_staff_id');

        return $this->resource($service->assign($enquiry, $id === null ? null : (int) $id, $request->user()));
    }

    public function correctClassification(CorrectEnquiryClassificationRequest $request, Enquiry $enquiry, InternalEnquiryService $service): InternalEnquiryResource
    {
        return $this->resource($service->correctClassification($enquiry, $request->validated(), $request->user()));
    }

    public function changePriority(ChangeEnquiryPriorityRequest $request, Enquiry $enquiry, EnquiryPriorityService $service): InternalEnquiryResource
    {
        return $this->resource($service->override($enquiry, EnquiryPriority::from($request->validated('priority')), $request->validated('reason'), $request->user()));
    }

    private function resource(Enquiry $enquiry): InternalEnquiryResource
    {
        return new InternalEnquiryResource($enquiry->load([
            'assignedStaff', 'user', 'conversation.memory',
            'conversation.messages' => fn ($query) => $query->whereIn('role', ['user', 'assistant'])->orderBy('created_at')->orderBy('id'),
            'events' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'events.performedByStaff', 'workflow',
        ]));
    }
}
