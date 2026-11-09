<?php

namespace Tests\Feature;

use App\Enums\EnquiryStatus;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Enquiry;
use App\Models\EnquiryEvent;
use App\Models\StaffPermission;
use App\Models\StaffRole;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Enquiries\EnquiryEventService;
use App\Services\Enquiries\EnquiryQualificationService;
use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalEnquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StaffRolesAndPermissionsSeeder::class);
    }

    private function staff(string $role = 'super_admin', array $attributes = []): StaffUser
    {
        $staff = StaffUser::create([
            'first_name' => 'Test', 'last_name' => 'Staff', 'email' => Str::uuid().'@example.com',
            'status' => 'active', 'email_verified_at' => now(), ...$attributes,
        ]);
        $staff->roles()->attach(StaffRole::where('name', $role)->sole());

        return $staff;
    }

    private function enquiry(array $attributes = []): Enquiry
    {
        $enquiry = Enquiry::create([
            'reference' => 'TEST-'.Str::uuid(), 'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase',
            'workflow_key' => 'victoria_conversation', 'status' => 'submitted', 'priority' => 'normal',
            'client_name' => 'Test Client', 'client_email' => 'client@example.com', 'client_phone' => '+447700900123',
            'office' => 'Bristol', 'requires_review' => false, 'classification_source' => 'victoria',
            'classification_confidence' => 0.95, 'qualified_at' => now(), 'submitted_at' => now(), 'last_activity_at' => now(),
            ...$attributes,
        ]);
        if (isset($attributes['created_at'])) {
            $enquiry->forceFill(['created_at' => $attributes['created_at']])->save();
        }

        return $enquiry;
    }

    private function linked(Enquiry $enquiry): AiConversation
    {
        $user = User::factory()->create(['contact_number' => '+447700900123']);
        $conversation = AiConversation::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'metadata' => ['secret' => 'CONVERSATION_SECRET']]);
        $conversation->memory()->create([
            'uuid' => (string) Str::uuid(), 'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase',
            'practice_area_confidence' => 0.95, 'intent' => 'instruct_solicitor', 'intent_confidence' => 0.9,
            'summary' => 'Buying a house. Contact details still needed.', 'metadata' => ['api_key' => 'MEMORY_SECRET'],
        ]);
        $enquiry->update(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'assigned_to' => $user->id]);

        return $conversation;
    }

    public function test_all_routes_require_staff_authentication_and_reject_client_tokens(): void
    {
        $enquiry = $this->enquiry();
        $routes = [['get', '/api/staff/enquiries'], ['get', '/api/staff/enquiries/counts'], ['get', "/api/staff/enquiries/{$enquiry->id}"],
            ['patch', "/api/staff/enquiries/{$enquiry->id}/assignment"], ['patch', "/api/staff/enquiries/{$enquiry->id}/classification"]];
        foreach ($routes as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
        $client = User::factory()->create();
        $token = $client->createToken('client')->plainTextToken;
        foreach ($routes as [$method, $url]) {
            $this->withToken($token)->json($method, $url)->assertUnauthorized();
        }
    }

    public function test_basic_staff_cannot_view_or_manage_enquiries(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAs($this->staff('basic'), 'staff_sanctum');
        $this->getJson('/api/staff/enquiries')->assertForbidden();
        $this->getJson('/api/staff/enquiries/counts')->assertForbidden();
        $this->getJson("/api/staff/enquiries/{$enquiry->id}")->assertForbidden();
        $this->patchJson("/api/staff/enquiries/{$enquiry->id}/assignment", ['assigned_staff_id' => null])->assertForbidden();
        $this->patchJson("/api/staff/enquiries/{$enquiry->id}/classification", [])->assertForbidden();
    }

    public function test_view_permission_does_not_grant_mutation_permissions(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAs($this->staff('enquiry_team_member'), 'staff_sanctum');
        $this->getJson('/api/staff/enquiries')->assertOk();
        $this->getJson("/api/staff/enquiries/{$enquiry->id}")->assertOk();
        $this->patchJson("/api/staff/enquiries/{$enquiry->id}/assignment", ['assigned_staff_id' => null])->assertForbidden();
        $this->patchJson("/api/staff/enquiries/{$enquiry->id}/classification", [])->assertForbidden();
    }

    public function test_inactive_or_unverified_actor_cannot_read_or_write(): void
    {
        $enquiry = $this->enquiry();
        foreach ([['status' => 'deactivated'], ['email_verified_at' => null]] as $attributes) {
            $this->actingAs($this->staff(attributes: $attributes), 'staff_sanctum');
            $this->getJson('/api/staff/enquiries')->assertForbidden();
            $this->patchJson("/api/staff/enquiries/{$enquiry->id}/assignment", ['assigned_staff_id' => null])->assertForbidden();
        }
    }

    public function test_permissions_work_for_custom_roles_and_seeding_is_idempotent(): void
    {
        $this->seed(StaffRolesAndPermissionsSeeder::class);
        $this->seed(StaffRolesAndPermissionsSeeder::class);
        $this->assertSame(1, StaffPermission::where('name', 'enquiries.classify')->count());
        foreach (['super_admin', 'enquiry_team_manager'] as $role) {
            $this->assertTrue(StaffRole::where('name', $role)->sole()->permissions()->where('name', 'enquiries.classify')->exists());
        }
        $this->assertFalse(StaffRole::where('name', 'basic')->sole()->permissions()->where('name', 'like', 'enquiries.%')->exists());
        $custom = StaffRole::create(['name' => 'custom_viewer']);
        $custom->permissions()->attach(StaffPermission::where('name', 'enquiries.view')->sole());
        $staff = $this->staff('basic');
        $staff->roles()->sync([$custom->id]);
        $this->actingAs($staff, 'staff_sanctum')->getJson('/api/staff/enquiries')->assertOk();
    }

    public function test_list_is_paginated_and_newest_first_with_explicit_fields(): void
    {
        $old = $this->enquiry(['created_at' => now()->subDay()]);
        $new = $this->enquiry();
        $this->actingAs($this->staff(), 'staff_sanctum')->getJson('/api/staff/enquiries?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $new->id)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'last_page', 'total'], 'data' => [[
                'id', 'reference', 'client_name', 'client_email', 'client_phone', 'practice_area', 'matter_type', 'source', 'workflow_key', 'office',
                'status', 'priority', 'assigned_staff', 'requires_review', 'classification_confidence', 'classification_source', 'handoff_reason',
                'summary', 'received_at', 'created_at', 'submitted_at', 'last_activity_at',
            ]]]);
        $this->getJson('/api/staff/enquiries?per_page=1&page=2')->assertJsonPath('data.0.id', $old->id);
    }

    public function test_search_matches_reference_name_email_and_phone_case_insensitively(): void
    {
        $target = $this->enquiry(['reference' => 'ENQ-UNIQUE-42', 'client_name' => 'Alice Example', 'client_email' => 'alice@unique.test', 'client_phone' => '+441234567890']);
        $this->enquiry();
        $this->actingAs($this->staff(), 'staff_sanctum');
        foreach (['enq-unique', 'ALICE EXAMPLE', 'ALICE@UNIQUE.TEST', '+441234567890'] as $search) {
            $this->getJson('/api/staff/enquiries?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        }
        $this->getJson('/api/staff/enquiries?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_all_supported_filters_and_boolean_false_are_applied(): void
    {
        $assignee = $this->staff();
        $target = $this->enquiry(['status' => 'draft', 'practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation', 'assigned_staff_id' => $assignee->id, 'office' => 'Bath', 'requires_review' => true]);
        $other = $this->enquiry();
        $this->actingAs($this->staff(), 'staff_sanctum');
        foreach ([['status' => 'draft'], ['practice_area' => 'family_law'], ['matter_type' => 'divorce_and_separation'], ['assigned_staff_id' => $assignee->id], ['unassigned' => 0], ['requires_review' => 1], ['office' => 'Bath']] as $filter) {
            $this->getJson('/api/staff/enquiries?'.http_build_query($filter))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        }
        foreach ([['unassigned' => 1], ['requires_review' => 0]] as $filter) {
            $this->getJson('/api/staff/enquiries?'.http_build_query($filter))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
        }
        $this->getJson('/api/staff/enquiries?'.http_build_query(['status' => 'draft', 'office' => 'Bath', 'requires_review' => 1]))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sorting_and_page_limits_are_whitelisted(): void
    {
        $a = $this->enquiry(['reference' => 'A', 'created_at' => now()->subDay()]);
        $b = $this->enquiry(['reference' => 'B']);
        $this->actingAs($this->staff(), 'staff_sanctum');
        $this->getJson('/api/staff/enquiries?sort=reference&direction=asc')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/staff/enquiries?sort=created_at&direction=desc')->assertJsonPath('data.0.id', $b->id);
        foreach ([['sort' => 'password'], ['sort' => 'created_at desc; DROP TABLE enquiries'], ['direction' => 'random'], ['per_page' => 101], ['per_page' => 0], ['page' => 0], ['status' => 'closed'], ['practice_area' => 'property'], ['matter_type' => 'arbitrary'], ['unassigned' => 'yes'], ['assigned_staff_id' => 999999]] as $query) {
            $this->getJson('/api/staff/enquiries?'.http_build_query($query))->assertUnprocessable();
        }
        $this->getJson('/api/staff/enquiries?assigned_staff_id='.$this->staff()->id.'&unassigned=1')->assertUnprocessable();
        $this->assertDatabaseCount('enquiries', 2);
    }

    public function test_counts_are_real_global_and_do_not_invent_closed_or_lost(): void
    {
        $staff = $this->staff();
        $this->enquiry(['requires_review' => true]);
        $this->enquiry(['status' => 'draft', 'requires_review' => true]);
        $this->enquiry(['status' => 'completed', 'assigned_staff_id' => $staff->id]);
        $this->actingAs($staff, 'staff_sanctum')->getJson('/api/staff/enquiries/counts')->assertOk()->assertExactJson([
            'data' => ['all' => 3, 'submitted' => 1, 'unassigned' => 2, 'assigned' => 1, 'requires_review' => 2],
        ]);
    }

    public function test_empty_counts_are_zero(): void
    {
        $this->actingAs($this->staff(), 'staff_sanctum')->getJson('/api/staff/enquiries/counts')->assertOk()->assertExactJson([
            'data' => ['all' => 0, 'submitted' => 0, 'unassigned' => 0, 'assigned' => 0, 'requires_review' => 0],
        ]);
    }

    public function test_detail_returns_safe_related_data_and_chronological_messages(): void
    {
        $staff = $this->staff();
        $enquiry = $this->enquiry(['assigned_staff_id' => $staff->id, 'metadata' => ['api_key' => 'ENQUIRY_SECRET']]);
        $conversation = $this->linked($enquiry);
        $later = AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'Later', 'created_at' => now(), 'metadata' => ['secret' => 'MESSAGE_SECRET']]);
        $earlier = AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Earlier']);
        $earlier->forceFill(['created_at' => now()->subMinute()])->save();
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'system', 'content' => 'SYSTEM_SECRET']);
        $unrelated = AiConversation::create(['uuid' => (string) Str::uuid()]);
        AiMessage::create(['ai_conversation_id' => $unrelated->id, 'role' => 'user', 'content' => 'UNRELATED_SECRET']);
        EnquiryEvent::create(['enquiry_id' => $enquiry->id, 'event_type' => 'answer_recorded', 'metadata' => ['answer' => 'ANSWER_SECRET', 'secret' => 'EVENT_SECRET']]);
        $enquiry->workflow()->create(['workflow_key' => 'will_enquiry_v1', 'status' => 'completed', 'state' => ['secret' => 'WORKFLOW_SECRET']]);
        $response = $this->actingAs($staff, 'staff_sanctum')->getJson('/api/staff/enquiries/'.$enquiry->id)->assertOk()
            ->assertJsonPath('data.assigned_staff.id', $staff->id)->assertJsonPath('data.client_account.id', $enquiry->user_id)
            ->assertJsonPath('data.conversation.messages.0.id', $earlier->id)->assertJsonPath('data.conversation.messages.1.id', $later->id)
            ->assertJsonCount(2, 'data.conversation.messages')->assertJsonPath('data.workflow.status', 'completed')
            ->assertJsonPath('data.events.0.event_type', 'answer_recorded')->assertJsonPath('data.classification.source', 'victoria');
        foreach (['password', 'remember_token', 'access_token', 'ENQUIRY_SECRET', 'CONVERSATION_SECRET', 'MEMORY_SECRET', 'MESSAGE_SECRET', 'SYSTEM_SECRET', 'UNRELATED_SECRET', 'ANSWER_SECRET', 'EVENT_SECRET', 'WORKFLOW_SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_detail_handles_missing_optional_relations_and_unknown_ids(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAs($this->staff(), 'staff_sanctum')->getJson('/api/staff/enquiries/'.$enquiry->id)->assertOk()
            ->assertJsonPath('data.conversation', null)->assertJsonPath('data.client_account', null)->assertJsonPath('data.assigned_staff', null)->assertJsonPath('data.workflow', null);
        $this->getJson('/api/staff/enquiries/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/staff/enquiries/not-a-uuid')->assertNotFound();
    }

    public function test_assignment_reassignment_unassignment_record_actor_and_before_after(): void
    {
        $actor = $this->staff();
        $first = $this->staff('enquiry_team_member');
        $second = $this->staff('solicitor');
        $enquiry = $this->enquiry();
        $this->linked($enquiry);
        $client = $enquiry->user_id;
        $this->actingAs($actor, 'staff_sanctum');
        $previous = null;
        foreach ([[$first->id, 'enquiry_assigned'], [$second->id, 'enquiry_reassigned'], [null, 'enquiry_unassigned']] as [$id, $type]) {
            $this->travel(1)->minutes();
            $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $id])
                ->assertOk()->assertJsonPath('data.assigned_staff_id', $id);
            $event = $enquiry->events()->where('event_type', $type)->sole();
            $this->assertSame($actor->id, $event->performed_by_staff_id);
            $this->assertNull($event->performed_by);
            $this->assertSame(['assigned_staff_id' => $previous], $event->metadata['before']);
            $this->assertSame(['assigned_staff_id' => $id], $event->metadata['after']);
            $this->assertNotNull($event->created_at);
            $this->assertSame($client, $enquiry->fresh()->user_id);
            $this->assertSame($client, $enquiry->fresh()->assigned_to);
            $this->assertSame(now()->toDateTimeString(), $enquiry->fresh()->last_activity_at->toDateTimeString());
            $previous = $id;
        }
        $this->assertDatabaseCount('enquiry_events', 3);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_assignment_rejects_missing_inactive_pending_and_nonexistent_assignees(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAs($this->staff(), 'staff_sanctum');
        foreach ([$this->staff(attributes: ['status' => 'deactivated'])->id, $this->staff(attributes: ['status' => 'pending'])->id, 999999] as $id) {
            $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('assigned_staff_id');
        }
        $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', [])->assertUnprocessable();
        $this->assertNull($enquiry->fresh()->assigned_staff_id);
        $this->assertDatabaseCount('enquiry_events', 0);
    }

    public function test_repeated_assignment_is_a_noop_not_a_second_audit_change(): void
    {
        $actor = $this->staff();
        $enquiry = $this->enquiry();
        $this->actingAs($actor, 'staff_sanctum');
        foreach ([1, 2] as $_) {
            $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $actor->id])->assertOk();
        }
        $this->assertDatabaseCount('enquiry_events', 1);
    }

    public function test_classification_correction_is_audited_and_protected_from_victoria(): void
    {
        $actor = $this->staff('enquiry_team_manager');
        $enquiry = $this->enquiry();
        $conversation = $this->linked($enquiry);
        $this->actingAs($actor, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/classification', [
            'practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation', 'requires_review' => true, 'handoff_reason' => 'staff_review_required',
        ])->assertOk()->assertJsonPath('data.classification_source', 'staff')->assertJsonPath('data.matter_type', 'divorce_and_separation')
            ->assertJsonPath('data.classification_confidence', null);
        $event = $enquiry->events()->where('event_type', 'enquiry_classification_corrected')->sole();
        $this->assertSame($actor->id, $event->performed_by_staff_id);
        $this->assertSame('property_law', $event->metadata['before']['practice_area']);
        $this->assertSame('family_law', $event->metadata['after']['practice_area']);
        $this->assertSame('staff', $event->metadata['after']['classification_source']);
        $this->assertNull($event->performed_by);
        $result = app(EnquiryQualificationService::class)->qualify($conversation, $conversation->memory, ['recommend' => true, 'reason' => 'user_requested_consultation']);
        $this->assertSame('family_law', $result->enquiry->practice_area);
        $this->assertSame('divorce_and_separation', $result->enquiry->matter_type);
        $this->assertSame('staff', $result->enquiry->classification_source);
        $this->assertTrue($result->enquiry->requires_review);
        $this->assertSame('staff_review_required', $result->enquiry->handoff_reason);
        $this->assertFalse($result->qualified);
    }

    public function test_classification_validation_rejects_unknown_and_mismatched_values(): void
    {
        $enquiry = $this->enquiry();
        $this->actingAs($this->staff(), 'staff_sanctum');
        foreach ([['practice_area' => 'property', 'matter_type' => 'conveyancing_purchase', 'requires_review' => false],
            ['practice_area' => 'family_law', 'matter_type' => 'conveyancing_purchase', 'requires_review' => false],
            ['practice_area' => 'property_law', 'matter_type' => 'unknown', 'requires_review' => false],
            ['practice_area' => 'unclassified', 'matter_type' => null, 'requires_review' => false],
            ['practice_area' => 'property_law', 'matter_type' => null, 'requires_review' => true],
            ['practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase', 'requires_review' => false, 'handoff_reason' => 'contact_details_incomplete'],
        ] as $payload) {
            $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/classification', $payload)->assertUnprocessable();
        }
        $this->assertSame('victoria', $enquiry->fresh()->classification_source);
        $this->assertDatabaseCount('enquiry_events', 0);
    }

    public function test_correction_can_mark_unclassified_for_review_and_cannot_clear_missing_contact_review(): void
    {
        $enquiry = $this->enquiry(['client_email' => null, 'client_phone' => null, 'requires_review' => true]);
        $this->actingAs($this->staff(), 'staff_sanctum');
        $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/classification', [
            'practice_area' => 'unclassified', 'matter_type' => null, 'requires_review' => true,
        ])->assertOk()->assertJsonPath('data.handoff_reason', 'classification_requires_review');
        $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/classification', [
            'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase', 'requires_review' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('requires_review');
    }

    public function test_operational_summary_uses_collected_contacts_and_preserves_original_ai_memory(): void
    {
        $enquiry = $this->enquiry(['summary' => ['text' => 'Contact details still needed.']]);
        $conversation = $this->linked($enquiry);
        $original = $conversation->memory->getAttributes();
        $this->actingAs($this->staff(), 'staff_sanctum');
        foreach (['/api/staff/enquiries', '/api/staff/enquiries/'.$enquiry->id] as $url) {
            $response = $this->getJson($url)->assertOk();
            $summary = $response->json(str_ends_with($url, $enquiry->id) ? 'data.summary' : 'data.0.summary');
            $this->assertTrue($summary['contact_details_complete']);
            $this->assertStringContainsString('Contact details available.', $summary['text']);
            $this->assertStringNotContainsString('still needed', $summary['text']);
        }
        $this->assertSame($original, $conversation->memory->fresh()->getAttributes());
        $this->assertSame(['text' => 'Contact details still needed.'], $enquiry->fresh()->summary);
        $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertJsonPath('data.conversation.original_ai_summary', 'Buying a house. Contact details still needed.');
    }

    public function test_event_resource_returns_staff_actor_and_safe_before_after_only(): void
    {
        $enquiry = $this->enquiry();
        $actor = $this->staff();
        $this->actingAs($actor, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $actor->id])->assertOk()
            ->assertJsonPath('data.events.0.actor_staff.id', $actor->id)
            ->assertJsonPath('data.events.0.before.assigned_staff_id', null)
            ->assertJsonPath('data.events.0.after.assigned_staff_id', $actor->id);
        $event = $enquiry->events()->sole();
        $event->update(['metadata' => ['before' => ['assigned_staff_id' => null, 'token' => 'BEFORE_SECRET'], 'after' => ['assigned_staff_id' => $actor->id, 'message' => 'AFTER_SECRET']]]);
        $response = $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertOk();
        $this->assertStringNotContainsString('BEFORE_SECRET', $response->getContent());
        $this->assertStringNotContainsString('AFTER_SECRET', $response->getContent());
    }

    public function test_staff_actor_foreign_key_does_not_reinterpret_client_actor_history(): void
    {
        $enquiry = $this->enquiry();
        $conversation = $this->linked($enquiry);
        $clientEvent = EnquiryEvent::create(['enquiry_id' => $enquiry->id, 'event_type' => 'workflow_started', 'performed_by' => $conversation->user_id]);
        $actor = $this->staff();
        $this->actingAs($actor, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $actor->id])->assertOk();
        $this->assertSame($conversation->user_id, $clientEvent->fresh()->performed_by);
        $this->assertNull($clientEvent->fresh()->performed_by_staff_id);
        $actor->delete();
        $this->assertDatabaseCount('enquiry_events', 2);
        $this->assertNull($enquiry->events()->where('event_type', 'enquiry_assigned')->sole()->performed_by_staff_id);
    }

    public function test_each_existing_canonical_status_can_be_filtered_without_closed_lost_aliases(): void
    {
        $records = [];
        foreach (EnquiryStatus::cases() as $status) {
            $records[$status->value] = $this->enquiry(['status' => $status->value]);
        }
        $this->actingAs($this->staff(), 'staff_sanctum');
        foreach ($records as $status => $record) {
            $this->getJson('/api/staff/enquiries?status='.$status)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
        }
        $this->getJson('/api/staff/enquiries?status=lost')->assertUnprocessable();
    }

    public function test_repeated_classification_correction_is_a_noop_and_ignores_unvalidated_fields(): void
    {
        $enquiry = $this->enquiry();
        $conversation = $this->linked($enquiry);
        $actor = $this->staff();
        $payload = ['practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase', 'requires_review' => false,
            'assigned_to' => 999, 'user_id' => 999, 'assigned_staff_id' => 999, 'classification_source' => 'victoria', 'metadata' => ['secret' => 'INJECTED']];
        $this->actingAs($actor, 'staff_sanctum');
        foreach ([1, 2] as $_) {
            $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/classification', $payload)->assertOk()
                ->assertJsonPath('data.classification_source', 'staff')->assertJsonPath('data.requires_review', false);
        }
        $this->assertSame($conversation->user_id, $enquiry->fresh()->user_id);
        $this->assertSame($conversation->user_id, $enquiry->fresh()->assigned_to);
        $this->assertNull($enquiry->fresh()->assigned_staff_id);
        $this->assertNull($enquiry->fresh()->metadata);
        $this->assertDatabaseCount('enquiry_events', 1);
    }

    public function test_failed_assignment_audit_rolls_back_the_assignment(): void
    {
        $enquiry = $this->enquiry();
        $actor = $this->staff();
        $this->mock(EnquiryEventService::class, function ($mock) {
            $mock->shouldReceive('staffChange')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        });
        $this->actingAs($actor, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/assignment', ['assigned_staff_id' => $actor->id])->assertStatus(500);
        $this->assertNull($enquiry->fresh()->assigned_staff_id);
        $this->assertDatabaseCount('enquiry_events', 0);
    }

    public function test_actor_migration_preserves_historical_client_events(): void
    {
        $migration = require database_path('migrations/2026_11_09_000004_add_staff_actor_to_enquiry_events.php');
        $migration->down();
        $enquiry = $this->enquiry();
        $conversation = $this->linked($enquiry);
        $id = (string) Str::uuid();
        DB::table('enquiry_events')->insert(['id' => $id, 'enquiry_id' => $enquiry->id, 'performed_by' => $conversation->user_id,
            'event_type' => 'workflow_started', 'metadata' => '{"historical":true}', 'created_at' => now()]);
        $before = (array) DB::table('enquiry_events')->where('id', $id)->sole();
        $migration->up();
        $after = (array) DB::table('enquiry_events')->where('id', $id)->sole();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertNull($after['performed_by_staff_id']);
    }

    public function test_assignment_and_classification_permissions_are_independent(): void
    {
        $enquiry = $this->enquiry();
        foreach ([['enquiries.assign', 'assignment', ['assigned_staff_id' => null]],
            ['enquiries.classify', 'classification', ['practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase', 'requires_review' => false]]] as [$permission, $endpoint, $payload]) {
            $role = StaffRole::create(['name' => 'custom_'.$endpoint]);
            $role->permissions()->sync(StaffPermission::whereIn('name', ['enquiries.view', $permission])->pluck('id'));
            $actor = $this->staff('basic');
            $actor->roles()->sync([$role->id]);
            $this->actingAs($actor, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/'.$endpoint, $payload)->assertOk();
            $other = $endpoint === 'assignment' ? 'classification' : 'assignment';
            $this->patchJson('/api/staff/enquiries/'.$enquiry->id.'/'.$other, [])->assertForbidden();
        }
    }
}
