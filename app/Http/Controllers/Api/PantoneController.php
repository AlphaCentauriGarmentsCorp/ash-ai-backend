<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pantone\Store;
use App\Http\Requests\Pantone\Update;
use App\Http\Resources\PantoneResource;
use App\Models\Pantone;
use App\Services\PantoneService;
use Illuminate\Http\Request;

class PantoneController extends Controller
{
    // Show all Pantones
    public function index()
    {
        $pantones = Pantone::all();  // You could also use PantoneService here
        return PantoneResource::collection($pantones);  // Return Pantone data as a JSON collection
    }

    // Store a new Pantone
    public function store(Store $request)
    {
        // The request will automatically validate due to the Store FormRequest
        $pantone = Pantone::create($request->validated());  // Use validated() to ensure only valid data is saved
        return new PantoneResource($pantone);  // Return the created Pantone as a resource
    }

    // Show a single Pantone
    public function show($id)
    {
        $pantone = Pantone::findOrFail($id);  // Find or fail to ensure the Pantone exists
        return new PantoneResource($pantone);  // Return as a single resource
    }

    // Update a Pantone
    public function update(Update $request, $id)
    {
        $pantone = Pantone::findOrFail($id);  // Find the Pantone by ID
        $pantone->update($request->validated());  // Update with validated data
        return new PantoneResource($pantone);  // Return the updated Pantone as a resource
    }

    // Delete a Pantone
    public function destroy($id)
    {
        $pantone = Pantone::findOrFail($id);  // Find Pantone
        $pantone->delete();  // Delete Pantone
        return response()->json(null, 204);  // No content response for successful delete
    }
}