<?php

namespace Database\Seeders;

use App\Models\BookingLink;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BookingLinksSeeder extends Seeder
{
    public function run(): void
    {
        $links = [
            [
                'name' => 'Bath Wills & LPA',
                'office' => 'bath',
                'booking_url' => 'https://bookings.cloud.microsoft/book/BathWillsandLPA@lyonsbowe.co.uk/?ismsaljsauthenabled',
                'is_default' => false,
            ],
            [
                'name' => 'Bridgwater Private Client & Wills',
                'office' => 'bridgwater',
                'booking_url' => 'https://bookings.cloud.microsoft/book/BridgwaterPrivateClientandWillsTeam@lyonsbowe.co.uk/?ismsaljsauthenabled=true',
                'is_default' => false,
            ],
            [
                'name' => 'Bristol Private Client & Wills',
                'office' => 'bristol',
                'booking_url' => 'https://bookings.cloud.microsoft/book/BristolPrivateClientandWillsTeam@lyonsbowe.co.uk/?ismsaljsauthenabled=true',
                'is_default' => false,
            ],
            [
                'name' => 'Frome Private Client & Wills',
                'office' => 'frome',
                'booking_url' => 'https://bookings.cloud.microsoft/book/FromePrivateClientandWillsTeam@lyonsbowe.co.uk/?ismsaljsauthenabled=true',
                'is_default' => false,
            ],
            [
                'name' => 'Keynsham Private Client & Wills',
                'office' => 'keynsham',
                'booking_url' => 'https://bookings.cloud.microsoft/book/KeynshamPrivateClientandWillsTeam@lyonsbowe.co.uk/?ismsaljsauthenabled=true',
                'is_default' => false,
            ],
            [
                'name' => 'Cardiff Private Client & Wills',
                'office' => 'cardiff',
                'booking_url' => 'https://bookings.cloud.microsoft/book/CardiffPrivateClientandWillsTeam@lyonsbowe.co.uk/?ismsaljsauthenabled=true',
                'is_default' => false,
            ],
            [
                'name' => 'Shepton Mallet Private Client & Wills',
                'office' => 'shepton_mallet',
                'booking_url' => 'https://bookings.cloud.microsoft/book/SheptonPrivateClientandWillsTeamCopy@lyonsbowe.co.uk/?ismsaljsauthenabled=true',
                'is_default' => false,
            ],
            [
                'name' => 'Teams / Telephone Wills & LPA',
                'office' => null,
                'booking_url' => 'https://bookings.cloud.microsoft/book/TeamsTelephoneWillsandLPA@lyonsbowe.co.uk/?ismsaljsauthenabled=true',
                'is_default' => true,
            ],
        ];

        foreach ($links as $link) {
            BookingLink::updateOrCreate(
                [
                    'practice_area' => 'wills_and_probate',
                    'office' => $link['office'],
                ],
                [
                    'uuid' => BookingLink::query()
                        ->where('practice_area', 'wills_and_probate')
                        ->where('office', $link['office'])
                        ->value('uuid') ?? (string) Str::uuid(),

                    'name' => $link['name'],
                    'service' => null,
                    'booking_business_id' => null,
                    'booking_url' => $link['booking_url'],
                    'is_default' => $link['is_default'],
                    'is_active' => true,
                    'metadata' => null,
                    'trigger_type' => 'ai_recommended',
                ]
            );
        }

        BookingLink::query()
            ->where('name', 'Leigh Test Calendar')
            ->update([
                'is_default' => false,
                'is_active' => false,
            ]);
    }
}