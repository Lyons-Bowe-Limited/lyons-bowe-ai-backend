<?php

namespace Tests\Feature;

use App\Data\Enquiries\QualificationResult;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\ClaudeService;
use App\Services\Enquiries\EnquiryQualificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnquiryDraftCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(array $attributes = []): AiConversation
    {
        $user = User::factory()->create($attributes);
        $conversation = AiConversation::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id]);
        $conversation->memory()->create([
            'uuid' => (string) Str::uuid(), 'practice_area' => 'family_law', 'practice_area_confidence' => 0.95,
            'matter_type' => null, 'intent' => 'seek_legal_help', 'intent_confidence' => 0.9,
            'conversation_stage' => 'information_gathering', 'consultation_recommended' => false,
            'summary' => 'Client needs assistance with family arrangements.',
        ]);

        return $conversation;
    }

    private function evaluate(AiConversation $conversation, bool $recommended = false): QualificationResult
    {
        return app(EnquiryQualificationService::class)->qualify($conversation, $conversation->memory()->first(), ['recommend' => $recommended]);
    }

    public function test_exact_staging_urgent_family_help_request_creates_one_draft_urgent_enquiry(): void
    {
        $conversation = $this->conversation();
        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('extractConversationContext')->once()->andReturn([
                'practice_area' => 'family_law', 'practice_area_confidence' => 0.95, 'matter_type' => null,
                // The direct request must work even if the model's intent label is weak.
                'intent' => 'general_information', 'intent_confidence' => 0.4,
                'conversation_stage' => 'information_gathering', 'urgency' => ['signals' => []],
            ]);
            $mock->shouldReceive('chat')->once()->andReturn('Please tell me more about the arrangements you need.');
        });
        $this->actingAs($conversation->user)->postJson('/api/ai/playground/chat', [
            'conversation_id' => $conversation->uuid,
            'message' => 'I need help with family arrangements ASAP. This is a matter of urgency now. We need to make sure I am sorted once my divorce becomes finalised.',
        ])->assertOk();
        $enquiry = $conversation->enquiry()->sole();
        $this->assertSame('draft', $enquiry->status);
        $this->assertSame('family_law', $enquiry->practice_area);
        $this->assertNull($enquiry->matter_type);
        $this->assertTrue($enquiry->requires_review);
        $this->assertSame('information_gathering', $enquiry->handoff_reason);
        $this->assertSame('urgent', $enquiry->priority);
        $this->assertSame('explicit_urgent', $enquiry->priority_reason);
        $this->assertSame($conversation->user->name, $enquiry->client_name);
        $this->assertSame($conversation->user->email, $enquiry->client_email);
        $this->assertNull($enquiry->assigned_staff_id);
        $this->assertNull($enquiry->qualified_at);
        $this->assertNull($enquiry->submitted_at);
        $this->assertFalse($conversation->memory()->sole()->consultation_recommended);
        $this->assertSame(1, $enquiry->events()->where('event_type', 'priority_raised')->count());
        $this->evaluate($conversation);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_missing_matter_type_does_not_block_draft_capture_without_a_consultation(): void
    {
        $conversation = $this->conversation();
        $result = $this->evaluate($conversation);
        $this->assertTrue($result->shouldPersist);
        $this->assertFalse($result->qualified);
        $this->assertSame('family_law', $result->enquiry->practice_area);
        $this->assertNull($result->enquiry->matter_type);
        $this->assertSame('draft', $result->enquiry->status);
        $this->assertNull($result->enquiry->qualified_at);
        $this->assertNull($result->enquiry->submitted_at);
    }

    public function test_later_clarification_updates_and_submits_the_same_enquiry_only_when_fully_qualified(): void
    {
        $conversation = $this->conversation();
        $draft = $this->evaluate($conversation)->enquiry;
        $conversation->memory()->update(['matter_type' => 'divorce_and_separation', 'summary' => 'Clarified divorce and separation arrangements.']);
        $clarified = $this->evaluate($conversation);
        $this->assertSame($draft->id, $clarified->enquiry->id);
        $this->assertSame('divorce_and_separation', $clarified->enquiry->matter_type);
        $this->assertSame(['text' => 'Clarified divorce and separation arrangements.'], $clarified->enquiry->summary);
        $this->assertSame('draft', $clarified->enquiry->status);
        $this->assertNull($clarified->enquiry->submitted_at);
        $this->assertNull($clarified->enquiry->qualified_at);
        $this->assertDatabaseMissing('enquiry_events', ['event_type' => 'enquiry_submitted']);
        $submitted = $this->evaluate($conversation, true);
        $this->assertSame($draft->id, $submitted->enquiry->id);
        $this->assertSame($draft->reference, $submitted->enquiry->reference);
        $this->assertSame('submitted', $submitted->enquiry->status);
        $this->assertFalse($submitted->enquiry->requires_review);
        $this->assertNotNull($submitted->enquiry->qualified_at);
        $this->assertNotNull($submitted->enquiry->submitted_at);
        $this->evaluate($conversation, true);
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertSame(1, $draft->events()->where('event_type', 'enquiry_submitted')->count());
    }

    public function test_consultation_recommendation_without_matter_type_still_does_not_submit_draft(): void
    {
        $conversation = $this->conversation();
        $result = $this->evaluate($conversation, true);
        $this->assertSame('draft', $result->enquiry->status);
        $this->assertSame('family_law', $result->enquiry->practice_area);
        $this->assertNull($result->enquiry->matter_type);
        $this->assertTrue($result->enquiry->requires_review);
        $this->assertNull($result->enquiry->submitted_at);
    }

    public function test_informational_family_questions_do_not_create_drafts(): void
    {
        foreach (['What does family law cover?', 'Can you explain divorce?', 'Can you help me understand family law?', 'I need help understanding family law generally.'] as $message) {
            $conversation = $this->conversation();
            $conversation->memory()->update(['intent' => 'general_information', 'intent_confidence' => 0.99]);
            AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'user', 'content' => $message]);
            $this->assertFalse($this->evaluate($conversation)->shouldPersist);
        }
        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_early_capture_requires_confident_supported_area_and_account_name_email(): void
    {
        foreach ([['practice_area' => 'general'], ['practice_area_confidence' => 0.3]] as $memory) {
            $conversation = $this->conversation();
            $conversation->memory()->update($memory);
            $this->assertFalse($this->evaluate($conversation)->shouldPersist);
        }
        foreach ([['name' => ' '], ['email' => 'invalid']] as $account) {
            $conversation = $this->conversation($account);
            $this->assertFalse($this->evaluate($conversation)->shouldPersist);
        }
        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_short_clarification_updates_existing_draft_without_repeating_request(): void
    {
        $conversation = $this->conversation();
        $draft = $this->evaluate($conversation)->enquiry;
        $conversation->memory()->update(['intent' => null, 'summary' => 'Client clarified dates and arrangements.']);
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Next month.']);
        $result = $this->evaluate($conversation);
        $this->assertSame($draft->id, $result->enquiry->id);
        $this->assertSame(['text' => 'Client clarified dates and arrangements.'], $result->enquiry->summary);
        $this->assertSame('draft', $result->enquiry->status);
        $this->assertSame('information_gathering', $result->enquiry->handoff_reason);
        $this->assertNull($result->enquiry->submitted_at);
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_direct_assistance_and_instruction_requests_can_capture_before_ai_intent_is_confident(): void
    {
        foreach (['Can you help me with family arrangements?', 'Please assist me with my divorce.', 'I want to instruct a solicitor.', 'I need a lawyer.'] as $message) {
            $conversation = $this->conversation();
            $conversation->memory()->update(['intent' => null, 'intent_confidence' => 0]);
            AiMessage::create(['ai_conversation_id' => $conversation->id, 'role' => 'user', 'content' => $message]);
            $result = $this->evaluate($conversation);
            $this->assertTrue($result->shouldPersist);
            $this->assertSame('draft', $result->enquiry->status);
            $this->assertNull($result->enquiry->submitted_at);
        }
    }

    public function test_public_chat_informational_family_questions_do_not_create_enquiries(): void
    {
        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldReceive('extractConversationContext')->times(3)->andReturn([
                'practice_area' => 'family_law', 'practice_area_confidence' => 0.95, 'matter_type' => null,
                'intent' => 'general_information', 'intent_confidence' => 0.99, 'urgency' => ['signals' => []],
            ]);
            $mock->shouldReceive('chat')->times(3)->andReturn('General Family Law information.');
        });
        foreach (['What does Family Law cover?', 'Can you explain divorce?', 'Can you help me understand family law?'] as $message) {
            $conversation = $this->conversation();
            $this->actingAs($conversation->user)->postJson('/api/ai/playground/chat', [
                'conversation_id' => $conversation->uuid, 'message' => $message,
            ])->assertOk();
        }
        $this->assertDatabaseCount('enquiries', 0);
    }
}
