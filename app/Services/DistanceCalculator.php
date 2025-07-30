<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DistanceCalculator
{
    // Earth's radius in kilometers
    private const EARTH_RADIUS_KM = 6371;

    /**
     * Calculate the shortest distance from the start coordinate to either the destination or current coordinate.
     *
     * @param array $start ['lat' => float, 'lng' => float] Start coordinate
     * @param array $destination ['lat' => float, 'lng' => float] Destination coordinate
     * @param array $current ['lat' => float, 
     * 
     * 
     *  => float] Current coordinate
     * @return float Shortest distance in kilometers
     * @throws InvalidArgumentException If coordinates are invalid
     */
    public function getShortestDistance(array $start, array $destination, array $current): float
    {
        try {
            // Validate coordinates
            $this->validateCoordinates($start, 'start');
            $this->validateCoordinates($destination, 'destination');
            $this->validateCoordinates($current, 'current');

            // Calculate distances using Haversine formula
            $distanceToDestination = $this->haversineDistance(
                $start['lat'], $start['lng'],
                $destination['lat'], $destination['lng']
            );
            $distanceToCurrent = $this->haversineDistance(
                $start['lat'], $start['lng'],
                $current['lat'], $current['lng']
            );

            // Log distances for debugging
            Log::debug('Calculated distances', [
                'start' => $start,
                'destination' => $destination,
                'current' => $current,
                'distance_to_destination_km' => $distanceToDestination,
                'distance_to_current_km' => $distanceToCurrent,
            ]);

            // Return the smallest distance
            return max($distanceToDestination, $distanceToCurrent);
        } catch (InvalidArgumentException $e) {
            Log::error('Invalid coordinates provided', [
                'error' => $e->getMessage(),
                'start' => $start,
                'destination' => $destination,
                'current' => $current,
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error calculating shortest distance', [
                'error' => $e->getMessage(),
                'start' => $start,
                'destination' => $destination,
                'current' => $current,
            ]);
            throw new \Exception('Failed to calculate shortest distance: ' . $e->getMessage());
        }
    }

    /**
     * Validate coordinate array
     *
     * @param array $coord ['lat' => float, 'lng' => float]
     * @param string $type Coordinate type (e.g., 'start', 'destination')
     * @throws InvalidArgumentException
     */
    private function validateCoordinates(array $coord, string $type): void
    {
        if (!isset($coord['lat']) || !isset($coord['lng'])) {
            throw new InvalidArgumentException("{$type} coordinate must contain 'lat' and 'lng' keys");
        }

        if (!is_numeric($coord['lat']) || !is_numeric($coord['lng'])) {
            throw new InvalidArgumentException("{$type} coordinate latitude and longitude must be numeric");
        }

        if ($coord['lat'] < -90 || $coord['lat'] > 90) {
            throw new InvalidArgumentException("{$type} coordinate latitude must be between -90 and 90 degrees");
        }

        if ($coord['lng'] < -180 || $coord['lng'] > 180) {
            throw new InvalidArgumentException("{$type} coordinate longitude must be between -180 and 180 degrees");
        }
    }

    /**
     * Calculate distance between two coordinates using the Haversine formula
     *
     * @param float $lat1 Latitude of first point
     * @param float $lon1 Longitude of first point
     * @param float $lat2 Latitude of second point
     * @param float $lon2 Longitude of second point
     * @return float Distance in kilometers
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        // Convert degrees to radians
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        // Haversine formula
        $dLat = $lat2 - $lat1;
        $dLon = $lon2 - $lon1;
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos($lat1) * cos($lat2) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}