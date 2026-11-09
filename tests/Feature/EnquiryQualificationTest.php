<?php

namespace Tests\Feature;

use App\Data\Enquiries\QualificationResult;
use App\Enums\PracticeArea;
use App\Models\AiConversation;
use App\Models\AiConversationMemory;
use App\Models\Enquiry;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\ClaudeService;
use App\Services\Enquiries\EnquiryCreationService;
use App\Services\Enquiries\EnquiryQualificationService;
use App\Services\Enquiries\EnquiryWorkflowService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnquiryQualificationTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(array $userAttributes = []): AiConversation
    {
        $user = User::factory()->create(['contact_number' => '+447700900123', ...$userAttributes]);

        return AiConversation::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => 'active',
            'metadata' => ['office' => 'Bath', 'service' => 'Conveyancing'],
        ]);
    }

    private function memory(AiConversation $conversation, array $attributes = []): AiConversationMemory
    {
        return $conversation->memory()->create([
            'uuid' => (string) Str::uuid(), 'practice_area' => 'property_law',
            'matter_type' => 'property_purchase', 'intent' => 'seek_legal_help',
            'intent_confidence' => 0.95, 'practice_area_confidence' => 0.95,
            'summary' => 'Structured test summary', ...$attributes,
        ]);
    }

    private function qualify(AiConversation $conversation, AiConversationMemory $memory): QualificationResult
    {
        return app(EnquiryQualificationService::class)->qualify($conversation, $memory, [
            'recommend' => true, 'reason' => 'user_requested_consultation',
        ]);
    }

    public function test_staging_unclassified_enquiry_recovers_from_latest_persisted_memory(): void
    {
        $conversation = $this->conversation();
        $staleMemory = $this->memory($conversation, ['practice_area' => 'general', 'matter_type' => null, 'intent' => null]);
        $enquiry = Enquiry::create([
            'conversation_id' => $conversation->id, 'user_id' => $conversation->user_id,
            'reference' => 'STAGING-REGRESSION', 'workflow_key' => 'victoria_conversation',
            'practice_area' => 'unclassified', 'matter_type' => null,
            'classification_confidence' => 0.95, 'classification_source' => 'victoria',
            'requires_review' => true, 'handoff_reason' => 'classification_requires_review',
            'client_name' => 'Collected Name', 'client_email' => 'collected@example.com', 'client_phone' => '+447700900999',
        ]);
        $this->travel(1)->minutes();
        AiConversationMemory::whereKey($staleMemory->id)->update([
            'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase',
            'practice_area_confidence' => 0.95, 'intent' => 'instruct_solicitor', 'intent_confidence' => 0.90,
            'conversation_stage' => 'consultation_ready', 'consultation_recommended' => true,
        ]);
        $this->assertTrue($staleMemory->fresh()->updated_at->greaterThan($enquiry->updated_at));
        $service = app(EnquiryQualificationService::class);
        $result = $service->qualify($conversation, $staleMemory, []);
        $this->assertTrue($result->qualified);
        $this->assertSame($enquiry->id, $result->enquiry->id);
        $this->assertSame('property_law', $result->enquiry->practice_area);
        $this->assertSame('conveyancing_purchase', $result->enquiry->matter_type);
        $this->assertSame(0.95, $result->enquiry->classification_confidence);
        $this->assertSame('victoria', $result->enquiry->classification_source);
        $this->assertFalse($result->enquiry->requires_review);
        $this->assertNotNull($result->enquiry->qualified_at);
        $this->assertNotNull($result->enquiry->submitted_at);
        $service->qualify($conversation, $staleMemory, []);
        $this->assertDatabaseCount('enquiries', 1);
        foreach (['enquiry_classified', 'enquiry_qualified', 'enquiry_submitted', 'enquiry_ready_for_assignment'] as $type) {
            $this->assertSame(1, $enquiry->events()->where('event_type', $type)->count());
        }
    }

    public function test_ordinary_qualified_conversation_populates_one_enquiry_and_events(): void
    {
        $conversation = $this->conversation();
        $result = $this->qualify($conversation, $this->memory($conversation));
        $this->assertTrue($result->qualified);
        $this->assertSame(PracticeArea::Property, $result->practiceArea);
        $enquiry = $result->enquiry;
        $this->assertSame($conversation->user->name, $enquiry->client_name);
        $this->assertSame($conversation->user->email, $enquiry->client_email);
        $this->assertSame('+447700900123', $enquiry->client_phone);
        $this->assertSame('Bath', $enquiry->office);
        $this->assertSame('Conveyancing', $enquiry->recommended_service);
        $this->assertSame('buying_a_property', $enquiry->matter_type);
        $this->assertSame(['text' => 'Structured test summary'], $enquiry->summary);
        $this->assertFalse($enquiry->requires_review);
        $this->assertNotNull($enquiry->qualified_at);
        $this->assertNotNull($enquiry->submitted_at);
        $this->assertNull($enquiry->assigned_staff_id);
        $this->assertDatabaseCount('enquiries', 1);
        foreach (['enquiry_qualified', 'enquiry_submitted', 'enquiry_ready_for_assignment'] as $type) {
            $this->assertDatabaseHas('enquiry_events', ['enquiry_id' => $enquiry->id, 'event_type' => $type]);
        }
    }

    public function test_repeated_qualification_reuses_record_timestamps_and_events(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation);
        $first = $this->qualify($conversation, $memory)->enquiry;
        $this->travel(5)->minutes();
        $second = $this->qualify($conversation, $memory)->enquiry;
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($first->qualified_at->equalTo($second->qualified_at));
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertDatabaseCount('enquiry_events', 3);
    }

    public function test_low_confidence_is_unclassified_and_not_submitted(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['practice_area_confidence' => 0.4]);
        $result = $this->qualify($conversation, $memory);
        $this->assertFalse($result->qualified);
        $this->assertSame('unclassified', $result->enquiry->practice_area);
        $this->assertNull($result->enquiry->matter_type);
        $this->assertTrue($result->enquiry->requires_review);
        $this->assertNull($result->enquiry->qualified_at);
        $this->assertNull($result->enquiry->submitted_at);
        $this->assertSame('qualification_confidence_too_low', $result->enquiry->handoff_reason);
        $this->assertDatabaseHas('enquiry_events', ['event_type' => 'enquiry_qualification_requires_review']);
        $this->assertDatabaseMissing('enquiry_events', ['event_type' => 'enquiry_ready_for_assignment']);
    }

    public function test_confidence_threshold_comes_from_configuration(): void
    {
        config(['enquiries.classification_confidence_threshold' => 0.99]);
        $conversation = $this->conversation();
        $this->assertFalse($this->qualify($conversation, $this->memory($conversation))->qualified);
    }

    public function test_unknown_or_conflicting_matter_type_requires_review(): void
    {
        foreach (['something_unknown', 'divorce'] as $matter) {
            $conversation = $this->conversation();
            $result = $this->qualify($conversation, $this->memory($conversation, ['matter_type' => $matter]));
            $this->assertSame(PracticeArea::Property, $result->practiceArea);
            $this->assertNull($result->matterType);
            $this->assertTrue($result->requiresReview);
            $this->assertSame('draft', $result->enquiry->status);
            $this->assertSame('information_gathering', $result->reason);
        }
    }

    public function test_ai_completed_label_alone_and_casual_chat_never_qualify(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['conversation_stage' => 'completed', 'intent' => 'general_information']);
        $service = app(EnquiryQualificationService::class);
        $this->assertFalse($service->qualify($conversation, $memory, ['recommend' => false])->shouldPersist);
        $this->assertFalse($service->qualify($conversation, $memory, ['recommend' => true, 'reason' => 'sufficient_information_gathered'])->shouldPersist);
        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_missing_contact_or_name_cannot_qualify(): void
    {
        foreach ([['email' => 'invalid', 'contact_number' => null], ['name' => ' ']] as $attributes) {
            $conversation = $this->conversation($attributes);
            $result = $this->qualify($conversation, $this->memory($conversation));
            $this->assertFalse($result->qualified);
            $this->assertFalse($result->shouldPersist);
        }
        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_valid_phone_is_a_contact_channel_when_email_is_invalid(): void
    {
        $conversation = $this->conversation(['email' => 'invalid']);
        $this->assertTrue($this->qualify($conversation, $this->memory($conversation))->qualified);
    }

    public function test_qualified_intent_needs_configured_confidence_without_explicit_request(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['intent_confidence' => 0.3]);
        $service = app(EnquiryQualificationService::class);
        $consultation = ['recommend' => true, 'reason' => 'sufficient_information_gathered'];
        $this->assertFalse($service->qualify($conversation, $memory, $consultation)->shouldPersist);
        $memory->update(['intent_confidence' => 0.95]);
        $this->assertTrue($service->qualify($conversation, $memory, $consultation)->qualified);
    }

    public function test_database_unique_constraint_rejects_duplicate_conversations(): void
    {
        $conversation = $this->conversation();
        $first = $this->qualify($conversation, $this->memory($conversation))->enquiry;
        $this->expectException(UniqueConstraintViolationException::class);
        Enquiry::create([
            'conversation_id' => $conversation->id, 'reference' => $first->reference.'-duplicate',
            'practice_area' => 'property_law', 'workflow_key' => 'victoria_conversation',
        ]);
    }

    public function test_creation_service_reloads_existing_enquiry_without_overwriting_history(): void
    {
        $conversation = $this->conversation();
        $first = $this->qualify($conversation, $this->memory($conversation))->enquiry;
        $second = app(EnquiryCreationService::class)->getOrCreate($conversation->id, ['practice_area' => 'family_law', 'workflow_key' => 'other']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->reference, $second->reference);
        $this->assertSame('property_law', $second->practice_area);
        $this->assertDatabaseCount('enquiry_events', 3);
    }

    public function test_multiple_null_conversation_ids_remain_allowed(): void
    {
        foreach ([1, 2] as $number) {
            Enquiry::create(['reference' => "LEGACY-$number", 'practice_area' => 'legacy_value', 'workflow_key' => 'legacy']);
        }
        $this->assertDatabaseCount('enquiries', 2);
    }

    public function test_staff_assignment_uses_staff_users_and_preserves_legacy_assignment(): void
    {
        $conversation = $this->conversation();
        $enquiry = $this->qualify($conversation, $this->memory($conversation))->enquiry;
        $staff = StaffUser::create(['first_name' => 'Test', 'last_name' => 'Staff', 'email' => 'staff@example.com']);
        $enquiry->update(['assigned_staff_id' => $staff->id, 'assigned_to' => $conversation->user_id]);
        $this->assertTrue($enquiry->assignedStaff->is($staff));
        $this->assertTrue($staff->assignedEnquiries->sole()->is($enquiry));
        $this->assertTrue($enquiry->assignedUser->is($conversation->user));
        $staff->delete();
        $this->assertNull($enquiry->fresh()->assigned_staff_id);
        $this->assertSame($conversation->user_id, $enquiry->fresh()->assigned_to);
    }

    public function test_staff_classification_and_assignment_survive_ai_refresh(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation);
        $enquiry = $this->qualify($conversation, $memory)->enquiry;
        $staff = StaffUser::create(['first_name' => 'Test', 'last_name' => 'Staff', 'email' => 'staff@example.com']);
        $enquiry->update(['classification_source' => 'staff', 'practice_area' => 'family_law', 'matter_type' => 'divorce_and_separation', 'assigned_staff_id' => $staff->id]);
        $memory->update(['practice_area_confidence' => 0.1]);
        $result = $this->qualify($conversation, $memory);
        $this->assertSame('staff', $result->enquiry->classification_source);
        $this->assertSame('family_law', $result->enquiry->practice_area);
        $this->assertSame('divorce_and_separation', $result->enquiry->matter_type);
        $this->assertSame($staff->id, $result->enquiry->assigned_staff_id);
    }

    public function test_all_canonical_matter_aliases_map_to_their_practice_area(): void
    {
        foreach (config('enquiries.matter_types') as $matter => $definition) {
            $conversation = $this->conversation();
            $memory = $this->memory($conversation, ['practice_area' => $definition['practice_area'], 'matter_type' => $definition['aliases'][0]]);
            $result = $this->qualify($conversation, $memory);
            $this->assertSame($matter, $result->matterType);
            $this->assertSame($definition['practice_area'], $result->enquiry->practice_area);
        }
    }

    public function test_unknown_practice_area_with_legal_intent_goes_to_review(): void
    {
        $conversation = $this->conversation();
        $result = $this->qualify($conversation, $this->memory($conversation, ['practice_area' => 'some_variant', 'matter_type' => null]));
        $this->assertTrue($result->shouldPersist);
        $this->assertSame('unclassified', $result->enquiry->practice_area);
        $this->assertTrue($result->enquiry->requires_review);
    }

    public function test_review_record_can_be_qualified_without_replacing_it_or_its_audit(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['practice_area_confidence' => 0.4]);
        $first = $this->qualify($conversation, $memory)->enquiry;
        $memory->update(['practice_area_confidence' => 0.95]);
        $second = $this->qualify($conversation, $memory)->enquiry;
        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->requires_review);
        $this->assertDatabaseCount('enquiry_events', 5);
    }

    public function test_enquiries_without_questionnaire_workflows_are_readable(): void
    {
        $conversation = $this->conversation();
        $enquiry = $this->qualify($conversation, $this->memory($conversation))->enquiry;
        $this->actingAs($conversation->user)->getJson('/api/v1/enquiries/'.$enquiry->id)
            ->assertOk()->assertJsonPath('data.enquiry.id', $enquiry->id)->assertJsonPath('data.workflow', null);
    }

    public function test_different_legal_matter_must_use_a_separate_conversation(): void
    {
        $conversation = $this->conversation();
        $this->qualify($conversation, $this->memory($conversation));
        $this->actingAs($conversation->user)->postJson('/api/v1/enquiries', [
            'practice_area' => 'wills_and_probate', 'conversation_id' => $conversation->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertDatabaseCount('enquiry_workflows', 0);
    }

    public function test_additive_migration_preserves_existing_enquiry_and_audit_fields(): void
    {
        $migration = require database_path('migrations/2026_11_09_000001_add_operational_fields_to_enquiries.php');
        $migration->down();
        $id = (string) Str::uuid();
        $event = (string) Str::uuid();
        DB::table('enquiries')->insert([
            'id' => $id, 'reference' => 'PRE-PHASE-ONE', 'practice_area' => 'legacy_variant',
            'workflow_key' => 'will_enquiry_v1', 'status' => 'completed', 'summary' => '{"legacy":true}',
        ]);
        DB::table('enquiry_events')->insert([
            'id' => $event, 'enquiry_id' => $id, 'event_type' => 'workflow_completed', 'created_at' => now(),
        ]);
        $before = (array) DB::table('enquiries')->where('id', $id)->first();
        $migration->up();
        $after = (array) DB::table('enquiries')->where('id', $id)->first();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertDatabaseHas('enquiry_events', ['id' => $event, 'enquiry_id' => $id]);
        $this->assertNull($after['assigned_staff_id']);
    }

    public function test_collected_operational_contacts_are_retained_when_account_details_are_missing(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation);
        $first = $this->qualify($conversation, $memory)->enquiry;
        $conversation->user->update(['email' => 'invalid', 'contact_number' => null]);
        $result = $this->qualify($conversation, $memory);
        $this->assertSame($first->id, $result->enquiry->id);
        $this->assertTrue($result->qualified);
        $this->assertFalse($result->enquiry->requires_review);
        $this->assertSame($first->client_email, $result->enquiry->client_email);
        $this->assertSame($first->client_phone, $result->enquiry->client_phone);
        $this->assertNotNull($result->enquiry->submitted_at);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_missing_contact_has_its_own_review_reason_and_preserves_correct_classification(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['matter_type' => 'conveyancing_purchase']);
        $enquiry = $this->qualify($conversation, $memory)->enquiry;
        $conversation->user->update(['email' => 'invalid', 'contact_number' => null]);
        $enquiry->update(['client_email' => null, 'client_phone' => null]);
        $result = $this->qualify($conversation, $memory);
        $this->assertFalse($result->qualified);
        $this->assertSame('contact_details_incomplete', $result->reason);
        $this->assertSame('property_law', $result->enquiry->practice_area);
        $this->assertSame('conveyancing_purchase', $result->enquiry->matter_type);
        $this->assertTrue($result->enquiry->requires_review);
        $this->assertNotNull($result->enquiry->submitted_at);
        $this->assertDatabaseMissing('enquiry_events', ['event_type' => 'classification_requires_review']);
        $this->assertDatabaseHas('enquiry_events', ['event_type' => 'enquiry_qualification_requires_review']);
    }

    public function test_low_intent_confidence_has_its_own_reason_on_an_existing_enquiry(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['intent' => 'instruct_solicitor', 'matter_type' => 'conveyancing_purchase']);
        $enquiry = $this->qualify($conversation, $memory)->enquiry;
        $memory->update(['intent_confidence' => 0.2]);
        $result = app(EnquiryQualificationService::class)->qualify($conversation, $memory, ['recommend' => true]);
        $this->assertFalse($result->qualified);
        $this->assertSame('qualification_confidence_too_low', $result->reason);
        $this->assertSame('property_law', $result->enquiry->practice_area);
        $this->assertSame('conveyancing_purchase', $result->enquiry->matter_type);
        $this->assertSame($enquiry->id, $result->enquiry->id);
    }

    public function test_unrecognised_classification_has_classification_review_reason(): void
    {
        $conversation = $this->conversation();
        $result = $this->qualify($conversation, $this->memory($conversation, ['practice_area' => 'unknown', 'matter_type' => 'unknown']));
        $this->assertSame('classification_requires_review', $result->reason);
        $this->assertDatabaseHas('enquiry_events', ['event_type' => 'classification_requires_review']);
    }

    public function test_memory_contacts_merge_with_account_details_and_are_saved_before_readiness(): void
    {
        $conversation = $this->conversation(['email' => 'invalid', 'contact_number' => null]);
        $memory = $this->memory($conversation, ['entities' => ['contact_details' => ['email' => 'derived@example.com', 'phone' => '+447700900555']]]);
        $result = $this->qualify($conversation, $memory);
        $this->assertTrue($result->qualified);
        $this->assertSame($conversation->user->name, $result->enquiry->client_name);
        $this->assertSame('derived@example.com', $result->enquiry->client_email);
        $this->assertSame('+447700900555', $result->enquiry->client_phone);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_account_contacts_win_over_derived_contacts_without_repetition_in_chat(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['entities' => ['name' => 'Derived Name', 'email' => 'derived@example.com', 'phone' => '+447700900555']]);
        // A cached user relationship must not conceal current account details.
        $conversation->load('user');
        User::whereKey($conversation->user_id)->update(['name' => 'Current Account Name']);
        $result = $this->qualify($conversation, $memory);
        $this->assertTrue($result->qualified);
        $this->assertSame('Current Account Name', $result->enquiry->client_name);
        $this->assertSame($conversation->user->email, $result->enquiry->client_email);
        $this->assertSame('+447700900123', $result->enquiry->client_phone);
    }

    public function test_fresh_victoria_memory_cannot_overwrite_a_concurrent_staff_correction(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation);
        $enquiry = $this->qualify($conversation, $memory)->enquiry;
        $conversation->load('enquiry');
        $corrected = [
            'classification_source' => 'staff', 'practice_area' => 'family_law',
            'matter_type' => 'staff_specific_family_matter', 'classification_confidence' => 0.75,
        ];
        Enquiry::whereKey($enquiry->id)->update($corrected);
        $memory->update(['matter_type' => 'conveyancing_purchase', 'practice_area_confidence' => 0.99]);
        $result = $this->qualify($conversation, $memory);
        foreach ($corrected as $field => $value) {
            $this->assertSame($value, $result->enquiry->{$field});
        }
        $this->assertTrue($result->qualified);
        $this->assertSame('staff', $result->source);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_unsaved_memory_changes_do_not_override_persisted_classification(): void
    {
        $conversation = $this->conversation();
        $memory = $this->memory($conversation, ['matter_type' => 'conveyancing_purchase']);
        $memory->practice_area = 'family_law';
        $memory->matter_type = 'divorce';
        $result = $this->qualify($conversation, $memory);
        $this->assertSame('property_law', $result->enquiry->practice_area);
        $this->assertSame('conveyancing_purchase', $result->enquiry->matter_type);
    }

    public function test_chat_qualifies_only_after_context_and_consultation_state_are_persisted(): void
    {
        $conversation = $this->conversation();
        $this->memory($conversation, ['practice_area' => 'general', 'matter_type' => null]);
        $service = app(EnquiryQualificationService::class);
        $this->mock(EnquiryQualificationService::class, function ($mock) use ($service) {
            $mock->shouldReceive('qualify')->once()->andReturnUsing(function ($conversation, $memory, $consultation) use ($service) {
                $persisted = $memory->fresh();
                $this->assertSame('property_law', $persisted->practice_area);
                $this->assertSame('conveyancing_purchase', $persisted->matter_type);
                $this->assertSame('consultation_ready', $persisted->conversation_stage);
                $this->assertTrue($persisted->consultation_recommended);
                $this->assertTrue($persisted->metadata['last_consultation_result']['recommend']);
                $this->assertFalse($memory->isDirty());

                return $service->qualify($conversation, $memory, $consultation);
            });
        });
        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('extractConversationContext')->once()->andReturn([
                'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase',
                'practice_area_confidence' => 0.95, 'intent' => 'instruct_solicitor', 'intent_confidence' => 0.90,
                'conversation_stage' => 'information_gathering',
            ]);
            $mock->shouldReceive('chat')->once()->andReturn('Test reply');
        });
        $this->actingAs($conversation->user)->postJson('/api/ai/playground/chat', [
            'message' => 'I am buying a property. Please contact me.', 'conversation_id' => $conversation->uuid,
        ])->assertOk();
        $this->assertDatabaseHas('enquiries', ['conversation_id' => $conversation->id, 'practice_area' => 'property_law', 'matter_type' => 'conveyancing_purchase', 'requires_review' => false]);
    }

    private function finalWillStep(): void
    {
        config(['enquiry-workflows.workflows.will_enquiry_v1' => [
            'key' => 'will_enquiry_v1', 'name' => 'Test Will', 'practice_area' => 'wills_and_probate', 'version' => 'test', 'first_step' => 'confirm',
            'steps' => ['confirm' => ['key' => 'confirm', 'question_key' => 'confirm', 'question' => 'Confirm?', 'type' => 'boolean', 'required' => true, 'next' => null]],
        ]]);
    }

    public function test_will_completion_reuses_existing_enquiry_and_populates_operational_data(): void
    {
        $this->finalWillStep();
        $conversation = $this->conversation();
        $this->memory($conversation, ['matter_type' => 'will', 'practice_area' => 'wills_and_probate']);
        $service = app(EnquiryWorkflowService::class);
        $start = $service->start('wills_and_probate', $conversation->user, $conversation->id);
        $this->assertSame('+447700900123', $start->enquiry->client_phone);
        $conversation->user->update(['name' => 'Updated Name', 'contact_number' => '+447700900456']);
        $result = $service->submitAnswer($start->enquiry, 'confirm', true, $conversation->user_id);
        $this->assertTrue($result->completed);
        $this->assertSame($start->enquiry->id, $result->enquiry->id);
        $this->assertSame('Updated Name', $result->enquiry->client_name);
        $this->assertSame('+447700900456', $result->enquiry->client_phone);
        $this->assertSame('Bath', $result->enquiry->office);
        $this->assertSame('wills_and_probate', $result->enquiry->practice_area);
        $this->assertSame('making_a_will', $result->enquiry->matter_type);
        $this->assertSame(['text' => 'Structured test summary'], $result->enquiry->summary);
        $this->assertSame('workflow', $result->enquiry->classification_source);
        $this->assertNotNull($result->enquiry->submitted_at);
        $this->assertNotNull($result->workflow->last_activity_at);
        $this->assertNull($result->enquiry->assigned_staff_id);
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertDatabaseHas('enquiry_events', ['enquiry_id' => $start->enquiry->id, 'event_type' => 'enquiry_ready_for_assignment']);
    }

    public function test_will_without_contact_completes_questionnaire_but_is_not_ready(): void
    {
        $this->finalWillStep();
        $conversation = $this->conversation(['email' => 'invalid', 'contact_number' => null]);
        $service = app(EnquiryWorkflowService::class);
        $start = $service->start('wills_and_probate', $conversation->user, $conversation->id);
        $result = $service->submitAnswer($start->enquiry, 'confirm', true);
        $this->assertTrue($result->completed);
        $this->assertTrue($result->enquiry->requires_review);
        $this->assertSame('contact_details_incomplete', $result->enquiry->handoff_reason);
        $this->assertNull($result->enquiry->qualified_at);
        $this->assertNull($result->enquiry->submitted_at);
        $this->assertDatabaseMissing('enquiry_events', ['event_type' => 'enquiry_ready_for_assignment']);
    }

    public function test_active_will_is_not_prematurely_submitted_by_ordinary_qualification(): void
    {
        $conversation = $this->conversation();
        app(EnquiryWorkflowService::class)->start('wills_and_probate', $conversation->user, $conversation->id);
        $result = $this->qualify($conversation, $this->memory($conversation));
        $this->assertSame('workflow_in_progress', $result->reason);
        $this->assertNull($result->enquiry->submitted_at);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_repeated_direct_posts_and_victoria_share_the_existing_enquiry(): void
    {
        $conversation = $this->conversation();
        $enquiry = $this->qualify($conversation, $this->memory($conversation, ['practice_area' => 'wills_and_probate', 'matter_type' => 'will']))->enquiry;
        $payload = ['practice_area' => 'wills_and_probate', 'conversation_id' => $conversation->id];
        $first = $this->actingAs($conversation->user)->postJson('/api/v1/enquiries', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/enquiries', $payload)->assertCreated();
        $first->assertJsonPath('data.enquiry.id', $enquiry->id);
        $second->assertJsonPath('data.enquiry.id', $enquiry->id);
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertDatabaseCount('enquiry_workflows', 1);
        $this->assertSame(1, $enquiry->events()->where('event_type', 'workflow_started')->count());
    }

    public function test_direct_post_cannot_attach_another_users_conversation(): void
    {
        $conversation = $this->conversation();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/v1/enquiries', [
            'practice_area' => 'wills_and_probate', 'conversation_id' => $conversation->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('conversation_id');
        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_existing_enquiries_remain_readable_with_new_nullable_fields(): void
    {
        $conversation = $this->conversation();
        $result = app(EnquiryWorkflowService::class)->start('wills_and_probate', $conversation->user, $conversation->id);
        $result->enquiry->update(['assigned_to' => $conversation->user_id]);
        $this->actingAs($conversation->user)->getJson('/api/v1/enquiries/'.$result->enquiry->id)
            ->assertOk()->assertJsonPath('data.enquiry.id', $result->enquiry->id);
        $this->assertNull($result->enquiry->fresh()->assigned_staff_id);
    }

    public function test_unique_migration_fails_clearly_without_deleting_duplicate_records(): void
    {
        $migration = require database_path('migrations/2026_11_09_000002_add_unique_conversation_to_enquiries.php');
        $migration->down();
        $conversation = $this->conversation();
        foreach ([1, 2] as $number) {
            Enquiry::create(['conversation_id' => $conversation->id, 'reference' => "DUP-$number", 'practice_area' => 'wills_and_probate', 'workflow_key' => 'will_enquiry_v1']);
        }
        try {
            $migration->up();
            $this->fail('Expected a duplicate-data migration failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('preserving records and audit history', $exception->getMessage());
            $this->assertStringContainsString((string) $conversation->id, $exception->getMessage());
            $this->assertDatabaseCount('enquiries', 2);
        }
    }

    public function test_ordinary_chat_integrates_qualification_after_context_evaluation(): void
    {
        $conversation = $this->conversation();
        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('extractConversationContext')->once()->andReturn([
                'practice_area' => 'property_law', 'matter_type' => 'property_purchase', 'intent' => 'seek_legal_help',
                'practice_area_confidence' => 0.95, 'intent_confidence' => 0.95, 'summary' => 'Structured chat summary',
            ]);
            $mock->shouldReceive('chat')->once()->andReturn('Test reply');
        });
        $this->actingAs($conversation->user)->postJson('/api/ai/playground/chat', [
            'message' => 'I am buying a property. Please contact me.', 'conversation_id' => $conversation->uuid, 'office' => 'Bristol',
        ])->assertOk();
        $this->assertDatabaseHas('enquiries', ['conversation_id' => $conversation->id, 'office' => 'Bristol', 'matter_type' => 'buying_a_property', 'status' => 'submitted']);
    }

    public function test_will_chat_preserves_office_and_repeated_start_does_not_duplicate(): void
    {
        $this->finalWillStep();
        $conversation = $this->conversation();
        $this->actingAs($conversation->user)->postJson('/api/ai/playground/chat', [
            'message' => 'I need a will', 'conversation_id' => $conversation->uuid, 'office' => 'Bristol',
        ])->assertOk()->assertJsonPath('interaction.type', 'workflow_started');
        $this->postJson('/api/ai/playground/chat', ['message' => 'yes', 'conversation_id' => $conversation->uuid])
            ->assertOk()->assertJsonPath('interaction.type', 'workflow_completed');
        $this->postJson('/api/ai/playground/chat', ['message' => 'I need a will', 'conversation_id' => $conversation->uuid])
            ->assertOk()->assertJsonPath('interaction.type', 'workflow_completed');
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertDatabaseHas('enquiries', ['office' => 'Bristol', 'practice_area' => 'wills_and_probate']);
    }
}
