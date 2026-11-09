<?php

namespace App\Services\Enquiries;

use App\Enums\PracticeArea;
use App\Models\AiConversation;
use App\Models\Enquiry;
use Illuminate\Support\Facades\DB;

class EnquiryCreationService
{
    public function __construct(private readonly EnquiryReferenceService $references) {}

    /** Call inside a wider transaction when further updates must share the lock. */
    public function getOrCreate(?int $conversationId, array $attributes): Enquiry
    {
        unset($attributes['conversation_id']);

        return DB::transaction(function () use ($conversationId, $attributes) {
            if ($conversationId !== null) {
                // Serialize all application creation paths, including workflow creation.
                AiConversation::query()->whereKey($conversationId)->lockForUpdate()->firstOrFail();
                $existing = Enquiry::query()->where('conversation_id', $conversationId)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $attributes['practice_area'] = PracticeArea::normalise($attributes['practice_area'] ?? null)->value;
            $attributes['reference'] = $this->references->generate($attributes['practice_area']);
            if ($conversationId === null) {
                return Enquiry::query()->create(['conversation_id' => null, ...$attributes]);
            }

            // Laravel uses a savepoint and reloads the winner on a unique violation.
            // The database constraint also protects writers outside this service.
            return Enquiry::query()->firstOrCreate(['conversation_id' => $conversationId], $attributes);
        }, attempts: 3);
    }
}
