<?php

namespace App\Services\Enquiries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EnquiryReferenceService
{
    private const MAX_GENERATION_ATTEMPTS = 3;

    /**
     * Generate a concurrency-safe human-readable enquiry reference.
     */
    public function generate(
        string $practiceArea,
        ?CarbonImmutable $date = null
    ): string {
        $prefix = $this->prefixForPracticeArea($practiceArea);
        $sequenceDate = ($date ?? CarbonImmutable::now())
            ->toDateString();

        for (
            $attempt = 1;
            $attempt <= self::MAX_GENERATION_ATTEMPTS;
            $attempt++
        ) {
            try {
                return DB::transaction(
                    function () use (
                        $prefix,
                        $sequenceDate
                    ): string {
                        /*
                         * Ensure the counter row exists.
                         *
                         * The unique index prevents two simultaneous
                         * requests from creating duplicate rows.
                         */
                        DB::table('enquiry_reference_sequences')
                            ->insertOrIgnore([
                                'prefix' => $prefix,
                                'sequence_date' => $sequenceDate,
                                'current_number' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                        /*
                         * Lock this prefix/date counter until the
                         * transaction finishes.
                         */
                        $sequence = DB::table(
                            'enquiry_reference_sequences'
                        )
                            ->where('prefix', $prefix)
                            ->where(
                                'sequence_date',
                                $sequenceDate
                            )
                            ->lockForUpdate()
                            ->first();

                        if ($sequence === null) {
                            throw new RuntimeException(
                                'Unable to initialise the enquiry '
                                .'reference sequence.'
                            );
                        }

                        $nextNumber =
                            (int) $sequence->current_number + 1;

                        DB::table('enquiry_reference_sequences')
                            ->where('id', $sequence->id)
                            ->update([
                                'current_number' => $nextNumber,
                                'updated_at' => now(),
                            ]);

                        return $this->formatReference(
                            prefix: $prefix,
                            sequenceDate: $sequenceDate,
                            sequenceNumber: $nextNumber,
                        );
                    },
                    attempts: 3,
                );
            } catch (Throwable $exception) {
                if (
                    $attempt
                    === self::MAX_GENERATION_ATTEMPTS
                ) {
                    throw new RuntimeException(
                        'Unable to generate a unique enquiry '
                        .'reference.',
                        previous: $exception,
                    );
                }

                usleep(50_000 * $attempt);
            }
        }

        throw new RuntimeException(
            'Unable to generate a unique enquiry reference.'
        );
    }

    /**
     * Format the final human-readable reference.
     */
    private function formatReference(
        string $prefix,
        string $sequenceDate,
        int $sequenceNumber
    ): string {
        $formattedDate = CarbonImmutable::parse(
            $sequenceDate
        )->format('Ymd');

        return sprintf(
            '%s-%s-%06d',
            $prefix,
            $formattedDate,
            $sequenceNumber,
        );
    }

    /**
     * Convert a practice area into its reference prefix.
     */
    private function prefixForPracticeArea(
        string $practiceArea
    ): string {
        return match ($practiceArea) {
            'wills_and_probate' => 'WILL',
            'family_law' => 'FAM',
            'property_law' => 'PROP',
            'probate' => 'PROB',
            default => 'ENQ',
        };
    }
}