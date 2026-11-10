<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\ListMyWorkEnquiriesRequest;
use App\Http\Resources\Internal\EnquiryListItemResource;
use App\Models\Enquiry;
use App\Services\Enquiries\EnquiryQueueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyWorkEnquiryController extends Controller
{
    public function index(ListMyWorkEnquiriesRequest $request, EnquiryQueueQuery $queues): AnonymousResourceCollection
    {
        $query = Enquiry::query()->where('assigned_staff_id', $request->user()->id)->with('assignedStaff');
        $filters = $request->validated();
        // These fields are never passed to the shared filter, even if submitted empty.
        unset($filters['assigned_staff_id'], $filters['staff_id'], $filters['staff_user_id'], $filters['user_id'], $filters['unassigned']);

        return EnquiryListItemResource::collection($queues->filter($query, $filters)
            ->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function counts(Request $request, EnquiryQueueQuery $queues): JsonResponse
    {
        return response()->json(['data' => $queues->counts(
            Enquiry::query()->where('assigned_staff_id', $request->user()->id)
        )]);
    }
}
