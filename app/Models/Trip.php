<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Trip extends Model
{
    //
    use HasFactory;

    protected $fillable = [
        'trip_duration',
        'location',
        'gyroscope',
        'accelerometer',
        'network',
        'summary',
        'photo_score',
        'start_pos',
        'final_pos',
        'last_pos',
        'mode',
        'state',
        'type',
        'wallet',
        'origin',
        'destination'
    ];

    protected $casts = [
        'location' => 'array',
        'gyroscope' => 'array',
        'accelerometer' => 'array',
        'network' => 'array',
        'summary' => 'array',
        'start_pos' => 'array',
        'final_pos' => 'array',
        'last_pos' => 'array'
    ];
}
