<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;


class StoreTripRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tripDuration' => 'required|integer|min:0',
            'location' => 'required|array',
            'location.varianceScore' => 'required|numeric',
            'location.idleTime' => 'required|numeric',
            'location.idlePercentage' => 'required|numeric',
            'location.sampleCount' => 'required|integer',
            'location.unreasonableJumps' => 'required|integer',
            'gyroscope' => 'required|array',
            'gyroscope.varianceScore' => 'required|numeric',
            'gyroscope.idleTime' => 'required|numeric',
            'gyroscope.idlePercentage' => 'required|numeric',
            'gyroscope.sampleCount' => 'required|integer',
            'accelerometer' => 'required|array',
            'accelerometer.varianceScore' => 'required|numeric',
            'accelerometer.idleTime' => 'required|numeric',
            'accelerometer.idlePercentage' => 'required|numeric',
            'accelerometer.sampleCount' => 'required|integer',
            'network' => 'required|array',
            'network.changeCount' => 'required|integer',
            'network.changesPerHour' => 'required|numeric',
            'summary' => 'required|array',
            'summary.suspiciousActivityScore' => 'required|numeric',
            'summary.totalIdleTime' => 'required|numeric',
            'summary.overallIdlePercentage' => 'required|numeric',
            'photoScore' => 'required',
            'lastPos' => 'required',
            'finalPos' => 'required',
            'startPos' => 'required',
            'mode' => 'required',
            'wallet' => 'required',
        ];
    }

    protected function prepareForValidation()
    {
        try {
            $encryptedData = $this->input('encrypted_data');

            // Merge the decrypted data into the request
            $this->merge(decryptJs($encryptedData));
            
        } catch (\Exception $e) {
            throw new HttpResponseException(response()->json([
                'error' => 'Failed to process encrypted data: ' . $e->getMessage()
            ], 400));
        }
    }
}
