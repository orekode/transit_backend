<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TripAnalysis;
use App\Models\TripSensorSummary;
use App\Models\TripSuspiciousWindow;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

use App\Services\DistanceCalculator;
use App\Services\RewardService;

use App\Http\Requests\StoreTripAnalysisRequest;


class TripAnalysisController extends Controller
{
    /**
     * Store a new trip analysis.
     */
    public function store(StoreTripAnalysisRequest $request): JsonResponse
    {

        try {
            DB::beginTransaction();

            // Create the main trip analysis record
            $tripAnalysis = TripAnalysis::create([
                'mode' => $request->input('mode'),
                'wallet' => $request->input('wallet'),
                'photo_check' => $request->input('photoCheck'),
                'overall_realness' => $request->input('overallRealness'),
                'confidence_score' => $request->input('confidenceScore'),
                'total_windows' => $request->input('totalWindows'),
                'real_windows' => $request->input('realWindows'),
                'analysis_timestamp' => $request->input('timestamp'),
                'analysis_version' => $request->input('analysisVersion'),
                'location_stream' => $request->input('locationStream'),
            ]);

            // Store sensor summaries
            foreach ($request->input('sensorSummaries', []) as $sensorData) {
                $sensorSummary = TripSensorSummary::create([
                    'trip_analysis_id' => $tripAnalysis->id,
                    'sensor_type' => $sensorData['sensorType'],
                    'window_count' => $sensorData['windowCount'],
                    'avg_variance' => $sensorData['avgVariance'],
                    'avg_cv' => $sensorData['avgCV'],
                    'avg_entropy' => $sensorData['avgEntropy'],
                    'avg_autocorrelation' => $sensorData['avgAutocorrelation'],
                    'avg_frequency_power' => $sensorData['avgFrequencyPower'],
                    'avg_z_score_anomalies' => $sensorData['avgZScoreAnomalies'],
                    'avg_magnitude_variance' => $sensorData['avgMagnitudeVariance'] ?? null,
                    'avg_acceleration_changes' => $sensorData['avgAccelerationChanges'] ?? null,
                    'avg_cross_correlation' => $sensorData['avgCrossCorrelation'] ?? null,
                ]);

                // // Store suspicious windows for this sensor
                // if (isset($sensorData['suspiciousWindows'])) {
                //     foreach ($sensorData['suspiciousWindows'] as $windowData) {
                //         TripSuspiciousWindow::create([
                //             'trip_sensor_summary_id' => $sensorSummary->id,
                //             'window_index' => $windowData['windowIndex'],
                //             'is_real' => $windowData['isReal'],
                //             'reasons' => $windowData['reasons'] ?? [],
                //         ]);
                //     }
                // }
            }

            DB::commit();

            // Load the created record with relationships
            $tripAnalysis->load(['sensorSummaries.suspiciousWindows', 'user']);

            $this->reward($tripAnalysis);

            return apiResponse(
                $tripAnalysis,
                'Trip analysis stored successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();

            return apiError('Failed to store trip analysis', exception:[$e]);
            
        }
    }


    public function reward($data) {
        Log::info("reward data", [$data]);

        try {
            $calculator = new DistanceCalculator();
            $end   = json_decode($data->location_stream["destinationCoordinates"]);
            $start = json_decode($data->location_stream["startCoordinates"]);
            $curr  = json_decode($data->location_stream["currentPosition"]);

            
            $distance = $calculator->getShortestDistance(
                (array) $start,
                (array) $end,
                (array) $curr,
            );
            log::info("decoded", [$distance, (float) $data->confidence_score * (float) $data->photo_check * 0.5 > 0.5]);

            $confidence = 1; // ((float) $data->confidence_score * (float) $data->photo_check * 0.5) > 0.5;



            if (!$confidence) {
                return apiError('Failed to store reward analysis');
            }

            $rewarder = new RewardService();
            $rewarder->triggerSmartContract($data->wallet, $distance);

            $data->update([
                'state' => 'completed'
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Reward error: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Get a specific trip analysis.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $tripAnalysis = TripAnalysis::with([
                'sensorSummaries.suspiciousWindows',
                'user:id,name,email'
            ])->findOrFail($id);

            return apiResponse(
                $tripAnalysis,
                'Trip analysis retrieved successfully'
            );

        } catch (\Exception $e) {
            return apiError('Failed to retrieve trip analysis', exception:[$e]);

        }
    }

    /**
     * Get paginated list of trip analyses.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'realness' => 'nullable|boolean',
            'min_confidence' => 'nullable|numeric|between:0,100',
            'max_confidence' => 'nullable|numeric|between:0,100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = TripAnalysis::with([
                'sensorSummaries',
                'user:id,name,email'
            ]);

            // Apply filters
            if ($request->has('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }

            if ($request->has('realness')) {
                $query->where('overall_realness', $request->input('realness'));
            }

            if ($request->has('min_confidence') || $request->has('max_confidence')) {
                $query->byConfidence(
                    $request->input('min_confidence'),
                    $request->input('max_confidence')
                );
            }

            if ($request->has('start_date')) {
                $query->where('analysis_timestamp', '>=', $request->input('start_date'));
            }

            if ($request->has('end_date')) {
                $query->where('analysis_timestamp', '<=', $request->input('end_date'));
            }

            // Calculate statistics
            $totalTrips = $query->count();
            $realTrips = $query->where('overall_realness', true)->count();
            $suspiciousTrips = $totalTrips - $realTrips;

            $averageConfidence = $query->avg('confidence_score') ?: 0;
            $averageWindows = $query->avg('total_windows') ?: 0;
            $averageRealWindows = $query->avg('real_windows') ?: 0;

            // Get sensor type statistics
            $sensorStats = DB::table('trip_sensor_summaries')
                ->join('trip_analyses', 'trip_sensor_summaries.trip_analysis_id', '=', 'trip_analyses.id')
                ->when($request->has('user_id'), function ($query) use ($request) {
                    return $query->where('trip_analyses.user_id', $request->input('user_id'));
                })
                ->when($request->has('start_date'), function ($query) use ($request) {
                    return $query->where('trip_analyses.analysis_timestamp', '>=', $request->input('start_date'));
                })
                ->when($request->has('end_date'), function ($query) use ($request) {
                    return $query->where('trip_analyses.analysis_timestamp', '<=', $request->input('end_date'));
                })
                ->select(
                    'sensor_type',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('AVG(avg_variance) as avg_variance'),
                    DB::raw('AVG(avg_entropy) as avg_entropy'),
                    DB::raw('AVG(window_count) as avg_window_count')
                )
                ->groupBy('sensor_type')
                ->get();

            // Get recent trends (last 30 days)
            $recentTrends = TripAnalysis::query()
                ->when($request->has('user_id'), function ($query) use ($request) {
                    return $query->where('user_id', $request->input('user_id'));
                })
                ->where('analysis_timestamp', '>=', now()->subDays(30))
                ->select(
                    DB::raw('DATE(analysis_timestamp) as date'),
                    DB::raw('COUNT(*) as total_trips'),
                    DB::raw('SUM(CASE WHEN overall_realness = 1 THEN 1 ELSE 0 END) as real_trips'),
                    DB::raw('AVG(confidence_score) as avg_confidence')
                )
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get();

            return response()->json([
                'success' => true,
                'statistics' => [
                    'total_trips' => $totalTrips,
                    'real_trips' => $realTrips,
                    'suspicious_trips' => $suspiciousTrips,
                    'legitimacy_rate' => $totalTrips > 0 ? ($realTrips / $totalTrips) * 100 : 0,
                    'average_confidence' => round($averageConfidence, 2),
                    'average_windows' => round($averageWindows, 2),
                    'average_real_windows' => round($averageRealWindows, 2),
                    'sensor_statistics' => $sensorStats,
                    'recent_trends' => $recentTrends,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get suspicious patterns analysis.
     */
    public function suspiciousPatterns(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:users,id',
            'sensor_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = TripSuspiciousWindow::query()
                ->join('trip_sensor_summaries', 'trip_suspicious_windows.trip_sensor_summary_id', '=', 'trip_sensor_summaries.id')
                ->join('trip_analyses', 'trip_sensor_summaries.trip_analysis_id', '=', 'trip_analyses.id')
                ->where('trip_suspicious_windows.is_real', false);

            // Apply filters
            if ($request->has('user_id')) {
                $query->where('trip_analyses.user_id', $request->input('user_id'));
            }

            if ($request->has('sensor_type')) {
                $query->where('trip_sensor_summaries.sensor_type', $request->input('sensor_type'));
            }

            // Get most common suspicious patterns
            $patterns = $query->select(
                    'trip_sensor_summaries.sensor_type',
                    'trip_suspicious_windows.reasons',
                    DB::raw('COUNT(*) as frequency'),
                    DB::raw('AVG(trip_analyses.confidence_score) as avg_confidence_score')
                )
                ->groupBy('trip_sensor_summaries.sensor_type', 'trip_suspicious_windows.reasons')
                ->orderBy('frequency', 'desc')
                ->limit($request->input('limit', 20))
                ->get();

            // Process reasons to extract individual patterns
            $processedPatterns = [];
            foreach ($patterns as $pattern) {
                $reasons = is_string($pattern->reasons) ? json_decode($pattern->reasons, true) : $pattern->reasons;
                if (is_array($reasons)) {
                    foreach ($reasons as $reason) {
                        $key = $pattern->sensor_type . '|' . $reason;
                        if (!isset($processedPatterns[$key])) {
                            $processedPatterns[$key] = [
                                'sensor_type' => $pattern->sensor_type,
                                'reason' => $reason,
                                'frequency' => 0,
                                'avg_confidence_score' => 0,
                                'count' => 0
                            ];
                        }
                        $processedPatterns[$key]['frequency'] += $pattern->frequency;
                        $processedPatterns[$key]['avg_confidence_score'] += $pattern->avg_confidence_score;
                        $processedPatterns[$key]['count']++;
                    }
                }
            }

            // Calculate final averages and sort
            $finalPatterns = array_map(function ($pattern) {
                $pattern['avg_confidence_score'] = $pattern['count'] > 0 
                    ? $pattern['avg_confidence_score'] / $pattern['count'] 
                    : 0;
                unset($pattern['count']);
                return $pattern;
            }, $processedPatterns);

            usort($finalPatterns, function ($a, $b) {
                return $b['frequency'] - $a['frequency'];
            });

            return response()->json([
                'success' => true,
                'patterns' => array_slice($finalPatterns, 0, $request->input('limit', 20)),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve suspicious patterns',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}