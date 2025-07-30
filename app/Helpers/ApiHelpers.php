<?php
use Illuminate\Support\Facades\Log;


function apiResponse($data = null, $message = "Success", $status = 200)
{
    Log::info($message, [$data]);

    return response()->json([
        "success" => true,
        "message" => $message,
        "data" => $data,
    ], $status);
}


function apiError($message = "Something went wrong", $status = 500, $errors = [], $exception=[])
{

    Log::error($message, [$exception]);

    return response()->json([
        "success" => false,
        "message" => $message,
        "errors" => $errors,
    ], $status);


}

function storeImage($request, $path, $name) {

    $image = $request->file("image");

    if(!$image) return null;

    $image_name = $name . "_" . time() . "." . $image->getClientOriginalExtension();
           
    $request->file("image")->storeAs($path, $image_name, 'public');

    return $image_name;
}

use Illuminate\Support\Facades\Crypt;

function decryptJs($encryptedData)
{
    if (!$encryptedData) {
        throw new \Exception('No encrypted data provided');
    }

    // Decode base64
    $combined = base64_decode($encryptedData);
    
    // Extract IV (first 16 bytes) and encrypted data (rest)
    $iv = substr($combined, 0, 16);
    $encrypted = substr($combined, 16);
    
    // Get the raw key (same as your frontend)
    $key = config('app.key');
    if (strpos($key, 'base64:') === 0) {
        $key = base64_decode(substr($key, 7));
    }
    
    // Decrypt using raw AES-CBC
    $decryptedData = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    
    if ($decryptedData === false) {
        throw new \Exception('Decryption failed');
    }
    
    $parsedData = json_decode($decryptedData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Invalid JSON in decrypted data');
    }
    return $parsedData;
}
