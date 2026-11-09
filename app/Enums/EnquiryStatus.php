<?php

namespace App\Enums;

enum EnquiryStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    // Questionnaire completion; not an operational closed/lost outcome.
    case Completed = 'completed';
}
