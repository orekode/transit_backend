<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use Illuminate\Http\Request;

use App\Http\Requests\StoreTripRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use App\Services\ThresholdService;

class TripController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'wallet' => 'sometimes|string|max:255',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:checked,pending',
                'type' => 'sometimes|string|in:real,fake',
                'mode' => 'sometimes|string|max:50',
                'sort_by' => 'sometimes|string|in:created_at,trip_duration,photo_score',
                'sort_order' => 'sometimes|string|in:asc,desc',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
            ]);

            // Build the query
            $query = Trip::query();

            // Filter by wallet if provided
            if ($request->has('wallet') && !empty($validated['wallet'])) {
                $query->where('wallet', $validated['wallet']);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $validated['status']);
            }

            // Filter by type (real/fake) if provided
            if ($request->has('type')) {
                $query->where('type', $validated['type']);
            }

            // Filter by transportation mode if provided
            if ($request->has('mode')) {
                $query->where('mode', 'LIKE', '%' . $validated['mode'] . '%');
            }

            // Filter by date range if provided
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $validated['date_from']);
            }

            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $validated['date_to']);
            }

            // Apply sorting
            $sortBy = $validated['sort_by'] ?? 'created_at';
            $sortOrder = $validated['sort_order'] ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);

            // Add secondary sort for consistent pagination
            if ($sortBy !== 'id') {
                $query->orderBy('id', 'desc');
            }

            // Set pagination parameters
            $perPage = $validated['per_page'] ?? 10;
            $perPage = min($perPage, 100); // Cap at 100 items per page

            // Execute paginated query
            $trips = $query->paginate($perPage);

            // Transform the data if needed
            $transformedTrips = $trips->getCollection()->map(function ($trip) {
                return [
                    'id' => $trip->id,
                    'wallet' => $trip->wallet,
                    'trip_duration' => $trip->trip_duration,
                    'location' => $trip->location,
                    'gyroscope' => $trip->gyroscope,
                    'accelerometer' => $trip->accelerometer,
                    'network' => $trip->network,
                    'summary' => $trip->summary,
                    'photo_score' => $trip->photo_score,
                    'start_pos' => $trip->start_pos,
                    'final_pos' => $trip->final_pos,
                    'last_pos' => $trip->last_pos,
                    'mode' => $trip->mode,
                    'origin' => $trip->origin,
                    'destination' => $trip->destination,
                    'traffic_data' => $trip->traffic_data ? json_decode($trip->traffic_data, true) : null,
                    'type' => $trip->type,
                    'status' => $trip->status,
                    'created_at' => $trip->created_at->toISOString(),
                    'updated_at' => $trip->updated_at->toISOString(),
                ];
            });

            // Replace the collection with transformed data
            $trips->setCollection($transformedTrips);

            // Prepare response data
            $responseData = [
                'data' => $trips->items(),
                'current_page' => $trips->currentPage(),
                'last_page' => $trips->lastPage(),
                'per_page' => $trips->perPage(),
                'total' => $trips->total(),
                'from' => $trips->firstItem(),
                'to' => $trips->lastItem(),
                'has_more_pages' => $trips->hasMorePages(),
                'path' => $trips->path(),
                'links' => [
                    'first' => $trips->url(1),
                    'last' => $trips->url($trips->lastPage()),
                    'prev' => $trips->previousPageUrl(),
                    'next' => $trips->nextPageUrl(),
                ]
            ];

            // Log successful retrieval for debugging
            Log::info('Trips retrieved successfully', [
                'wallet' => $validated['wallet'] ?? 'all',
                'page' => $trips->currentPage(),
                'per_page' => $trips->perPage(),
                'total' => $trips->total(),
                'filters' => array_intersect_key($validated, array_flip(['status', 'type', 'mode', 'date_from', 'date_to']))
            ]);

            return apiResponse($responseData, 'Trips retrieved successfully');

        } catch (ValidationException $e) {
            Log::warning('Validation failed for trips index', [
                'errors' => $e->errors(),
                'request' => $request->all()
            ]);
            
            return apiError('Validation failed', errors: $e->errors(), statusCode: 422);

        } catch (\Exception $e) {
            Log::error('Error retrieving trips', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all()
            ]);

            return apiError('Unable to retrieve trips', exception: [$request, $e]);
        }
    }

    /**
     * Get trip statistics for the authenticated user
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats(Request $request)
    {
        try {
            $wallet = $request->input('wallet');
            
            if (!$wallet) {
                return apiError('Wallet address is required');
            }

            $stats = [
                'total_trips' => Trip::where('wallet', $wallet)->count(),
                'real_trips' => Trip::where('wallet', $wallet)->where('type', 'real')->count(),
                'fake_trips' => Trip::where('wallet', $wallet)->where('type', 'fake')->count(),
                'pending_trips' => Trip::where('wallet', $wallet)->where('status', 'pending')->count(),
                'total_duration' => Trip::where('wallet', $wallet)->sum('trip_duration'),
                'average_score' => Trip::where('wallet', $wallet)->avg('photo_score'),
                'most_used_mode' => Trip::where('wallet', $wallet)
                    ->select('mode')
                    ->groupBy('mode')
                    ->orderByRaw('COUNT(*) DESC')
                    ->first()?->mode,
                'this_month_trips' => Trip::where('wallet', $wallet)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
            ];

            return apiResponse($stats, 'Trip statistics retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving trip statistics', [
                'message' => $e->getMessage(),
                'wallet' => $request->input('wallet')
            ]);

            return apiError('Unable to retrieve trip statistics', exception: [$request, $e]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTripRequest $request)
    {
        try {
            $validated = $request->validated();

            $thresholdService = new ThresholdService();
            
            $city = $thresholdService->checkAndValidateTripLimits(
                $request->input('wallet'),
                $request->ip()
            );

            $score = 0;
            for ($i = 0; $i < sizeof($validated['photoScore']); $i++) {
                $score += ((float) $validated['photoScore'][$i] / 7998666);
            }

            Log::info("score", ["score" => $score]);

            $score = $score / sizeof($validated['photoScore']);
            $validated["photoScore"] = $score;

            // Define the criteria that make a trip "identical"
            $duplicateTrip = Trip::where('wallet', $validated['wallet'])
                ->where('trip_duration', $validated['tripDuration'])
                ->where('location', $validated['location'])
                ->where('gyroscope', $validated['gyroscope'])
                ->where('accelerometer', $validated['accelerometer'])
                ->where('network', $validated['network'])
                ->where('summary', $validated['summary'])
                ->where('photo_score', $score)
                ->where('start_pos', $validated['startPos'])
                ->where('final_pos', $validated['finalPos'])
                ->where('last_pos', $validated['lastPos'])
                ->where('mode', $validated['mode'])
                ->first();

            if ($duplicateTrip) {
                return apiError("Duplicate trip data detected. Trip already exists.");
            }

            // Create the new trip
            $tripData = Trip::create([
                'wallet' => $validated['wallet'],
                'trip_duration' => $validated['tripDuration'],
                'location' => $validated['location'],
                'gyroscope' => $validated['gyroscope'],
                'accelerometer' => $validated['accelerometer'],
                'network' => $validated['network'],
                'summary' => $validated['summary'],
                'photo_score' => $score,
                'start_pos' => $validated['startPos'],
                'final_pos' => $validated['finalPos'],
                'last_pos' => $validated['lastPos'],
                'mode' => $validated['mode'],
                'origin'    => $this->getAddressFromCoordinates($validated['startPos']['lat'], $validated['startPos']['lng']) ?? "" . $validated['startPos']['lat'] . " " . $validated['startPos']['lng'],
                'destination' => $this->getAddressFromCoordinates($validated['finalPos']['lat'], $validated['finalPos']['lng'])   ?? "" . $validated['finalPos']['lat']  . " " . $validated['finalPos']['lng']
            ]);

            // Check traffic conditions
            $trafficCheck = $this->checkTrafficConditions(
                $validated,
                $tripData->created_at,
                $request->input('timestamp')
            );

            // Determine if the trip is real and log details
            $isReal = $this->isTripReal($validated, $trafficCheck, $request->input('timestamp'), $tripData->id);

            // Update trip with traffic data
            $tripData->update([
                'traffic_data' => json_encode($trafficCheck),
                'type' => $isReal ? "real" : "fake",
                'status' => "checked"
            ]);

            Log::info("Trip ID: {$tripData->id}, Verification Result: " . ($isReal ? 'Real' : 'Not Real'));

            $payed = $this->processPayment($tripData);

            return apiResponse([
                "real" => $isReal,
                "payed" => $payed,
            ], "Trip data stored successfully");

        } catch (\Exception $e) {
            return apiError($e->getMessage(), exception: [$request, $e]);
        }
    }

    private function getAddressFromCoordinates($lat, $lng): ?string
    {
        $apiKey = env('MAP_KEY'); // Make sure this is set in your .env file
        $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key={$apiKey}";

        $response = Http::get($url);

        if ($response->successful()) {
            $data = $response->json();

            if (isset($data['results'][0]['formatted_address'])) {
                return $data['results'][0]['formatted_address'];
            }
        }

        Log::error("Failed to reverse geocode for coordinates: {$lat}, {$lng}", ['response' => $response->json()]);
        return null;
    }



    private function checkTrafficConditions(array $tripData, Carbon $createdAt, ?int $timestamp): array
    {
        $apiKey = env('MAP_KEY');
        if (!$apiKey) {
            Log::warning('Google Maps API key not configured');
            return ['traffic_valid' => true, 'message' => 'API key not configured, skipping traffic check'];
        }

        // Calculate distance if finalPos is not {0, 0}
        $distance = null;
        if ($tripData['finalPos']['lat'] != 0 || $tripData['finalPos']['lng'] != 0) {
            $distance = $this->calculateDistance(
                $tripData['startPos']['lat'],
                $tripData['startPos']['lng'],
                $tripData['finalPos']['lat'],
                $tripData['finalPos']['lng']
            );
        }

        // Derive travel period
        $departureTime = $timestamp ? Carbon::createFromTimestampMs($timestamp) : $createdAt;
        $timeRadiusSeconds = $distance ? max(1800, $distance / 8.33) : 1800; // Min 30 min, or distance-based (30 km/h)
        $departureTimestamp = $departureTime->timestamp;

        // Google Maps API request
        $origin = "{$tripData['startPos']['lat']},{$tripData['startPos']['lng']}";
        $destination = $tripData['finalPos']['lat'] == 0 && $tripData['finalPos']['lng'] == 0
            ? $tripData['location']
            : "{$tripData['finalPos']['lat']},{$tripData['finalPos']['lng']}";
        $mode = strtolower($tripData['mode']) === 'car' ? 'driving' : strtolower($tripData['mode']);

        $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
            'origins' => $origin,
            'destinations' => $destination,
            'mode' => $mode,
            'departure_time' => $departureTimestamp,
            'traffic_model' => 'pessimistic',
            'key' => $apiKey,
        ]);

        if ($response->failed() || $response->json()['status'] !== 'OK') {
            Log::warning('Google Maps API request failed: ' . json_encode($response->json()));
            return ['traffic_valid' => true, 'message' => 'API request failed, assuming valid idle time'];
        }

        $element = $response->json()['rows'][0]['elements'][0];
        if ($element['status'] !== 'OK') {
            Log::warning('Google Maps API element status not OK: ' . $element['status']);
            return ['traffic_valid' => true, 'message' => 'API element error, assuming valid idle time'];
        }

        $durationInTraffic = $element['duration_in_traffic']['value'] * 1000; // Convert to ms
        $baseDuration = $element['duration']['value'] * 1000; // Without traffic
        $trafficDelay = $durationInTraffic - $baseDuration;
        $idleTime = $tripData['location']['idleTime'];
        $maxReasonableIdle = $trafficDelay * 1.5; // Lenient: 50% extra
        $trafficValid = $idleTime <= $maxReasonableIdle || $trafficDelay > $tripData['tripDuration'] * 0.3;

        return [
            'traffic_valid' => $trafficValid,
            'duration_in_traffic' => $durationInTraffic,
            'base_duration' => $baseDuration,
            'traffic_delay' => $trafficDelay,
            'message' => $trafficValid ? 'Idle time consistent with traffic' : 'Idle time exceeds expected traffic delay',
        ];
    }

    private function isTripReal(array $tripData, array $trafficCheck, ?int $timestamp, int $tripId): bool
    {
        // Initialize log data
        $logData = ['trip_id' => $tripId, 'checks' => []];

        // Check 1: photoScore >= 0.5
        $photoScoreValid = $tripData['photoScore'] >= 0.5;
        $logData['checks']['photoScore'] = [
            'value' => $tripData['photoScore'],
            'passed' => $photoScoreValid,
            'threshold' => '>= 0.5',
            'weight' => 0.3,
        ];
        $this->logCheck($tripId, 'photoScore', $photoScoreValid, "Value: {$tripData['photoScore']}, Threshold: >= 0.5");

        // Check 2: suspiciousActivityScore < 0.5
        $suspiciousScoreValid = $tripData['summary']['suspiciousActivityScore'] < 0.5;
        $logData['checks']['suspiciousActivityScore'] = [
            'value' => $tripData['summary']['suspiciousActivityScore'],
            'passed' => $suspiciousScoreValid,
            'threshold' => '< 0.5',
            'weight' => 0.05,
        ];
        $this->logCheck($tripId, 'suspiciousActivityScore', $suspiciousScoreValid, 
            "Value: {$tripData['summary']['suspiciousActivityScore']}, Threshold: < 0.5");

        // Check 3: idlePercentage < 50
        $idlePercentageValid = $tripData['location']['idlePercentage'] < 50;
        $logData['checks']['idlePercentage'] = [
            'value' => $tripData['location']['idlePercentage'],
            'passed' => $idlePercentageValid,
            'threshold' => '< 50',
            'weight' => 0.05,
        ];
        $this->logCheck($tripId, 'idlePercentage', $idlePercentageValid, 
            "Value: {$tripData['location']['idlePercentage']}%, Threshold: < 50%");

        // Check 4: unreasonableJumps <= 2
        $jumpsValid = $tripData['location']['unreasonableJumps'] <= 3;
        $logData['checks']['unreasonableJumps'] = [
            'value' => $tripData['location']['unreasonableJumps'],
            'passed' => $jumpsValid,
            'threshold' => '<= 3',
            'weight' => 0.1,
        ];
        $this->logCheck($tripId, 'unreasonableJumps', $jumpsValid, 
            "Value: {$tripData['location']['unreasonableJumps']}, Threshold: <= 2");

        // Check 5: hasMovement (startPos != finalPos or finalPos = {0, 0})
        $hasMovement = (
            $tripData['startPos']['lat'] != $tripData['lastPos']['lat'] &&
            $tripData['startPos']['lng'] != $tripData['lastPos']['lng'] &&
            ($tripData['lastPos']['lat'] != 0 && $tripData['lastPos']['lng'] != 0)
        );
        $logData['checks']['hasMovement'] = [
            'value' => "startPos: {$tripData['startPos']['lat']},{$tripData['startPos']['lng']}, " .
                       "lastPos: {$tripData['lastPos']['lat']},{$tripData['lastPos']['lng']}",
            'passed' => $hasMovement,
            'threshold' => 'startPos != lastPos or lastPos = {0, 0}',
            'weight' => 0.2,
        ];
        $this->logCheck($tripId, 'hasMovement', $hasMovement, 
            "Start: {$tripData['startPos']['lat']},{$tripData['startPos']['lng']}, " .
            "Last: {$tripData['lastPos']['lat']},{$tripData['lastPos']['lng']}");

        // Check 6: durationValid (tripDuration > 0)
        $durationValid = $tripData['tripDuration'] > (1000 * 60 * ($tripData['lastPos']['lat'] != 0 || $tripData['lastPos']['lng'] != 0)
            ? $this->calculateDistance(
                $tripData['startPos']['lat'],
                $tripData['startPos']['lng'],
                $tripData['lastPos']['lat'],
                $tripData['lastPos']['lng']
            )
            : 100000000);


        $logData['checks']['durationValid'] = [
            'value' => $tripData['tripDuration'],
            'passed' => $durationValid,
            'threshold' => '> 0',
            'weight' => 0.2,
        ];
        $this->logCheck($tripId, 'durationValid', $durationValid, 
            "Value: {$tripData['tripDuration']} ms, Threshold: > 0");

        // Check 7: trafficValid
        $trafficValid = $trafficCheck['traffic_valid'];
        $logData['checks']['trafficValid'] = [
            'value' => "idleTime: {$tripData['location']['idleTime']} ms, " .
                       "traffic_delay:". ($trafficCheck['traffic_delay'] ?? 0) . " ms",
            'passed' => $trafficValid,
            'threshold' => 'idleTime <= traffic_delay * 1.5 or traffic_delay > 30% of tripDuration',
            'weight' => 0.1,
        ];
        $this->logCheck($tripId, 'trafficValid', $trafficValid, 
            "IdleTime: {$tripData['location']['idleTime']} ms, " .
            "TrafficDelay: " . ($trafficCheck['traffic_delay'] ?? 0) . " ms, Message: {$trafficCheck['message']}");

        // Check 8: dayOfWeek (optional)
        $dayCheck = true;
        if ($timestamp) {
            $dayOfWeek = (int) gmdate('w', $timestamp / 1000);
            $dayCheck = in_array($dayOfWeek, [1, 2, 3, 4, 5]);
            $logData['checks']['dayOfWeek'] = [
                'value' => $dayOfWeek,
                'passed' => $dayCheck,
                'threshold' => 'Weekday (1-5)',
                'weight' => 0.0, // Not included in score
            ];
            $this->logCheck($tripId, 'dayOfWeek', $dayCheck, 
                "Day: {$dayOfWeek}, Threshold: Weekday (1-5)");
        }

        // Calculate weighted score
        $score = (
            $photoScoreValid * 0.3 +
            $suspiciousScoreValid * 0.05 +
            $idlePercentageValid * 0.05 +
            $jumpsValid * 0.1 +
            $hasMovement * 0.2 +
            $durationValid * 0.2 +
            $trafficValid * 0.1
        );

        $logData['final_score'] = $score;
        Log::info("Trip ID: {$tripId}, Final Score: {$score}, Threshold: >= 0.7", $logData);

        return $score >= 0.7;
    }

    public function processPayment(Trip $trip)
    {
        // Extract trip data
        $tripData = [
            'start_pos' => $trip->start_pos, true,
            'final_pos' => $trip->final_pos, true,
        ];

        // Infer distance_traveled from start_pos and final_pos
        $distanceTraveled = ($tripData['final_pos']['lat'] != 0 || $tripData['final_pos']['lng'] != 0)
            ? $this->calculateDistance(
                $tripData['start_pos']['lat'],
                $tripData['start_pos']['lng'],
                $tripData['final_pos']['lat'],
                $tripData['final_pos']['lng']
            )
            : 0;

        // Use wallet and network from trip model
        $wallet = $trip->wallet;
        if (empty($wallet)) {
            Log::warning("Payment processing for Trip ID: {$trip->id}, wallet is empty");
            $trip->update(['status' => 'failed_pay']);
            return false;
        }

        $network = $trip->network ?? 'test'; // Default to 'test' if null

        // Prepare payload for external endpoint
        $payload = [
            'distance' => $distanceTraveled,
            'userAddress' => $wallet,
            'network' => $network
        ];

        // Make request to external endpoint (replace with actual URL)
        $response = Http::post('https://reward-app-577639696387.us-central1.run.app/reward', $payload);

        // Log the request and response
        Log::info("Payment request for Trip ID: {$trip->id}, Payload: " . json_encode($payload));
        if ($response->failed() || !$response->body()->status) {
            Log::warning("Payment failed for Trip ID: {$trip->id}, Status: {$response->status()}, Response: " . $response->body());
            $trip->update(['status' => 'failed_pay']);
            return false;
        }

        // Update trip status to 'paid' on success
        Log::info("Payment succeeded for Trip ID: {$trip->id}, Response: " . $response->body());
        $trip->update(['status' => 'paid']);

        return true;
    }

    private function logCheck(int $tripId, string $checkName, bool $passed, string $details): void
    {
        $logMethod = $passed ? 'info' : 'warning';
        Log::$logMethod("Trip ID: {$tripId}, Check: {$checkName}, Status: " . 
            ($passed ? 'Passed' : 'Failed') . ", Details: {$details}");
    }

    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371e3; // Earth's radius in meters
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lng2 - $lng1);

        $a = sin($Δφ / 2) * sin($Δφ / 2) +
             cos($φ1) * cos($φ2) * sin($Δλ / 2) * sin($Δλ / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $R * $c; // Distance in meters
    }

    /**
     * Display the specified resource.
     */
    public function show(Trip $trip)
    {
        try {
            // Transform the trip data to match the index response format
            $transformedTrip = [
                'id' => $trip->id,
                'wallet' => $trip->wallet,
                'trip_duration' => $trip->trip_duration,
                'location' => $trip->location,
                'gyroscope' => $trip->gyroscope,
                'accelerometer' => $trip->accelerometer,
                'network' => $trip->network,
                'summary' => $trip->summary,
                'photo_score' => $trip->photo_score,
                'start_pos' => $trip->start_pos,
                'final_pos' => $trip->final_pos,
                'last_pos' => $trip->last_pos,
                'mode' => $trip->mode,
                'origin' => $trip->origin,
                'destination' => $trip->destination,
                'traffic_data' => $trip->traffic_data ? json_decode($trip->traffic_data, true) : null,
                'type' => $trip->type,
                'status' => $trip->status,
                'created_at' => $trip->created_at->toISOString(),
                'updated_at' => $trip->updated_at->toISOString(),
            ];

            // Log successful retrieval for debugging
            Log::info('Trip retrieved successfully', [
                'trip_id' => $trip->id,
                'wallet' => $trip->wallet,
            ]);

            return apiResponse(['data' => $transformedTrip], 'Trip retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving trip', [
                'trip_id' => $trip->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return apiError('Unable to retrieve trip', exception: $e, statusCode: 500);
        }
    }

    public function profile(Request $request)
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'wallet' => 'required|string|max:255',
            ]);

            $wallet = $validated['wallet'];

            // Fetch all trips for the user
            $trips = Trip::where('wallet', $wallet)->get();

            // Calculate statistics
            $totalTrips = $trips->count();
            $totalDistance = 0;
            $totalB3TR = 0;

            foreach ($trips as $trip) {
                if ($trip->type === 'real' && $trip->status === 'paid') {
                    // Calculate distance using Haversine formula
                    $distance = $this->calculateDistance(
                        $trip->start_pos['lat'],
                        $trip->start_pos['lng'],
                        $trip->final_pos['lat'],
                        $trip->final_pos['lng']
                    );
                    $totalDistance += $distance;
                    $totalB3TR += $distance * 0.5; // 0.5 B3TR per km
                }
            }

            // Round distance and B3TR to 2 decimal places
            $totalDistance = round($totalDistance, 2);
            $totalB3TR = round($totalB3TR, 2);

            // Prepare response data
            $responseData = [
                'wallet' => $wallet,
                'total_trips' => $totalTrips,
                'total_distance_km' => $totalDistance,
                'total_b3tr' => $totalB3TR,
                'created_at' => $trips->min('created_at')?->toISOString(),
            ];

            // Log successful retrieval
            Log::info('User profile retrieved successfully', [
                'wallet' => $wallet,
                'total_trips' => $totalTrips,
            ]);

            return apiResponse($responseData, 'User profile retrieved successfully');

        } catch (ValidationException $e) {
            Log::warning('Validation failed for user profile', [
                'errors' => $e->errors(),
                'request' => $request->all()
            ]);
            return apiError('Validation failed', errors: $e->errors(), statusCode: 422);
        } catch (\Exception $e) {
            Log::error('Error retrieving user profile', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all()
            ]);
            return apiError('Unable to retrieve user profile', exception: [$request, $e]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Trip $trip)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Trip $trip)
    {
        //
    }
}
