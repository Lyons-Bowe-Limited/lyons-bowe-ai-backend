<?php

namespace App\Services\Enquiries;

use App\Enums\PracticeArea;

class EnquiryClassification
{
    public function isValid(string $area, ?string $matter): bool
    {
        if (PracticeArea::tryFrom($area) === null) {
            return false;
        }
        if ($area === PracticeArea::Unclassified->value) {
            return $matter === null;
        }

        return $matter !== null && config("enquiries.matter_types.{$matter}.practice_area") === $area;
    }
}
