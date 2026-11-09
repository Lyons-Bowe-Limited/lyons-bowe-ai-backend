<?php

namespace App\Data\Enquiries;

use App\Enums\PracticeArea;
use App\Models\Enquiry;

readonly class QualificationResult
{
    public function __construct(
        public bool $shouldPersist,
        public bool $qualified,
        public PracticeArea $practiceArea,
        public ?string $matterType,
        public float $confidence,
        public string $source,
        public bool $requiresReview,
        public string $reason,
        public ?Enquiry $enquiry = null,
    ) {}
}
