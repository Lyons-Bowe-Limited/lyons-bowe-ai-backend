<?php

namespace App\Services\Enquiries;

use App\Models\Enquiry;
use Illuminate\Support\Str;

class OperationalEnquirySummary
{
    /** A current operational projection; never rewrite or rely on AI contact claims. */
    public function for(Enquiry $enquiry): array
    {
        $digits = preg_replace('/\D/', '', $enquiry->client_phone ?? '');
        $contactComplete = filled($enquiry->client_name) && (
            filter_var($enquiry->client_email, FILTER_VALIDATE_EMAIL) !== false || (strlen($digits) >= 7 && strlen($digits) <= 15)
        );
        $text = Str::headline($enquiry->practice_area).' enquiry';
        if ($enquiry->matter_type) {
            $text .= ' — '.Str::headline($enquiry->matter_type);
        }
        $text .= '. '.($contactComplete ? 'Contact details available.' : 'Contact details incomplete.');
        $text .= $enquiry->assigned_staff_id ? ' Assigned to staff.' : ' Awaiting staff assignment.';
        if ($enquiry->requires_review) {
            $text .= ' Requires review.';
        }

        return ['text' => $text, 'contact_details_complete' => $contactComplete, 'source' => 'operational_enquiry'];
    }
}
