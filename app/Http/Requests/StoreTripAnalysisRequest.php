<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreTripAnalysisRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Adjust based on your authorization logic
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'mode' => 'required',
            'wallet' => 'required',
            'photoCheck' => 'required',
            'overallRealness' => 'required|boolean',
            'confidenceScore' => 'required|numeric|between:0,100',
            'totalWindows' => 'required|integer|min:0',
            'realWindows' => 'required|integer|min:0|lte:totalWindows',
            'timestamp' => 'required|date|before_or_equal:now',
            'analysisVersion' => 'required|string|max:10',
            
            // Location stream validation
            'locationStream' => 'nullable|array',
            'locationStream.analyzedPath' => 'nullable|string|json',
            'locationStream.tripLegitimacy' => 'nullable|string|json',
            
            // Sensor summaries validation
            'sensorSummaries' => 'required|array|min:1',
            'sensorSummaries.*.sensorType' => 'required|string|max:50|in:accelerometer,gyroscope,magnetometer,location,orientation,absoluteOrientation,relativeOrientation,linearAcceleration,gravity',
            'sensorSummaries.*.windowCount' => 'required|integer|min:0',
            
            // Statistical measures validation
            'sensorSummaries.*.avgVariance' => 'nullable|numeric|min:0',
            'sensorSummaries.*.avgCV' => 'nullable|numeric|min:0',
            'sensorSummaries.*.avgEntropy' => 'nullable|numeric|min:0',
            'sensorSummaries.*.avgAutocorrelation' => 'nullable|numeric|between:-1,1',
            'sensorSummaries.*.avgFrequencyPower' => 'nullable|numeric|min:0',
            'sensorSummaries.*.avgZScoreAnomalies' => 'nullable|numeric|min:0',
            
            // Optional sensor-specific measures
            'sensorSummaries.*.avgMagnitudeVariance' => 'nullable|numeric|min:0',
            'sensorSummaries.*.avgAccelerationChanges' => 'nullable|numeric|min:0',
            'sensorSummaries.*.avgCrossCorrelation' => 'nullable|numeric|between:-1,1',
            
            // Suspicious windows validation
            'sensorSummaries.*.suspiciousWindows' => 'nullable|array',
            'sensorSummaries.*.suspiciousWindows.*.windowIndex' => 'required|integer|min:0',
            'sensorSummaries.*.suspiciousWindows.*.isReal' => 'required|boolean',
            'sensorSummaries.*.suspiciousWindows.*.reasons' => 'nullable|array',
            'sensorSummaries.*.suspiciousWindows.*.reasons.*' => 'string|max:255',
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'userId.exists' => 'The specified user does not exist.',
            'confidenceScore.between' => 'Confidence score must be between 0 and 100.',
            'realWindows.lte' => 'Real windows cannot exceed total windows.',
            'timestamp.before_or_equal' => 'Analysis timestamp cannot be in the future.',
            'sensorSummaries.required' => 'At least one sensor summary is required.',
            'sensorSummaries.*.sensorType.in' => 'Invalid sensor type. Must be one of: accelerometer, gyroscope, magnetometer, location, orientation.',
            'locationStream.analyzedPath.json' => 'Analyzed path must be valid JSON.',
            'locationStream.tripLegitimacy.json' => 'Trip legitimacy must be valid JSON.',
        ];
    }

    /**
     * Get custom attribute names for validation errors.
     */
    public function attributes(): array
    {
        return [
            'userId' => 'user ID',
            'overallRealness' => 'overall realness',
            'confidenceScore' => 'confidence score',
            'totalWindows' => 'total windows',
            'realWindows' => 'real windows',
            'analysisVersion' => 'analysis version',
            'locationStream.analyzedPath' => 'analyzed path',
            'locationStream.tripLegitimacy' => 'trip legitimacy',
            'sensorSummaries.*.sensorType' => 'sensor type',
            'sensorSummaries.*.windowCount' => 'window count',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean values if needed
        if ($this->has('overallRealness') && is_string($this->overallRealness)) {
            $this->merge([
                'overallRealness' => filter_var($this->overallRealness, FILTER_VALIDATE_BOOLEAN)
            ]);
        }

        // Ensure numeric values are properly typed
        if ($this->has('confidenceScore')) {
            $this->merge(['confidenceScore' => (float) $this->confidenceScore]);
        }

        if ($this->has('totalWindows')) {
            $this->merge(['totalWindows' => (int) $this->totalWindows]);
        }

        if ($this->has('realWindows')) {
            $this->merge(['realWindows' => (int) $this->realWindows]);
        }
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'failed_rules' => $validator->failed(),
            ], 422)
        );
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Custom validation: realWindows should not exceed totalWindows
            if ($this->filled(['totalWindows', 'realWindows'])) {
                if ($this->realWindows > $this->totalWindows) {
                    $validator->errors()->add('realWindows', 'Real windows cannot exceed total windows.');
                }
            }

            // Custom validation: Validate JSON strings in locationStream
            if ($this->has('locationStream.analyzedPath') && $this->input('locationStream.analyzedPath')) {
                $analyzedPath = $this->input('locationStream.analyzedPath');
                if (!is_null(json_decode($analyzedPath)) === false && json_last_error() !== JSON_ERROR_NONE) {
                    $validator->errors()->add('locationStream.analyzedPath', 'Analyzed path must be valid JSON.');
                }
            }

            if ($this->has('locationStream.tripLegitimacy') && $this->input('locationStream.tripLegitimacy')) {
                $tripLegitimacy = $this->input('locationStream.tripLegitimacy');
                if (!is_null(json_decode($tripLegitimacy)) === false && json_last_error() !== JSON_ERROR_NONE) {
                    $validator->errors()->add('locationStream.tripLegitimacy', 'Trip legitimacy must be valid JSON.');
                }
            }

            // Validate that suspicious windows indices are within the window count range
            // if ($this->has('sensorSummaries')) {
            //     foreach ($this->sensorSummaries as $index => $sensorSummary) {
            //         if (isset($sensorSummary['suspiciousWindows']) && isset($sensorSummary['windowCount'])) {
            //             foreach ($sensorSummary['suspiciousWindows'] as $windowIndex => $suspiciousWindow) {
            //                 if (isset($suspiciousWindow['windowIndex']) && 
            //                     $suspiciousWindow['windowIndex'] >= $sensorSummary['windowCount']) {
            //                     $validator->errors()->add(
            //                         "sensorSummaries.{$index}.suspiciousWindows.{$windowIndex}.windowIndex",
            //                         'Window index cannot exceed the total window count for this sensor.'
            //                     );
            //                 }
            //             }
            //         }
            //     }
            // }
        });
    }
}

// Usage example in controller:
/*

*/