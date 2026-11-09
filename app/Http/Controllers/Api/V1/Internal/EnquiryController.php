<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Enums\EnquiryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\AssignEnquiryRequest;
use App\Http\Requests\Internal\CorrectEnquiryClassificationRequest;
use App\Http\Requests\Internal\ListEnquiriesRequest;
use App\Http\Resources\Internal\EnquiryListItemResource;
use App\Http\Resources\InternalEnquiryResource;
use App\Models\Enquiry;
use App\Services\Enquiries\InternalEnquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EnquiryController extends Controller
{
    public function index(ListEnquiriesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Enquiry::query()->with('assignedStaff');
        if (filled($filters['search'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['search']))).'%';
            $query->where(function ($query) use ($term) {
                foreach (['reference', 'client_name', 'client_email', 'client_phone'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        foreach (['status', 'practice_area', 'matter_type', 'assigned_staff_id', 'office'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (array_key_exists('requires_review', $filters)) {
            $query->where('requires_review', $request->boolean('requires_review'));
        }
        if (array_key_exists('unassigned', $filters)) {
            $request->boolean('unassigned') ? $query->whereNull('assigned_staff_id') : $query->whereNotNull('assigned_staff_id');
        }

        return EnquiryListItemResource::collection($query
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function counts(): JsonResponse
    {
        $counts = Enquiry::query()->selectRaw('COUNT(*) AS all_count,
            COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS submitted,
            COALESCE(SUM(CASE WHEN assigned_staff_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned,
            COALESCE(SUM(CASE WHEN assigned_staff_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS assigned,
            COALESCE(SUM(CASE WHEN requires_review THEN 1 ELSE 0 END), 0) AS review_count', [EnquiryStatus::Submitted->value])->first();

        return response()->json(['data' => [
            'all' => (int) $counts->all_count, 'submitted' => (int) $counts->submitted,
            'unassigned' => (int) $counts->unassigned, 'assigned' => (int) $counts->assigned, 'requires_review' => (int) $counts->review_count,
        ]]);
    }

    public function show(Enquiry $enquiry): InternalEnquiryResource
    {
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
