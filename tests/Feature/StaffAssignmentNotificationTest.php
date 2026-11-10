<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\StaffNotification;
use App\Models\StaffRole;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Enquiries\EnquiryEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'solicitor', array $attributes = []): StaffUser
    {
        $staff = StaffUser::create([
            'first_name' => 'Notification', 'last_name' => 'User', 'email' => Str::uuid().'@example.com',
            'status' => 'active', 'email_verified_at' => now(), ...$attributes,
        ]);
        $staff->roles()->attach(StaffRole::where('name', $role)->sole());

        return $staff;
    }

    private function enquiry(array $attributes = []): Enquiry
    {
        return Enquiry::create([
            'reference' => 'NOTIFY-'.Str::uuid(), 'workflow_key' => 'victoria_conversation', 'practice_area' => 'family_law',
            'matter_type' => 'divorce_and_separation', 'status' => 'draft', 'client_name' => 'Example Client',
            'client_email' => 'client@example.com', 'requires_review' => true, ...$attributes,
        ]);
    }

    private function notification(StaffUser $staff, array $attributes = []): StaffNotification
    {
        return $staff->staffNotifications()->create([
            'type' => 'enquiry_assigned', 'title' => 'Enquiry assigned to you', 'message' => 'Example work was assigned to you.', ...$attributes,
        ]);
    }

    private function assign(StaffUser $manager, Enquiry $enquiry, ?StaffUser $assignee): void
    {
        $this->actingAs($manager, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $assignee?->id])->assertOk();
    }

    private function ownIds(StaffUser $staff): array
    {
        return $this->actingAs($staff, 'staff_sanctum')->getJson('/api/staff/my-work/enquiries')->assertOk()->json('data.*.id');
    }

    public function test_assignment_creates_one_notification_and_work_for_the_new_assignee(): void
    {
        $manager = $this->staff('enquiry_team_manager');
        $assignee = $this->staff();
        $enquiry = $this->enquiry();
        $this->assign($manager, $enquiry, $assignee);
        $notification = StaffNotification::sole();
        $this->assertSame($assignee->id, $notification->staff_user_id);
        $this->assertSame('enquiry_assigned', $notification->type);
        $this->assertSame($enquiry->id, $notification->enquiry_id);
        $this->assertStringContainsString($enquiry->reference, $notification->message);
        $this->assertStringContainsString('Example Client', $notification->message);
        $this->assertStringContainsString('assigned to you', $notification->message);
        $this->assertNull($notification->read_at);
        $this->assertNotNull($notification->created_at);
        $this->assertNotNull($notification->updated_at);
        $this->assertSame([$enquiry->id], $this->ownIds($assignee));
        $this->getJson('/api/staff/my-work/counts')->assertOk()->assertJsonPath('data.all', 1)->assertJsonPath('data.draft', 1)->assertJsonPath('data.requires_review', 1)->assertJsonMissingPath('data.overdue');
        $this->getJson('/api/staff/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.enquiry_id', $enquiry->id);
        $this->assertSame(0, $manager->staffNotifications()->count());
        $this->assertSame(1, $enquiry->events()->where('event_type', 'enquiry_assigned')->count());
    }

    public function test_reassignment_moves_work_and_only_notifies_the_new_assignee(): void
    {
        $manager = $this->staff('super_admin');
        $first = $this->staff();
        $second = $this->staff();
        $enquiry = $this->enquiry();
        $this->assign($manager, $enquiry, $first);
        $this->assign($manager, $enquiry, $second);
        $this->assertSame([], $this->ownIds($first));
        $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertForbidden();
        $this->assertSame([$enquiry->id], $this->ownIds($second));
        $this->assertSame(1, $first->staffNotifications()->count());
        $this->assertSame(1, $second->staffNotifications()->count());
        $this->assertDatabaseCount('staff_notifications', 2);
        $this->assertSame(1, $enquiry->events()->where('event_type', 'enquiry_reassigned')->count());
    }

    public function test_same_assignment_is_a_noop_without_duplicate_notifications_or_events(): void
    {
        $manager = $this->staff('super_admin');
        $assignee = $this->staff();
        $enquiry = $this->enquiry();
        $this->assign($manager, $enquiry, $assignee);
        $before = StaffNotification::sole()->getAttributes();
        $this->assign($manager, $enquiry, $assignee);
        $this->assertDatabaseCount('staff_notifications', 1);
        $this->assertSame($before, StaffNotification::sole()->getAttributes());
        $this->assertSame(1, $enquiry->events()->count());
    }

    public function test_unassignment_removes_work_without_an_assignment_notification(): void
    {
        $manager = $this->staff('enquiry_team_manager');
        $assignee = $this->staff();
        $enquiry = $this->enquiry();
        $this->assign($manager, $enquiry, $assignee);
        $this->assign($manager, $enquiry, null);
        $this->assertSame([], $this->ownIds($assignee));
        $this->getJson('/api/staff/my-work/counts')->assertJsonPath('data.all', 0)->assertJsonPath('data.draft', 0);
        $this->assertDatabaseCount('staff_notifications', 1);
        $this->assertNull($enquiry->fresh()->assigned_staff_id);
        $this->assertSame(1, $enquiry->events()->where('event_type', 'enquiry_unassigned')->count());
    }

    public function test_reassignment_back_to_a_previous_assignee_creates_a_new_valid_notification(): void
    {
        $manager = $this->staff('super_admin');
        $first = $this->staff();
        $second = $this->staff();
        $enquiry = $this->enquiry();
        foreach ([$first, $second, $first] as $assignee) {
            $this->assign($manager, $enquiry, $assignee);
        }
        $this->assertSame(2, $first->staffNotifications()->count());
        $this->assertSame(1, $second->staffNotifications()->count());
        $this->assertDatabaseCount('staff_notifications', 3);
    }

    public function test_notifications_are_private_paginated_newest_first_and_ignore_scope_escape_attempts(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $older = $this->notification($owner);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = $this->notification($owner);
        $foreign = $this->notification($other, ['message' => 'FOREIGN_NOTIFICATION']);
        $this->actingAs($owner, 'staff_sanctum')->getJson('/api/staff/notifications?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)
            ->assertJsonStructure(['links', 'meta', 'data' => [['id', 'staff_user_id', 'type', 'title', 'message', 'enquiry_id', 'read_at', 'created_at', 'updated_at']]]);
        $this->getJson('/api/staff/notifications?per_page=1&page=2')->assertJsonPath('data.0.id', $older->id);
        foreach (['staff_user_id', 'staff_id', 'user_id'] as $parameter) {
            $this->getJson('/api/staff/notifications?'.$parameter.'='.$other->id)->assertUnprocessable()->assertJsonMissingPath('data');
        }
        $response = $this->getJson('/api/staff/notifications?scope=all&filter[staff_user_id]='.$other->id)->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('FOREIGN_NOTIFICATION', $response->getContent());
        $this->patchJson('/api/staff/notifications/'.$foreign->id.'/read')->assertForbidden();
        $this->assertNull($foreign->fresh()->read_at);
        $this->actingAs($this->staff('super_admin'), 'staff_sanctum')->patchJson('/api/staff/notifications/'.$foreign->id.'/read')->assertForbidden();
    }

    public function test_unread_count_and_mark_one_read_are_scoped_and_idempotent(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $first = $this->notification($owner);
        $second = $this->notification($owner);
        $this->notification($owner, ['read_at' => now()->subDay()]);
        $foreign = $this->notification($other);
        $this->actingAs($owner, 'staff_sanctum')->getJson('/api/staff/notifications/unread-count?staff_user_id='.$other->id)->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->patchJson('/api/staff/notifications/'.$first->id.'/read', ['staff_user_id' => $other->id, 'read_at' => '2000-01-01'])->assertOk()->assertJsonPath('data.id', $first->id);
        $readAt = $first->fresh()->read_at;
        $this->assertNotNull($readAt);
        $this->travel(5)->minutes();
        $this->patchJson('/api/staff/notifications/'.$first->id.'/read')->assertOk();
        $this->assertTrue($readAt->equalTo($first->fresh()->read_at));
        $this->getJson('/api/staff/notifications/unread-count')->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/staff/notifications?unread=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/staff/notifications?unread=0')->assertJsonCount(2, 'data');
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_unread_popup_refresh_excludes_read_notifications_and_preserves_history(): void
    {
        $owner = $this->staff();
        $first = $this->notification($owner);
        $second = $this->notification($owner);
        $historical = $this->notification($owner, ['read_at' => now()->subDay()]);

        $this->actingAs($owner, 'staff_sanctum')->getJson('/api/staff/notifications?unread=1')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->patchJson('/api/staff/notifications/'.$first->id.'/read')
            ->assertOk()->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.read_at', fn ($value) => is_string($value));

        // A new request represents reloading the popup after navigation or refresh.
        $this->getJson('/api/staff/notifications?unread=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.read_at', null)->assertJsonMissingPath('data.0.is_read');
        $this->getJson('/api/staff/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 1);

        $this->patchJson('/api/staff/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->getJson('/api/staff/notifications?unread=1')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/staff/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);

        $history = $this->getJson('/api/staff/notifications?unread=0')->assertOk()->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $historical->id], $history->json('data.*.id'));
        $this->assertDatabaseCount('staff_notifications', 3);
    }

    public function test_notification_filter_requires_laravel_compatible_query_booleans(): void
    {
        $this->actingAs($this->staff(), 'staff_sanctum');

        foreach (['1', '0'] as $value) {
            $this->getJson('/api/staff/notifications?unread='.$value)->assertOk();
        }

        foreach (['true', 'false'] as $value) {
            $this->getJson('/api/staff/notifications?unread='.$value)
                ->assertUnprocessable()->assertJsonValidationErrors('unread');
        }
    }

    public function test_mark_all_read_updates_only_own_unread_notifications(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $this->notification($owner);
        $this->notification($owner);
        $read = $this->notification($owner, ['read_at' => now()->subDay()]);
        $originalRead = $read->read_at;
        $foreign = $this->notification($other);
        $this->actingAs($owner, 'staff_sanctum')->patchJson('/api/staff/notifications/read-all', ['staff_user_id' => $other->id])->assertOk()->assertJsonPath('data.marked_read', 2);
        $this->getJson('/api/staff/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertTrue($originalRead->equalTo($read->fresh()->read_at));
        $this->patchJson('/api/staff/notifications/read-all')->assertJsonPath('data.marked_read', 0);
    }

    public function test_authentication_and_active_verification_are_required_for_all_notification_endpoints(): void
    {
        $owner = $this->staff();
        $notification = $this->notification($owner);
        $routes = [['GET', '/api/staff/notifications'], ['GET', '/api/staff/notifications/unread-count'], ['PATCH', '/api/staff/notifications/'.$notification->id.'/read'], ['PATCH', '/api/staff/notifications/read-all']];
        foreach ($routes as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
        $token = User::factory()->create()->createToken('client')->plainTextToken;
        foreach ($routes as [$method, $url]) {
            $this->withToken($token)->json($method, $url)->assertUnauthorized();
        }
        foreach ([['status' => 'deactivated'], ['email_verified_at' => null]] as $attributes) {
            $staff = $this->staff(attributes: $attributes);
            foreach ($routes as [$method, $url]) {
                $this->actingAs($staff, 'staff_sanctum')->json($method, $url)->assertForbidden();
            }
        }
    }

    public function test_inbox_is_personal_without_management_permissions_and_validates_pagination(): void
    {
        $owner = $this->staff('basic');
        $notification = $this->notification($owner);
        $this->actingAs($owner, 'staff_sanctum')->getJson('/api/staff/notifications')->assertOk()->assertJsonPath('data.0.id', $notification->id);
        foreach (['per_page=101', 'per_page=0', 'page=0', 'unread=invalid'] as $query) {
            $this->getJson('/api/staff/notifications?'.$query)->assertUnprocessable();
        }
        $this->patchJson('/api/staff/notifications/'.Str::uuid().'/read')->assertNotFound();
        $this->patchJson('/api/staff/notifications/invalid/read')->assertNotFound();
    }

    public function test_audit_failure_rolls_back_assignment_without_a_notification(): void
    {
        $manager = $this->staff('super_admin');
        $assignee = $this->staff();
        $enquiry = $this->enquiry();
        $this->mock(EnquiryEventService::class, function ($mock) {
            $mock->shouldReceive('staffChange')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        });
        $this->actingAs($manager, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $assignee->id])->assertStatus(500);
        $this->assertNull($enquiry->fresh()->assigned_staff_id);
        $this->assertDatabaseCount('staff_notifications', 0);
        $this->assertDatabaseCount('enquiry_events', 0);
    }

    public function test_failure_after_notification_insert_rolls_back_notification_assignment_and_audit(): void
    {
        $manager = $this->staff('super_admin');
        $assignee = $this->staff();
        $enquiry = $this->enquiry();
        StaffNotification::created(function () {
            throw new \RuntimeException('Simulated failure after notification insertion');
        });
        try {
            $this->actingAs($manager, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $assignee->id])->assertStatus(500);
        } finally {
            StaffNotification::flushEventListeners();
        }
        $this->assertNull($enquiry->fresh()->assigned_staff_id);
        $this->assertDatabaseCount('staff_notifications', 0);
        $this->assertDatabaseCount('enquiry_events', 0);
        $this->assertSame([], $this->ownIds($assignee));
    }

    public function test_failed_reassignment_preserves_previous_owner_and_commits_no_notification(): void
    {
        $manager = $this->staff('super_admin');
        $first = $this->staff();
        $second = $this->staff();
        $enquiry = $this->enquiry();
        $this->assign($manager, $enquiry, $first);
        StaffNotification::created(function () {
            throw new \RuntimeException('Simulated insert failure');
        });
        try {
            $this->actingAs($manager, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $second->id])->assertStatus(500);
        } finally {
            StaffNotification::flushEventListeners();
        }
        $this->assertSame($first->id, $enquiry->fresh()->assigned_staff_id);
        $this->assertSame([$enquiry->id], $this->ownIds($first));
        $this->assertSame([], $this->ownIds($second));
        $this->assertDatabaseCount('staff_notifications', 1);
        $this->assertDatabaseCount('enquiry_events', 1);
    }

    public function test_my_work_totals_include_real_drafts_and_submissions_but_not_overdue(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $this->enquiry(['assigned_staff_id' => $owner->id]);
        $this->enquiry(['assigned_staff_id' => $owner->id, 'status' => 'submitted', 'requires_review' => false]);
        $this->enquiry(['assigned_staff_id' => $other->id]);
        $this->enquiry();
        $this->actingAs($owner, 'staff_sanctum')->getJson('/api/staff/my-work/counts?staff_user_id='.$other->id)->assertOk()->assertExactJson([
            'data' => ['all' => 2, 'submitted' => 1, 'draft' => 1, 'assigned' => 2, 'unassigned' => 0, 'requires_review' => 1],
        ]);
        $this->getJson('/api/staff/my-work/enquiries?assigned_staff_id='.$other->id)->assertUnprocessable();
        $this->getJson('/api/staff/enquiries')->assertForbidden();
    }

    public function test_deleted_enquiry_preserves_historical_notification_and_legacy_table_is_untouched(): void
    {
        $manager = $this->staff('super_admin');
        $owner = $this->staff();
        $enquiry = $this->enquiry();
        $this->assign($manager, $enquiry, $owner);
        $enquiry->delete();
        $notification = StaffNotification::sole();
        $this->assertNull($notification->enquiry_id);
        $this->assertSame($owner->id, $notification->staff_user_id);
        $this->assertDatabaseCount('notifications', 0);
        $this->actingAs($owner, 'staff_sanctum')->getJson('/api/staff/notifications')->assertOk()->assertJsonCount(1, 'data');
    }
}
