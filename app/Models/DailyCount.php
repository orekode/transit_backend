<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DailyCount extends Model
{
    protected $fillable = ['type', 'value', 'count', 'date'];

    public static function incrementCount($type, $value)
    {
        return self::updateOrCreate(
            [
                'type' => $type,
                'value' => $value,
                'date' => now()->toDateString(),
            ],
            [
                'count' => DB::raw('count + 1'),
            ]
        );
    }

    public static function getCount($type, $value)
    {
        return self::where('type', $type)
            ->where('value', $value)
            ->where('date', now()->toDateString())
            ->first();
    }
}