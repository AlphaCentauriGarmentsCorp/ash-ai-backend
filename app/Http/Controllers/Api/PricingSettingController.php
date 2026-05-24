<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PricingSetting\Update;
use App\Http\Resources\PricingSettingResource;
use App\Services\PricingSettingService;

class PricingSettingController extends Controller
{
    protected $service;

    public function __construct(PricingSettingService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $settings = $this->service->getAll();

        return PricingSettingResource::collection($settings);
    }

    public function update(Update $request, $id)
    {
        $setting = $this->service->update($request->validated(), $id);

        if (! $setting) {
            return response()->json(['message' => 'Pricing setting not found'], 404);
        }

        return new PricingSettingResource($setting);
    }
}
