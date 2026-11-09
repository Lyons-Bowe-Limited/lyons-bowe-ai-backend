<?php

namespace App\Services\Enquiries;

class LegalHelpIntentDetector
{
    /** Direct requests only; informational/hypothetical questions are not instructions. */
    public function detect(string $message): bool
    {
        $message = mb_strtolower(trim($message));
        if (preg_match('/\b(?:help|assistance)(?: me| us)?(?: with| to)? (?:understand|understanding|learn|learning|research|researching)\b/u', $message)) {
            return false;
        }

        return preg_match('/(?:^|[.!?;\n]\s*)(?:please[, ]+)?(?:(?:i|we) (?:need|want|would like|require|am looking for|are looking for) (?:legal )?(?:help|assistance|representation|advice)\b|(?:can|could|would) you (?:please )?(?:help|assist|advise|represent) (?:me|us)\b|(?:help|assist|advise|represent) (?:me|us)\b|(?:i|we) (?:want|need|would like|wish) to (?:instruct|hire|engage) (?:a |the |my |our )?(?:solicitor|lawyer)\b|(?:i|we) (?:need|want|require) (?:a |the |my |our )(?:solicitor|lawyer)\b)/u', $message) === 1;
    }
}
