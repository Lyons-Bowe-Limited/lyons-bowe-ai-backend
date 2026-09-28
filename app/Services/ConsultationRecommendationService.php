<?php

namespace App\Services;

use App\Models\AiConversation;
use Illuminate\Support\Str;

class ConsultationRecommendationService
{
    public function evaluate(
        string $message,
        ?AiConversation $conversation = null,
        ?string $practiceArea = null
    ): array {
        $normalisedMessage = $this->normalise($message);

        /*
         * The user has directly asked to speak to someone,
         * arrange contact, or book a meeting.
         */
        if ($this->containsAny($normalisedMessage, $this->directBookingTerms())) {
            return [
                'recommend' => true,
                'reason' => 'user_requested_consultation',
                'trigger_type' => 'manual',
                'confidence' => 'high',
            ];
        }

        /*
         * The user has asked for direct legal advice or for a
         * solicitor to assess their personal circumstances.
         */
        if ($this->containsAny($normalisedMessage, $this->solicitorReviewTerms())) {
            return [
                'recommend' => true,
                'reason' => 'solicitor_review_requested',
                'trigger_type' => 'ai_recommended',
                'confidence' => 'high',
            ];
        }

        /*
         * Certain situations should be escalated to a solicitor
         * rather than handled only through general AI guidance.
         */
        if ($this->containsAny($normalisedMessage, $this->mandatoryEscalationTerms())) {
            return [
                'recommend' => true,
                'reason' => 'matter_requires_solicitor',
                'trigger_type' => 'mandatory',
                'confidence' => 'high',
            ];
        }

        /*
         * Check whether the conversation has developed enough
         * for a consultation recommendation to be useful.
         */
        if (
            $conversation &&
            $practiceArea &&
            $this->hasEnoughConversationContext($conversation)
        ) {
            return [
                'recommend' => true,
                'reason' => 'sufficient_information_gathered',
                'trigger_type' => 'ai_recommended',
                'confidence' => 'medium',
            ];
        }

        return [
            'recommend' => false,
            'reason' => null,
            'trigger_type' => null,
            'confidence' => 'low',
        ];
    }

    private function hasEnoughConversationContext(
        AiConversation $conversation
    ): bool {
        $userMessages = $conversation->messages()
            ->where('role', 'user')
            ->latest('created_at')
            ->limit(10)
            ->get();

        /*
         * Do not recommend a consultation too early unless the
         * user has directly requested one.
         */
        if ($userMessages->count() < 2) {
            return false;
        }

        $combinedText = $userMessages
            ->pluck('content')
            ->filter()
            ->implode(' ');

        $wordCount = str_word_count(strip_tags($combinedText));

        /*
         * This threshold prevents the booking CTA from appearing
         * after only a couple of short or vague messages.
         */
        if ($wordCount < 25) {
            return false;
        }

        return $this->containsAny(
            $this->normalise($combinedText),
            $this->matterDetailTerms()
        );
    }

    private function directBookingTerms(): array
    {
        return [
            'book a consultation',
            'book an appointment',
            'book a meeting',
            'arrange a meeting',
            'arrange an appointment',
            'set up a meeting',
            'schedule a meeting',
            'schedule an appointment',
            'speak to a solicitor',
            'speak with a solicitor',
            'talk to a solicitor',
            'contact a solicitor',
            'put me in contact',
            'put me in touch',
            'someone call me',
            'call me',
            'contact me',
            'meet with someone',
            'meeting request',
            'consultation request',
            'i want to book',
            'i would like to book',
            'can i book',
            'please book',
        ];
    }

    private function solicitorReviewTerms(): array
    {
        return [
            'legal advice',
            'advise me',
            'review my situation',
            'review my case',
            'review my documents',
            'assess my case',
            'assess my situation',
            'what should i do',
            'what are my options',
            'take this matter forward',
            'start a matter',
            'instruct a solicitor',
            'need representation',
            'need a lawyer',
            'need a solicitor',
        ];
    }

    private function mandatoryEscalationTerms(): array
    {
        return [
            'court hearing',
            'court proceedings',
            'urgent hearing',
            'injunction',
            'domestic abuse',
            'domestic violence',
            'immediate danger',
            'child abduction',
            'contentious probate',
            'challenge a will',
            'contest a will',
            'mental capacity',
            'undue influence',
            'fraudulent will',
            'property fraud',
            'completion today',
            'exchange today',
            'deadline today',
            'limitation deadline',
            'received court papers',
            'served with papers',
        ];
    }

    private function matterDetailTerms(): array
    {
        return [
            'property',
            'house',
            'mortgage',
            'offer accepted',
            'exchange',
            'completion',
            'divorce',
            'separation',
            'children',
            'child arrangements',
            'financial settlement',
            'married',
            'partner',
            'will',
            'probate',
            'executor',
            'estate',
            'inheritance',
            'beneficiary',
            'lasting power of attorney',
            'lpa',
            'deadline',
            'court',
            'document',
            'agreement',
            'assets',
            'savings',
            'business',
        ];
    }

    private function containsAny(
        string $message,
        array $terms
    ): bool {
        foreach ($terms as $term) {
            if (str_contains($message, $term)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->squish()
            ->toString();
    }
}