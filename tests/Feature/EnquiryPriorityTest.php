<?php

namespace Tests\Feature;

use App\Enums\EnquiryPriority;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Enquiry;
use App\Models\StaffPermission;
use App\Models\StaffRole;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\ClaudeService;
use App\Services\ConversationContextService;
use App\Services\Enquiries\EnquiryEventService;
use App\Services\Enquiries\EnquiryPriorityService;
use App\Services\Enquiries\EnquiryQualificationService;
use App\Services\Enquiries\EnquiryWorkflowService;
use App\Services\Enquiries\UrgencyDetectionService;
use Carbon\CarbonImmutable;
use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnquiryPriorityTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(array $signals = []): AiConversation
    {
        $user = User::factory()->create(['contact_number' => '+447700900123']);
        $conversation = AiConversation::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id]);
        $conversation->memory()->create([
            'uuid' => (string) Str::uuid(), 'practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation',
            'intent' => 'instruct_solicitor', 'practice_area_confidence' => 0.95, 'intent_confidence' => 0.9,
            'urgency' => ['signals' => $signals],
        ]);

        return $conversation;
    }

    private function signal(string $type = 'explicit_urgent', ?string $deadline = null, ?bool $immediate = true, float $confidence = 0.95): array
    {
        return ['type' => $type, 'confidence' => $confidence, 'immediate' => $immediate, 'deadline_at' => $deadline];
    }

    private function qualify(AiConversation $conversation): Enquiry
    {
        return app(EnquiryQualificationService::class)->qualify($conversation, $conversation->memory()->first(), ['recommend' => true, 'reason' => 'user_requested_consultation'])->enquiry;
    }

    private function staff(string $role = 'super_admin', array $attributes = []): StaffUser
    {
        $staff = StaffUser::create(['first_name' => 'Priority', 'last_name' => 'Manager', 'email' => Str::uuid().'@example.com', 'status' => 'active', 'email_verified_at' => now(), ...$attributes]);
        $staff->roles()->attach(StaffRole::where('name', $role)->sole());

        return $staff;
    }

    public function test_explicit_family_law_urgency_is_extracted_and_creates_one_urgent_enquiry(): void
    {
        $conversation = $this->conversation();
        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('extractConversationContext')->once()->andReturn([
                'practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation', 'intent' => 'instruct_solicitor',
                'practice_area_confidence' => 0.95, 'intent_confidence' => 0.9, 'urgency' => ['signals' => []],
            ]);
            $mock->shouldReceive('chat')->once()->andReturn('Test response');
        });
        $this->actingAs($conversation->user)->postJson('/api/ai/playground/chat', [
            'conversation_id' => $conversation->uuid, 'message' => 'I need a solicitor for my divorce. This is urgent.',
        ])->assertOk();
        $enquiry = $conversation->enquiry()->sole();
        $this->assertSame('urgent', $enquiry->priority);
        $this->assertSame('victoria', $enquiry->priority_source);
        $this->assertSame('explicit_urgent', $enquiry->priority_reason);
        $this->assertSame(1.0, $enquiry->priority_confidence);
        $this->assertNotNull($enquiry->priority_set_at);
        $this->assertNull($enquiry->priority_set_by_staff_id);
        $this->qualify($conversation);
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertSame(1, $enquiry->events()->where('event_type', 'priority_raised')->count());
    }

    public function test_non_urgent_family_enquiry_remains_normal_with_metadata(): void
    {
        $enquiry = $this->qualify($this->conversation());
        $this->assertSame('normal', $enquiry->priority);
        $this->assertSame('victoria', $enquiry->priority_source);
        $this->assertSame('no_reliable_urgency_signal', $enquiry->priority_reason);
        $this->assertSame(0.0, $enquiry->priority_confidence);
        $this->assertDatabaseMissing('enquiry_events', ['event_type' => 'priority_raised']);
    }

    public function test_imminent_material_deadlines_become_urgent(): void
    {
        foreach (['court_deadline', 'hearing_date', 'completion_deadline', 'other_deadline'] as $type) {
            $enquiry = $this->qualify($this->conversation([$this->signal($type, now()->addDay()->toIso8601String(), null)]));
            $this->assertSame('urgent', $enquiry->priority);
            $this->assertSame($type, $enquiry->priority_reason);
        }
    }

    public function test_non_immediate_deadline_is_high(): void
    {
        $enquiry = $this->qualify($this->conversation([$this->signal('court_deadline', now()->addWeeks(2)->toIso8601String(), false)]));
        $this->assertSame('high', $enquiry->priority);
        $this->assertSame('court_deadline', $enquiry->priority_reason);
    }

    public function test_immediate_safety_and_explicit_time_sensitive_circumstances_are_urgent(): void
    {
        foreach (['immediate_safety', 'time_sensitive_circumstance'] as $type) {
            $enquiry = $this->qualify($this->conversation([$this->signal($type)]));
            $this->assertSame('urgent', $enquiry->priority);
        }
    }

    public function test_imminent_medical_issue_is_relevant_to_a_will(): void
    {
        $conversation = $this->conversation([$this->signal('medical_deadline')]);
        $conversation->memory()->update(['practice_area' => 'wills_and_probate', 'matter_type' => 'making_a_will']);
        $enquiry = $this->qualify($conversation);
        $this->assertSame('urgent', $enquiry->priority);
        $this->assertSame('medical_deadline', $enquiry->priority_reason);
        $this->assertSame('normal', $this->qualify($this->conversation([$this->signal('medical_deadline')]))->priority);
    }

    public function test_deadline_window_and_confidence_threshold_are_configurable(): void
    {
        config(['enquiry-priority.imminent_hours' => 12]);
        $this->assertSame('high', $this->qualify($this->conversation([$this->signal('hearing_date', now()->addDay()->toIso8601String())]))->priority);
        config(['enquiry-priority.confidence_threshold' => 0.99]);
        $this->assertSame('normal', $this->qualify($this->conversation([$this->signal()]))->priority);
    }

    public function test_emotion_summary_text_negations_and_hypotheticals_do_not_establish_urgency(): void
    {
        $conversation = $this->conversation();
        $conversation->memory()->update(['summary' => 'The client is upset and desperate. This is urgent.']);
        $this->assertSame('normal', $this->qualify($conversation)->priority);
        $detector = app(UrgencyDetectionService::class);
        foreach (['I am very upset and stressed about my divorce.', 'This is not urgent.', 'If this is urgent what should I do?', 'Is this urgent?', 'I do not think this is urgent', "I don't think this is urgent"] as $message) {
            $this->assertNull($detector->explicitRequest($message));
        }
    }

    public function test_victoria_raises_but_never_lowers_an_existing_priority(): void
    {
        $conversation = $this->conversation([$this->signal('court_deadline', now()->addWeeks(2)->toIso8601String())]);
        $enquiry = $this->qualify($conversation);
        $this->assertSame('high', $enquiry->priority);
        $conversation->memory()->update(['urgency' => ['signals' => []]]);
        $this->assertSame('high', $this->qualify($conversation)->priority);
        $conversation->memory()->update(['urgency' => ['signals' => [$this->signal()]]]);
        $this->assertSame('urgent', $this->qualify($conversation)->priority);
        $conversation->memory()->update(['urgency' => ['signals' => [$this->signal('court_deadline', now()->addWeeks(2)->toIso8601String())]]]);
        $this->assertSame('urgent', $this->qualify($conversation)->priority);
        $this->assertSame(2, $enquiry->events()->where('event_type', 'priority_raised')->count());
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_an_existing_enquiry_can_be_raised_even_without_requalification(): void
    {
        $conversation = $this->conversation();
        $enquiry = $this->qualify($conversation);
        $conversation->memory()->update(['urgency' => ['signals' => [$this->signal()]], 'intent' => null]);
        app(EnquiryQualificationService::class)->qualify($conversation, $conversation->memory, ['recommend' => false]);
        $this->assertSame('urgent', $enquiry->fresh()->priority);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_positive_urgent_will_answer_raises_the_same_enquiry_for_both_keys(): void
    {
        foreach (['urgent_will', 'urgent_will_required'] as $key) {
            config(['enquiry-workflows.workflows.will_enquiry_v1' => [
                'key' => 'will_enquiry_v1', 'name' => 'Priority Test Will', 'practice_area' => 'wills_and_probate', 'version' => 'test', 'first_step' => $key,
                'steps' => [$key => ['key' => $key, 'question_key' => $key, 'question' => 'Urgent Will?', 'type' => 'boolean', 'required' => true, 'next' => null]],
            ]]);
            $conversation = $this->conversation();
            $service = app(EnquiryWorkflowService::class);
            $start = $service->start('wills_and_probate', $conversation->user, $conversation->id);
            $result = $service->submitAnswer($start->enquiry, $key, true, $conversation->user_id);
            $this->assertSame($start->enquiry->id, $result->enquiry->id);
            $this->assertSame('urgent', $result->enquiry->priority);
            $this->assertSame('victoria', $result->enquiry->priority_source);
            $this->assertSame('urgent_will_required', $result->enquiry->priority_reason);
            $this->assertSame(1, $result->enquiry->events()->where('event_type', 'priority_raised')->count());
        }
        $this->assertDatabaseCount('enquiries', 2);
    }

    public function test_negative_will_answer_does_not_raise_priority(): void
    {
        $conversation = $this->conversation();
        $service = app(EnquiryWorkflowService::class);
        $start = $service->start('wills_and_probate', $conversation->user, $conversation->id);
        $result = $service->submitAnswer($start->enquiry, 'urgent_will', false, $conversation->user_id);
        $this->assertSame('normal', $result->enquiry->priority);
        $this->assertDatabaseMissing('enquiry_events', ['event_type' => 'priority_raised']);
    }

    public function test_staff_can_lower_priority_with_a_reason_and_victoria_preserves_it(): void
    {
        $conversation = $this->conversation([$this->signal()]);
        $enquiry = $this->qualify($conversation);
        $actor = $this->staff('enquiry_team_manager');
        $response = $this->actingAs($actor, 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/priority', ['priority' => 'normal', 'reason' => 'Deadline confirmed as non-urgent.']);
        $response->assertOk()->assertJsonPath('data.priority', 'normal')->assertJsonPath('data.priority_source', 'staff')->assertJsonPath('data.priority_confidence', null)->assertJsonPath('data.priority_set_by_staff_id', $actor->id);
        $this->assertNotNull($enquiry->fresh()->priority_set_at);
        $this->assertSame($actor->id, $enquiry->fresh()->prioritySetByStaff->id);
        $this->assertSame('normal', $this->qualify($conversation)->priority);
        app(EnquiryPriorityService::class)->urgentWill($enquiry);
        $this->assertSame('normal', $enquiry->fresh()->priority);
        $event = $enquiry->events()->where('event_type', 'priority_changed_by_staff')->sole();
        $this->assertSame($actor->id, $event->performed_by_staff_id);
        $this->assertSame('urgent', $event->metadata['before']['priority']);
        $this->assertSame('normal', $event->metadata['after']['priority']);
        $this->assertSame('Deadline confirmed as non-urgent.', $event->metadata['after']['priority_reason']);
    }

    public function test_staff_priority_endpoint_rejects_unauthorised_basic_and_client_users(): void
    {
        $conversation = $this->conversation();
        $enquiry = $this->qualify($conversation);
        $url = '/api/staff/enquiries/'.$enquiry->id.'/priority';
        $payload = ['priority' => 'urgent', 'reason' => 'Court deadline today.'];
        $this->patchJson($url, $payload)->assertUnauthorized();
        $token = $conversation->user->createToken('client')->plainTextToken;
        $this->withToken($token)->patchJson($url, $payload)->assertUnauthorized();
        foreach (['basic', 'enquiry_team_member', 'solicitor'] as $role) {
            $this->actingAs($this->staff($role), 'staff_sanctum')->patchJson($url, $payload)->assertForbidden();
        }
        $this->assertSame('normal', $enquiry->fresh()->priority);
    }

    public function test_priority_permission_is_narrow_and_seeded_idempotently(): void
    {
        $this->seed(StaffRolesAndPermissionsSeeder::class);
        $this->seed(StaffRolesAndPermissionsSeeder::class);
        $this->assertSame(1, StaffPermission::where('name', 'enquiries.prioritise')->count());
        foreach (['super_admin', 'enquiry_team_manager'] as $name) {
            $this->assertTrue(StaffRole::where('name', $name)->sole()->permissions()->where('name', 'enquiries.prioritise')->exists());
        }
        $this->assertFalse(StaffRole::where('name', 'basic')->sole()->permissions()->where('name', 'enquiries.prioritise')->exists());
    }

    public function test_staff_endpoint_validates_three_values_and_reason_and_is_idempotent(): void
    {
        $enquiry = $this->qualify($this->conversation());
        $actor = $this->staff();
        $this->actingAs($actor, 'staff_sanctum');
        $url = '/api/staff/enquiries/'.$enquiry->id.'/priority';
        foreach ([['priority' => 'low', 'reason' => 'Reason'], ['priority' => 'critical', 'reason' => 'Reason'], ['priority' => 'urgent'], ['priority' => 'high', 'reason' => '  '], ['priority' => 'high', 'reason' => str_repeat('x', 501)]] as $payload) {
            $this->patchJson($url, $payload)->assertUnprocessable();
        }
        foreach ([1, 2] as $_) {
            $this->patchJson($url, ['priority' => 'high', 'reason' => 'Important deadline.', 'priority_source' => 'victoria', 'priority_set_by_staff_id' => 999])->assertOk()->assertJsonPath('data.priority_source', 'staff')->assertJsonPath('data.priority_set_by_staff_id', $actor->id);
        }
        $this->assertSame(1, $enquiry->events()->where('event_type', 'priority_changed_by_staff')->count());
    }

    public function test_priority_metadata_and_events_are_returned_without_message_content(): void
    {
        $enquiry = $this->qualify($this->conversation([$this->signal()]));
        $actor = $this->staff();
        $this->actingAs($actor, 'staff_sanctum')->getJson('/api/staff/enquiries')->assertOk()->assertJsonPath('data.0.priority', 'urgent')->assertJsonPath('data.0.priority_source', 'victoria')
            ->assertJsonStructure(['data' => [['priority_reason', 'priority_confidence', 'priority_set_at']]]);
        $response = $this->getJson('/api/staff/enquiries/'.$enquiry->id)->assertOk();
        $event = collect($response->json('data.events'))->firstWhere('event_type', 'priority_raised');
        $this->assertSame('normal', $event['before']['priority']);
        $this->assertSame('urgent', $event['after']['priority']);
        $this->assertSame('explicit_urgent', $event['after']['priority_reason']);
        $this->assertArrayNotHasKey('content', $event['after']);
    }

    public function test_malformed_unknown_low_confidence_and_past_deadlines_do_not_raise_priority(): void
    {
        foreach ([$this->signal('unknown'), $this->signal(confidence: 0.3), $this->signal('hearing_date', '2026-02-30', null), $this->signal('court_deadline', now()->subDay()->toIso8601String(), null)] as $signal) {
            $this->assertSame('normal', $this->qualify($this->conversation([$signal]))->priority);
        }
        $this->assertSame(['signals' => []], app(UrgencyDetectionService::class)->normalise('invalid'));
    }

    public function test_actual_claude_extraction_requests_and_persists_structured_urgency(): void
    {
        $conversation = $this->conversation();
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'My hearing is tomorrow.']);
        $deadline = now()->addDay()->toDateString();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation', 'urgency' => ['signals' => [$this->signal('hearing_date', $deadline, null)]],
        ])]]])]);
        $memory = app(ConversationContextService::class)->refresh($conversation, app(ClaudeService::class));
        $this->assertSame('hearing_date', $memory->urgency['signals'][0]['type']);
        $this->assertNotNull($memory->urgency['signals'][0]['deadline_at']);
        Http::assertSent(function ($request) {
            return str_contains($request['system'], 'Emotional language') && str_contains($request['system'], 'Current reference time:')
                && str_contains($request['system'], '"urgency"') && str_contains($request['messages'][0]['content'], '"urgency"');
        });
        $this->assertSame('urgent', $this->qualify($conversation)->priority);
    }

    public function test_direct_workflow_requests_no_longer_accept_low_priority(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($conversation->user)->postJson('/api/v1/enquiries', ['practice_area' => 'wills_and_probate', 'conversation_id' => $conversation->id, 'priority' => 'low'])->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_migration_preserves_existing_enquiry_priority_and_audit(): void
    {
        $migration = require database_path('migrations/2026_11_09_000006_add_enquiry_priority_metadata.php');
        $migration->down();
        $id = (string) Str::uuid();
        $eventId = (string) Str::uuid();
        DB::table('enquiries')->insert(['id' => $id, 'reference' => 'HISTORIC', 'priority' => 'high', 'practice_area' => 'family_law', 'workflow_key' => 'victoria_conversation']);
        DB::table('enquiry_events')->insert(['id' => $eventId, 'enquiry_id' => $id, 'event_type' => 'workflow_started', 'created_at' => now()]);
        $migration->up();
        $this->assertDatabaseHas('enquiries', ['id' => $id, 'priority' => 'high', 'priority_source' => null]);
        $this->assertDatabaseHas('enquiry_events', ['id' => $eventId, 'enquiry_id' => $id]);
    }

    public function test_stated_immediacy_can_be_used_without_inventing_a_deadline_date(): void
    {
        foreach ([true => 'urgent', false => 'high'] as $immediate => $expected) {
            $enquiry = $this->qualify($this->conversation([$this->signal('court_deadline', null, (bool) $immediate)]));
            $this->assertSame($expected, $enquiry->priority);
        }
        $this->assertSame('normal', $this->qualify($this->conversation([$this->signal('court_deadline', null, null)]))->priority);
    }

    public function test_date_only_deadlines_use_configured_timezone_and_imminent_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00Z'));
        $this->assertSame('urgent', $this->qualify($this->conversation([$this->signal('hearing_date', '2026-11-10', null)]))->priority);
        $this->assertSame('urgent', $this->qualify($this->conversation([$this->signal('court_deadline', now()->addHours(72)->toIso8601String(), null)]))->priority);
        $this->assertSame('high', $this->qualify($this->conversation([$this->signal('court_deadline', now()->addHours(73)->toIso8601String(), null)]))->priority);
    }

    public function test_staff_priority_change_requires_its_own_permission_and_active_actor(): void
    {
        $enquiry = $this->qualify($this->conversation());
        $url = '/api/staff/enquiries/'.$enquiry->id.'/priority';
        $payload = ['priority' => 'high', 'reason' => 'Material deadline.'];
        $role = StaffRole::create(['name' => 'custom_priority']);
        $role->permissions()->sync(StaffPermission::whereIn('name', ['enquiries.view', 'enquiries.prioritise'])->pluck('id'));
        $actor = $this->staff('basic');
        $actor->roles()->sync([$role->id]);
        $this->actingAs($actor, 'staff_sanctum')->patchJson($url, $payload)->assertOk();
        $actor->update(['status' => 'deactivated']);
        $this->patchJson($url, ['priority' => 'urgent', 'reason' => 'More urgent.'])->assertForbidden();
        $this->assertSame('high', $enquiry->fresh()->priority);
    }

    public function test_failed_staff_priority_audit_rolls_back_priority_metadata(): void
    {
        $enquiry = $this->qualify($this->conversation());
        $this->mock(EnquiryEventService::class, function ($mock) {
            $mock->shouldReceive('staffChange')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        });
        $this->actingAs($this->staff(), 'staff_sanctum')->patchJson('/api/staff/enquiries/'.$enquiry->id.'/priority', ['priority' => 'urgent', 'reason' => 'Court tomorrow.'])->assertStatus(500);
        $this->assertSame('normal', $enquiry->fresh()->priority);
        $this->assertSame('victoria', $enquiry->fresh()->priority_source);
        $this->assertNull($enquiry->fresh()->priority_set_by_staff_id);
        $this->assertSame(0, $enquiry->events()->where('event_type', 'priority_changed_by_staff')->count());
    }

    public function test_deleted_staff_setter_does_not_release_the_override(): void
    {
        $conversation = $this->conversation();
        $enquiry = $this->qualify($conversation);
        $actor = $this->staff();
        app(EnquiryPriorityService::class)->override($enquiry, EnquiryPriority::Normal, 'Reviewed by staff.', $actor);
        $actor->delete();
        $conversation->memory()->update(['urgency' => ['signals' => [$this->signal()]]]);
        $updated = $this->qualify($conversation);
        $this->assertNull($updated->priority_set_by_staff_id);
        $this->assertSame('staff', $updated->priority_source);
        $this->assertSame('normal', $updated->priority);
    }

    public function test_priority_uses_latest_persisted_memory_and_ignores_wrong_conversation_memory(): void
    {
        $conversation = $this->conversation();
        $enquiry = $this->qualify($conversation);
        $stale = $conversation->memory;
        $conversation->memory()->update(['urgency' => ['signals' => [$this->signal()]]]);
        $this->assertSame('urgent', app(EnquiryPriorityService::class)->fromMemory($enquiry, $stale)->priority);
        $other = $this->conversation();
        $this->expectException(\InvalidArgumentException::class);
        app(EnquiryPriorityService::class)->fromMemory($enquiry, $other->memory);
    }

    public function test_explicit_urgency_is_retained_when_context_extraction_returns_no_data(): void
    {
        $conversation = $this->conversation();
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'This is urgent.']);
        $claude = $this->mock(ClaudeService::class, fn ($mock) => $mock->shouldReceive('extractConversationContext')->once()->andReturn([]));
        $memory = app(ConversationContextService::class)->refresh($conversation, $claude);
        $this->assertSame('explicit_urgent', $memory->urgency['signals'][0]['type']);
        $this->assertSame('urgent', $this->qualify($conversation)->priority);
    }

    public function test_preexisting_positive_will_answer_is_applied_when_the_workflow_completes(): void
    {
        config(['enquiry-workflows.workflows.will_enquiry_v1' => [
            'key' => 'will_enquiry_v1', 'name' => 'Existing Will', 'practice_area' => 'wills_and_probate', 'version' => 'test', 'first_step' => 'confirm',
            'steps' => ['confirm' => ['key' => 'confirm', 'question_key' => 'confirm', 'question' => 'Confirm?', 'type' => 'boolean', 'required' => true, 'next' => null]],
        ]]);
        $conversation = $this->conversation();
        $service = app(EnquiryWorkflowService::class);
        $start = $service->start('wills_and_probate', $conversation->user, $conversation->id);
        $start->enquiry->answers()->create([
            'workflow_id' => $start->workflow->id, 'step_key' => 'urgent_will_required', 'question_key' => 'urgent_will_required',
            'question_text' => 'Urgent Will?', 'answer_type' => 'boolean', 'answer' => ['value' => true],
            'normalised_answer' => ['value' => true], 'answered_at' => now()->subDay(),
        ]);
        $result = $service->submitAnswer($start->enquiry, 'confirm', true);
        $this->assertSame($start->enquiry->id, $result->enquiry->id);
        $this->assertSame('urgent', $result->enquiry->priority);
        $this->assertSame('urgent_will_required', $result->enquiry->priority_reason);
        $this->assertSame(1, $result->enquiry->events()->where('event_type', 'priority_raised')->count());
        $this->assertDatabaseCount('enquiries', 1);
    }
}
