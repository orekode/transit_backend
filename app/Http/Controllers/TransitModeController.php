<?php

namespace App\Http\Controllers;

use App\Models\TransitMode;
use Illuminate\Http\Request;
use App\Http\Requests\StoreTransitModeRequest;
use App\Http\Requests\UpdateTransitModeRequest;
use App\Http\Resources\TransitModeResource;

class TransitModeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {

            return apiResponse(
                TransitModeResource::collection(TransitMode::where("state", "active")->paginate(20)),
                "Transit modes retrieved successfully"
            );
        }
        catch(\Excpetion $e) {
            return apiError("Unable to retrieve transit modes", exception: [$e]);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTransitModeRequest $request)
    {
        try {
            
            $image_name = storeImage($request, "images/transit_modes", $request->name);
    
            return apiResponse(
                new TransitModeResource(
                        TransitMode::create([
                            "name" => $request->name,
                            "reward_per_km" => $request->reward_per_km,
                            "image" => $image_name
                        ])
                    )
            , "Transit mode created successfully");
        }
        catch(\Excpetion $e) {
            return apiError("Unable to create transit mode", exception:[$request, $e]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(TransitMode $transitMode)
    {
        try {
    
            return apiResponse(
                new TransitModeResource($transitMode),
                "Transit mode retrieved successfully"
            );
        }
        catch(\Excpetion $e) {
            return apiError("Unable to provide transit mode", exception:[$e]);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTransitModeRequest $request, TransitMode $transitMode)
    {
        try {
            $image_name = storeImage($request, "images/transit_modes", $request->name);

            $check = TransitMode::where("name", $request->name)->first();

            if($check and $check->name != $transitMode->name) {
                throw Exception("Transit mode already exists");
                return;
            }

            $transitMode->update([
                "name" => $request->name ?? $transitMode->name,
                "reward_per_km" => $request->reward_per_km ?? $transitMode->reward_per_km,
                "image" => $image_name ?? $transitMode->image
            ]);

            return apiResponse(
                new TransitModeResource($transitMode),
                "Transit mode updated successfully"
            );
        }
        catch(\Excpetion $e) {
            return apiError("Unable to update transit mode", exception:[$request, $e]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TransitMode $transitMode)
    {
        try {
            $transitMode->delete();
            return apiResponse($transitMode, "Transit mode deleted successfully");
        }
        catch(\Excpetion $e) {
            return apiError("Unable to delete transit mode", exception:[$e]);
        }
    }
}
