<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\ListStaffNotificationsRequest;
use App\Http\Resources\Internal\StaffNotificationResource;
use App\Models\StaffNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffNotificationController extends Controller
{
    public function index(ListStaffNotificationsRequest $request): AnonymousResourceCollection
    {
        $query = $request->user()->staffNotifications();
        if ($request->has('unread')) {
            $request->boolean('unread') ? $query->whereNull('read_at') : $query->whereNotNull('read_at');
        }

        return StaffNotificationResource::collection($query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->validated('per_page', 25))->withQueryString());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $request->user()->staffNotifications()->whereNull('read_at')->count()]]);
    }

    public function read(Request $request, StaffNotification $notification): StaffNotificationResource
    {
        abort_unless($notification->staff_user_id === (int) $request->user()->id, 403, 'You are not authorised to access this notification.');
        $request->user()->staffNotifications()->whereKey($notification->id)->whereNull('read_at')->update(['read_at' => now()]);

        return new StaffNotificationResource($request->user()->staffNotifications()->whereKey($notification->id)->firstOrFail());
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = $request->user()->staffNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['marked_read' => $count]]);
    }
}
