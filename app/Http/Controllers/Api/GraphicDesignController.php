<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GraphicEditing\Store;
use App\Services\GraphicEditingService;
use App\Http\Resources\OrderDesignResource;

class GraphicDesignController extends Controller
{
    protected $service;

    public function __construct(GraphicEditingService $service)
    {
        $this->service = $service;
    }

    public function store(Store $request)
    {
        $design = $this->service->create($request->validated());
        return new OrderDesignResource($design->load('placements'));
    }
}
