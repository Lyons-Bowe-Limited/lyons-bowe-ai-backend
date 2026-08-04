<?php

namespace App\Services\Enquiries;

class WorkflowReplyService
{
    public function question(
        ?string $previousStepKey,
        mixed $previousAnswer,
        array $nextStep
    ): string {
        $acknowledgement = $this->acknowledgement(
            $previousStepKey,
            $previousAnswer
        );

        $question = $this->formatQuestion($nextStep);

        if ($acknowledgement === '') {
            return $question;
        }

        return trim(
            "{$acknowledgement}\n\n{$question}"
        );
    }

    public function clarification(
        array $step,
        string $clarification
    ): string {
        $options = $this->formatOptions($step);

        if ($options === '') {
            return trim($clarification);
        }

        return trim(
            "{$clarification}\n\n{$options}"
        );
    }

    public function started(array $firstStep): string
    {
        return sprintf(
            "Of course. I can guide you through the Will enquiry process, one step at a time, and explain anything you are unsure about along the way.\n\n%s",
            $this->formatQuestion($firstStep)
        );
    }

    public function completed(string $reference): string
    {
        return sprintf(
            'Thank you — that completes the initial Will enquiry. '
            .'I have securely saved your answers under reference %s '
            .'for the Lyons Bowe New Enquiry Team. You can continue '
            .'asking me questions about Wills or the next steps.',
            $reference
        );
    }

    private function formatQuestion(array $step): string
    {
        $question = trim(
            (string) ($step['question'] ?? '')
        );

        $options = $this->formatOptions($step);

        if ($options === '') {
            return $question;
        }

        return trim(
            "{$question}\n\n{$options}"
        );
    }

    private function formatOptions(array $step): string
    {
        $options = $step['options'] ?? [];

        if (! is_array($options) || $options === []) {
            return '';
        }

        $labels = collect($options)
            ->map(fn (array $option) => trim(
                (string) ($option['label'] ?? '')
            ))
            ->filter()
            ->values();

        if ($labels->isEmpty()) {
            return '';
        }

        $formatted = $labels
            ->map(fn (string $label) => "• {$label}")
            ->implode("\n");

        return "Please choose one of the following options:\n{$formatted}";
    }

    private function acknowledgement(
        ?string $stepKey,
        mixed $answer
    ): string {
        return match ($stepKey) {
            'urgent_will' => $answer === true
                ? 'I understand. I’ll make sure the urgency is taken into account.'
                : '',

            'urgent_will_details' =>
                'I understand.',

            'relationship_status' =>
                '',

            'family_law_support' =>
                $answer === 'no'
                    ? ''
                    : 'I’ve also noted that you may need support from another Lyons Bowe team.',

            'probate_support' =>
                $answer === 'no'
                    ? ''
                    : 'I’ve also noted that you may need support from another Lyons Bowe team.',

            'declaration_of_trust_support' =>
                '',

            'has_children' => $answer === true
                ? 'We’ll make sure your children are considered as part of the Will enquiry.'
                : '',

            'children_extra_protection' => $answer === true
                ? 'Of course. I’ll ask for a little more detail so the team understands what protection may be needed.'
                : '',

            'children_extra_protection_details' =>
                '',

            'owns_property' =>
                '',

            'lives_in_england_or_wales' =>
                '',

            'country_of_residence' =>
                '',

            'all_assets_in_uk' => $answer === false
                ? 'That may affect the type of Will support you need.'
                : '',

            'owns_business' => $answer === true
                ? 'Business ownership can affect how a Will needs to be structured.'
                : '',

            'donate_to_cruk' => $answer === true
                ? 'That’s very generous.'
                : '',

            default => '',
        };
    }
}