<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PrintMethodService;
use App\Services\ScreenCheckingService;
use App\Http\Requests\ScreenChecking\Store;
use App\Http\Resources\ScreenCheckingResource;

class ScreenCheckingController extends Controller
{
    protected $service;

    public function __construct(ScreenCheckingService $service)
    {
        $this->service = $service;
    }

    public function store(Store $request)
    {
        $screen = $this->service->create($request->validated());
        return  ScreenCheckingResource::collection($screen);
    }
}
