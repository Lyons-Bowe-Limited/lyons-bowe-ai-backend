<?php

namespace App\Services;

use App\Models\BookingLink;

class BookingLinkService
{
    public function find(
        ?string $practiceArea,
        ?string $office = null,
        ?string $service = null
    ): ?BookingLink {
        $query = BookingLink::query()
            ->where('is_active', true)
            ->when($practiceArea, fn ($query) =>
                $query->where('practice_area', $practiceArea)
            );

        if ($office) {
            $officeMatch = (clone $query)
                ->where('office', $office)
                ->when($service, fn ($query) =>
                    $query->where(function ($query) use ($service) {
                        $query->where('service', $service)
                            ->orWhereNull('service');
                    })
                )
                ->orderByRaw('service IS NULL')
                ->first();

            if ($officeMatch) {
                return $officeMatch;
            }
        }

        if ($service) {
            $serviceMatch = (clone $query)
                ->where('service', $service)
                ->first();

            if ($serviceMatch) {
                return $serviceMatch;
            }
        }

        return $query
            ->where('is_default', true)
            ->first();
    }
}