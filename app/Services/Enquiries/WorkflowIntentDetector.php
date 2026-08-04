<?php

namespace App\Services\Enquiries;

use Illuminate\Support\Str;

class WorkflowIntentDetector
{
    public function detect(string $message): ?array
    {
        $message = $this->normalise($message);

        /*
         * Keep general information questions in normal Victoria chat.
         */
        if ($this->isGeneralWillQuestion($message)) {
            return null;
        }

        if ($this->isClearWillStartIntent($message)) {
            return [
                'intent' => 'start_enquiry',
                'practice_area' => 'wills_and_probate',
                'workflow_key' => 'will_enquiry_v1',
                'confidence' => 1.0,
            ];
        }

        return null;
    }

    private function isClearWillStartIntent(
        string $message
    ): bool {
        $patterns = [
            /*
             * I want/need/would like to create a Will.
             */
            '/\b(?:i|we)\s+(?:want|need|would like|wanting|are ready|am ready|are looking|am looking)\s+(?:to\s+)?(?:make|create|write|write up|prepare|start|begin|set up|arrange|update|change)\s+(?:a|my|our|the)?\s*will\b/i',

            /*
             * Let's create/start a Will.
             */
            '/\blet(?:\'s| us)\s+(?:make|create|write|write up|prepare|start|begin|set up|arrange)\s+(?:a|our|the)?\s*will\b/i',

            /*
             * Help me/us create a Will.
             */
            '/\b(?:can you\s+)?help\s+(?:me|us)\s+(?:to\s+)?(?:make|create|write|write up|prepare|start|set up|arrange|update|change)\s+(?:a|my|our|the)?\s*will\b/i',

            /*
             * Start/begin the Will process.
             */
            '/\b(?:start|begin|continue)\s+(?:the|my|our)?\s*will\s+(?:process|questionnaire|enquiry|journey)\b/i',

            /*
             * Direct requests.
             */
            '/\b(?:i|we)\s+(?:want|need|would like)\s+(?:a|our|my)\s+will\b/i',

            /*
             * Mirror Wills or Wills for a couple.
             */
            '/\b(?:i|we)\s+(?:want|need|would like)\s+mirror\s+wills\b/i',

            '/\b(?:my partner|my spouse|my wife|my husband)\s+and\s+i\s+(?:want|need|would like)\s+wills\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isGeneralWillQuestion(
        string $message
    ): bool {
        $patterns = [
            '/\bwhat is a will\b/i',
            '/\bwhat does a will\b/i',
            '/\bhow does a will\b/i',
            '/\bshould i get a will\b/i',
            '/\bshould i make a will\b/i',
            '/\bdo i need a will\b/i',
            '/\bwhy do i need a will\b/i',
            '/\bhow much does a will cost\b/i',
            '/\bhow long does a will take\b/i',
            '/\bwhat happens without a will\b/i',
            '/\bwhat are mirror wills\b/i',
            '/\bwhat is a mirror will\b/i',
            '/\bwho can be an executor\b/i',
            '/\bwhat is an executor\b/i',
            '/\bcan an executor\b/i',
            '/\bwhat is a beneficiary\b/i',
            '/\bcan a beneficiary\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches(
                '/[^\pL\pN\s\'-]/u',
                ' '
            )
            ->squish()
            ->toString();
    }
}