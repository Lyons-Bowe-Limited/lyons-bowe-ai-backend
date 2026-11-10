<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\StaffPermission;
use App\Models\StaffRole;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffMyWorkEnquiryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'solicitor', array $attributes = []): StaffUser
    {
        $staff = StaffUser::create([
            'first_name' => 'Queue', 'last_name' => 'User', 'email' => Str::uuid().'@example.com',
            'status' => 'active', 'email_verified_at' => now(), ...$attributes,
        ]);
        $staff->roles()->attach(StaffRole::where('name', $role)->sole());

        return $staff;
    }

    private function enquiry(?StaffUser $assignee = null, array $attributes = []): Enquiry
    {
        return Enquiry::create([
            'reference' => 'QUEUE-'.Str::uuid(), 'workflow_key' => 'victoria_conversation',
            'practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation',
            'status' => 'submitted', 'priority' => 'normal', 'requires_review' => false,
            'client_name' => 'Queue Client', 'client_email' => 'queue@example.com',
            'assigned_staff_id' => $assignee?->id, ...$attributes,
        ]);
    }

    private function ownIds(StaffUser $staff): array
    {
        return $this->actingAs($staff, 'staff_sanctum')->getJson('/api/staff/my-work/enquiries')->assertOk()->json('data.*.id');
    }

    public function test_super_admin_and_enquiry_manager_can_access_global_queue_counts_and_any_detail(): void
    {
        $assigned = $this->enquiry($this->staff());
        $unassigned = $this->enquiry();
        foreach (['super_admin', 'enquiry_team_manager'] as $role) {
            $this->actingAs($this->staff($role), 'staff_sanctum')->getJson('/api/staff/enquiries')->assertOk()->assertJsonCount(2, 'data');
            $this->getJson('/api/staff/enquiries/counts')->assertOk()->assertJsonPath('data.all', 2);
            $this->getJson('/api/staff/enquiries/'.$assigned->id)->assertOk();
            $this->getJson('/api/staff/enquiries/'.$unassigned->id)->assertOk();
        }
    }

    public function test_solicitor_cannot_access_global_queue_or_counts_even_with_staff_id_filter(): void
    {
        $solicitor = $this->staff();
        $this->enquiry($solicitor);
        $this->enquiry();
        $this->actingAs($solicitor, 'staff_sanctum');
        foreach (['/api/staff/enquiries', '/api/staff/enquiries/counts', '/api/staff/enquiries?assigned_staff_id='.$solicitor->id] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_solicitor_cannot_assign_classify_or_prioritise_even_their_own_enquiry(): void
    {
        $solicitor = $this->staff();
        $enquiry = $this->enquiry($solicitor);
        $this->actingAs($solicitor, 'staff_sanctum');
        $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => null])->assertForbidden();
        $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/classification', ['practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation', 'requires_review' => false])->assertForbidden();
        $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/priority', ['priority' => 'urgent', 'reason' => 'Own queue must not grant mutation access.'])->assertForbidden();
        $this->assertSame($solicitor->id, $enquiry->fresh()->assigned_staff_id);
        $this->assertDatabaseCount('enquiry_events', 0);
    }

    public function test_solicitor_lists_only_own_assignment_and_cannot_read_other_or_unassigned_detail(): void
    {
        $solicitor = $this->staff();
        $own = $this->enquiry($solicitor);
        $other = $this->enquiry($this->staff());
        $unassigned = $this->enquiry();
        $this->assertSame([$own->id], $this->ownIds($solicitor));
        $this->getJson('/api/staff/enquiries/'.$own->id)->assertOk()->assertJsonPath('data.id', $own->id);
        $this->getJson('/api/staff/enquiries/'.$other->id)->assertForbidden();
        $this->getJson('/api/staff/enquiries/'.$unassigned->id)->assertForbidden();
        $this->getJson('/api/staff/enquiries/'.Str::uuid())->assertNotFound();
    }

    public function test_my_work_counts_are_scoped_and_cannot_be_overridden_by_parameters(): void
    {
        $solicitor = $this->staff();
        $other = $this->staff();
        $this->enquiry($solicitor, ['requires_review' => true]);
        $this->enquiry($solicitor, ['status' => 'draft', 'requires_review' => true]);
        $this->enquiry($other);
        $this->enquiry(null, ['requires_review' => true]);
        $this->actingAs($solicitor, 'staff_sanctum')->getJson('/api/staff/my-work/counts?assigned_staff_id='.$other->id.'&scope=all&unassigned=1')->assertOk()->assertExactJson([
            'data' => ['all' => 2, 'submitted' => 1, 'draft' => 1, 'assigned' => 2, 'unassigned' => 0, 'requires_review' => 2],
        ]);
        $this->actingAs($other, 'staff_sanctum')->getJson('/api/staff/my-work/counts')->assertOk()->assertJsonPath('data.all', 1);
        $this->actingAs($this->staff(), 'staff_sanctum')->getJson('/api/staff/my-work/counts')->assertOk()->assertJsonPath('data.all', 0)->assertJsonPath('data.requires_review', 0);
    }

    public function test_list_parameters_cannot_override_authenticated_staff_scope(): void
    {
        $solicitor = $this->staff();
        $other = $this->staff();
        $own = $this->enquiry($solicitor);
        $this->enquiry($other);
        $this->actingAs($solicitor, 'staff_sanctum');
        foreach (['assigned_staff_id', 'staff_id', 'staff_user_id', 'user_id'] as $field) {
            $this->getJson('/api/staff/my-work/enquiries?'.$field.'='.$other->id)->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonMissingPath('data');
        }
        $this->getJson('/api/staff/my-work/enquiries?unassigned=1')->assertUnprocessable();
        foreach (['scope=all', 'filter[assigned_staff_id]='.$other->id, 'assigned_to='.$other->id, 'assigned_staff_id='] as $query) {
            $this->getJson('/api/staff/my-work/enquiries?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('meta.total', 1);
        }
    }

    public function test_safe_filters_search_pagination_and_deterministic_sorting_stay_inside_own_scope(): void
    {
        $solicitor = $this->staff();
        $other = $this->staff();
        $target = $this->enquiry($solicitor, ['reference' => 'A-UNIQUE', 'client_name' => 'Unique Client', 'status' => 'draft', 'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase']);
        $second = $this->enquiry($solicitor, ['reference' => 'B-OTHER']);
        $this->enquiry($other, ['reference' => 'A-UNIQUE-OTHER', 'client_name' => 'Unique Client', 'status' => 'draft', 'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase']);
        $this->enquiry(null, ['client_name' => 'Unique Client']);
        $this->actingAs($solicitor, 'staff_sanctum');
        foreach ([['search' => 'UNIQUE'], ['search' => 'queue@example.com', 'status' => 'draft'], ['status' => 'draft'], ['practice_area' => 'property_law'], ['matter_type' => 'conveyancing_purchase']] as $filters) {
            $this->getJson('/api/staff/my-work/enquiries?'.http_build_query($filters))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)->assertJsonPath('meta.total', 1);
        }
        $target->forceFill(['created_at' => now()->subDay()])->save();
        $this->getJson('/api/staff/my-work/enquiries?sort=created_at&direction=asc&per_page=1')->assertOk()->assertJsonPath('data.0.id', $target->id)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)->assertJsonStructure(['links', 'meta', 'data']);
        $this->getJson('/api/staff/my-work/enquiries?sort=created_at&direction=asc&per_page=1&page=2')->assertJsonPath('data.0.id', $second->id);
        foreach (['sort=assigned_staff_id', 'sort=created_at%20desc%3B%20DROP%20TABLE%20enquiries', 'per_page=101', 'per_page=0', 'direction=invalid'] as $invalid) {
            $this->getJson('/api/staff/my-work/enquiries?'.$invalid)->assertUnprocessable();
        }
        // Equal timestamp ordering remains stable across pages and repeated reads.
        $target->forceFill(['created_at' => '2026-11-10 08:00:00'])->save();
        $second->forceFill(['created_at' => '2026-11-10 08:00:00'])->save();
        $expected = collect([$target->id, $second->id])->sort()->values()->all();
        $this->assertSame($expected, $this->getJson('/api/staff/my-work/enquiries')->assertOk()->json('data.*.id'));
        $this->assertSame($expected, $this->getJson('/api/staff/my-work/enquiries')->assertOk()->json('data.*.id'));
    }

    public function test_assignment_reassignment_and_unassignment_move_work_and_preserve_audit(): void
    {
        $manager = $this->staff('enquiry_team_manager');
        $first = $this->staff();
        $second = $this->staff();
        $enquiry = $this->enquiry();
        $url = '/api/staff/enquiries/'.$enquiry->id.'/assignment';
        $this->assertSame([], $this->ownIds($first));
        $this->actingAs($manager, 'staff_sanctum')->patchJson($url, ['assigned_staff_id' => $first->id])->assertOk();
        $this->assertSame([$enquiry->id], $this->ownIds($first));
        $this->assertSame([], $this->ownIds($second));
        $this->actingAs($manager, 'staff_sanctum')->patchJson($url, ['assigned_staff_id' => $second->id])->assertOk();
        $this->assertSame([], $this->ownIds($first));
        $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertForbidden();
        $this->getJson('/api/staff/my-work/counts')->assertJsonPath('data.all', 0);
        $this->assertSame([$enquiry->id], $this->ownIds($second));
        $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertOk();
        $this->actingAs($manager, 'staff_sanctum')->patchJson($url, ['assigned_staff_id' => null])->assertOk();
        $this->assertSame([], $this->ownIds($second));
        $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertForbidden();
        $this->getJson('/api/staff/my-work/counts')->assertJsonPath('data.all', 0);
        foreach (['enquiry_assigned', 'enquiry_reassigned', 'enquiry_unassigned'] as $type) {
            $event = $enquiry->events()->where('event_type', $type)->sole();
            $this->assertSame($manager->id, $event->performed_by_staff_id);
        }
        $this->assertDatabaseCount('enquiry_events', 3);
    }

    public function test_super_admin_my_work_is_still_own_scoped_and_not_global(): void
    {
        $admin = $this->staff('super_admin');
        $own = $this->enquiry($admin);
        $this->enquiry($this->staff());
        $this->enquiry();
        $this->assertSame([$own->id], $this->ownIds($admin));
        $this->getJson('/api/staff/my-work/counts')->assertJsonPath('data.all', 1);
        $this->getJson('/api/staff/enquiries')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_authentication_active_verification_and_client_token_restrictions_remain_in_force(): void
    {
        $enquiry = $this->enquiry();
        $urls = ['/api/staff/my-work/enquiries', '/api/staff/my-work/counts', '/api/staff/enquiries/'.$enquiry->id];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $client = User::factory()->create();
        $token = $client->createToken('client')->plainTextToken;
        foreach ($urls as $url) {
            $this->withToken($token)->getJson($url)->assertUnauthorized();
        }
        foreach ([['status' => 'deactivated'], ['status' => 'pending'], ['email_verified_at' => null]] as $attributes) {
            $staff = $this->staff(attributes: $attributes);
            $enquiry->update(['assigned_staff_id' => $staff->id]);
            $this->actingAs($staff, 'staff_sanctum');
            foreach ($urls as $url) {
                $this->getJson($url)->assertForbidden();
            }
        }
    }

    public function test_own_detail_requires_permission_not_only_assignment_or_legacy_work_view(): void
    {
        $staff = $this->staff();
        $role = StaffRole::create(['name' => 'legacy_work_viewer']);
        $role->permissions()->sync(StaffPermission::where('name', 'work.view')->pluck('id'));
        $staff->roles()->sync([$role->id]);
        $enquiry = $this->enquiry($staff);
        $this->actingAs($staff, 'staff_sanctum');
        $this->getJson('/api/staff/my-work/enquiries')->assertForbidden();
        $this->getJson('/api/staff/my-work/counts')->assertForbidden();
        $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertForbidden();
    }

    public function test_authorisation_uses_permissions_for_custom_roles_and_combined_roles(): void
    {
        $staff = $this->staff('reporting_only');
        $custom = StaffRole::create(['name' => 'custom_own_worker']);
        $custom->permissions()->sync(StaffPermission::where('name', 'work.view-own')->pluck('id'));
        $staff->roles()->sync([$custom->id]);
        $own = $this->enquiry($staff);
        $other = $this->enquiry($this->staff());
        $this->assertSame([$own->id], $this->ownIds($staff));
        $this->getJson('/api/staff/enquiries/'.$own->id)->assertOk();
        $this->getJson('/api/staff/enquiries/'.$other->id)->assertForbidden();
        $this->getJson('/api/staff/enquiries')->assertForbidden();
        $staff->roles()->attach(StaffRole::where('name', 'super_admin')->sole());
        $this->getJson('/api/staff/enquiries')->assertOk();
        $this->getJson('/api/staff/enquiries/'.$other->id)->assertOk();
    }

    public function test_permission_sync_is_idempotent_revokes_stale_solicitor_global_grants_and_preserves_records(): void
    {
        $solicitor = $this->staff();
        $enquiry = $this->enquiry($solicitor);
        $role = StaffRole::where('name', 'solicitor')->sole();
        $role->permissions()->syncWithoutDetaching(StaffPermission::whereIn('name', ['enquiries.view', 'enquiries.assign', 'enquiries.classify'])->pluck('id'));
        // Simulate a deployment still booted with the previous cached role policy.
        config(['staff_permissions.roles.solicitor' => ['enquiries.view', 'enquiries.assign', 'enquiries.classify']]);
        $migration = require database_path('migrations/2026_11_10_000001_sync_staff_own_work_permissions.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, StaffPermission::where('name', 'work.view-own')->count());
        foreach (['enquiries.view', 'enquiries.assign', 'enquiries.classify'] as $permission) {
            $this->assertFalse($role->permissions()->where('name', $permission)->exists());
        }
        $this->assertTrue($role->permissions()->where('name', 'work.view-own')->exists());
        $this->assertSame($solicitor->id, $enquiry->fresh()->assigned_staff_id);
        $this->assertTrue($solicitor->roles()->whereKey($role->id)->exists());
        $this->actingAs($solicitor, 'staff_sanctum')->getJson('/api/staff/enquiries')->assertForbidden();
        $migration->down();
        $this->getJson('/api/staff/enquiries')->assertForbidden();
    }

    public function test_client_and_legacy_assignment_id_collisions_never_grant_own_work_access(): void
    {
        $solicitor = $this->staff();
        $other = $this->staff();
        $client = User::factory()->create();
        $this->assertSame($solicitor->id, $client->id);
        $enquiry = $this->enquiry($other, ['user_id' => $client->id, 'assigned_to' => $client->id]);
        $this->assertSame([], $this->ownIds($solicitor));
        $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertForbidden();
        $this->getJson('/api/staff/my-work/counts')->assertJsonPath('data.all', 0);
    }
}
