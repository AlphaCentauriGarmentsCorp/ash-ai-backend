<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddonCategoriesResource;
use App\Services\AddonCategoriesService;
use App\Http\Requests\AddonCategories\Store;
use App\Http\Requests\AddonCategories\Update;

class AddonCategoriesController extends Controller
{
    protected $service;

    public function __construct(AddonCategoriesService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $addonCategories = $this->service->getAll();
        return AddonCategoriesResource::collection($addonCategories);
    }

    public function store(Store $request)
    {
        $addonCategories = $this->service->create($request->validated());
        return new AddonCategoriesResource($addonCategories);
    }

    public function show($id)
    {
        $addonCategories = $this->service->find($id);
        if (! $addonCategories) {
            return response()->json(['message' => 'Addon category not found'], 404);
        }

        return new AddonCategoriesResource($addonCategories);
    }

    public function update(Update $request, $id)
    {
        $addonCategories = $this->service->update($request->validated(), $id);
        if (! $addonCategories) {
            return response()->json(['message' => 'Addon category not found'], 404);
        }

        return new AddonCategoriesResource($addonCategories);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Addon category not found'], 404);
        }

        return response()->json(['message' => 'Addon category deleted successfully']);
    }
}
