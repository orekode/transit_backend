<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Threshold Model
class Threshold extends Model
{
    protected $fillable = ['type', 'threshold'];

    public static function getThreshold($type)
    {
        return self::where('type', $type)->first();
    }
}