<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet',
        'photo_check',
        'overall_realness',
        'confidence_score',
        'total_windows',
        'real_windows',
        'analysis_timestamp',
        'analysis_version',
        'location_stream',
        'mode',
    ];

    protected $casts = [
        'overall_realness' => 'boolean',
        'confidence_score' => 'decimal:2',
        'analysis_timestamp' => 'datetime',
        'location_stream' => 'array',
    ];

    protected $dates = [
        'analysis_timestamp',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the user that owns the trip analysis.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the sensor summaries for the trip analysis.
     */
    public function sensorSummaries(): HasMany
    {
        return $this->hasMany(TripSensorSummary::class);
    }

    /**
     * Get all suspicious windows through sensor summaries.
     */
    public function suspiciousWindows()
    {
        return $this->hasManyThrough(
            TripSuspiciousWindow::class,
            TripSensorSummary::class,
            'trip_analysis_id',
            'trip_sensor_summary_id'
        );
    }

    /**
     * Scope to filter by realness.
     */
    public function scopeReal($query)
    {
        return $query->where('overall_realness', true);
    }

    /**
     * Scope to filter by suspicious trips.
     */
    public function scopeSuspicious($query)
    {
        return $query->where('overall_realness', false);
    }

    /**
     * Scope to filter by confidence score range.
     */
    public function scopeByConfidence($query, $min = null, $max = null)
    {
        if ($min !== null) {
            $query->where('confidence_score', '>=', $min);
        }
        if ($max !== null) {
            $query->where('confidence_score', '<=', $max);
        }
        return $query;
    }

    /**
     * Get the trip legitimacy percentage.
     */
    public function getLegitimacyPercentageAttribute(): float
    {
        if ($this->total_windows === 0) {
            return 0;
        }
        return ($this->real_windows / $this->total_windows) * 100;
    }

    /**
     * Get the analyzed path from location stream.
     */
    public function getAnalyzedPathAttribute(): ?array
    {
        $locationStream = $this->location_stream;
        if (isset($locationStream['analyzedPath'])) {
            return json_decode($locationStream['analyzedPath'], true);
        }
        return null;
    }

    /**
     * Get the trip legitimacy from location stream.
     */
    public function getTripLegitimacyAttribute(): ?array
    {
        $locationStream = $this->location_stream;
        if (isset($locationStream['tripLegitimacy'])) {
            return json_decode($locationStream['tripLegitimacy'], true);
        }
        return null;
    }
}

// ====================================

class TripSensorSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_analysis_id',
        'sensor_type',
        'window_count',
        'avg_variance',
        'avg_cv',
        'avg_entropy',
        'avg_autocorrelation',
        'avg_frequency_power',
        'avg_z_score_anomalies',
        'avg_magnitude_variance',
        'avg_acceleration_changes',
        'avg_cross_correlation',
    ];

    protected $casts = [
        'avg_variance' => 'decimal:6',
        'avg_cv' => 'decimal:6',
        'avg_entropy' => 'decimal:6',
        'avg_autocorrelation' => 'decimal:6',
        'avg_frequency_power' => 'decimal:6',
        'avg_z_score_anomalies' => 'decimal:6',
        'avg_magnitude_variance' => 'decimal:6',
        'avg_acceleration_changes' => 'decimal:6',
        'avg_cross_correlation' => 'decimal:6',
    ];

    /**
     * Get the trip analysis that owns the sensor summary.
     */
    public function tripAnalysis(): BelongsTo
    {
        return $this->belongsTo(TripAnalysis::class);
    }

    /**
     * Get the suspicious windows for the sensor summary.
     */
    public function suspiciousWindows(): HasMany
    {
        return $this->hasMany(TripSuspiciousWindow::class);
    }

    /**
     * Get the count of suspicious windows.
     */
    public function getSuspiciousWindowsCountAttribute(): int
    {
        return $this->suspiciousWindows()->count();
    }

    /**
     * Get the percentage of suspicious windows.
     */
    public function getSuspiciousPercentageAttribute(): float
    {
        if ($this->window_count === 0) {
            return 0;
        }
        return ($this->suspicious_windows_count / $this->window_count) * 100;
    }
}

// ====================================

class TripSuspiciousWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_sensor_summary_id',
        'window_index',
        'is_real',
        'reasons',
    ];

    protected $casts = [
        'is_real' => 'boolean',
        'reasons' => 'array',
    ];

    /**
     * Get the sensor summary that owns the suspicious window.
     */
    public function sensorSummary(): BelongsTo
    {
        return $this->belongsTo(TripSensorSummary::class, 'trip_sensor_summary_id');
    }

    /**
     * Get the trip analysis through sensor summary.
     */
    public function tripAnalysis()
    {
        return $this->hasOneThrough(
            TripAnalysis::class,
            TripSensorSummary::class,
            'id',
            'id',
            'trip_sensor_summary_id',
            'trip_analysis_id'
        );
    }

    /**
     * Scope to filter only suspicious windows.
     */
    public function scopeSuspicious($query)
    {
        return $query->where('is_real', false);
    }

    /**
     * Scope to filter only legitimate windows.
     */
    public function scopeLegitimate($query)
    {
        return $query->where('is_real', true);
    }
}