<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingLink extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'practice_area',
        'office',
        'service',
        'booking_business_id',
        'booking_url',
        'is_default',
        'is_active',
        'metadata',
        'trigger_type',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}