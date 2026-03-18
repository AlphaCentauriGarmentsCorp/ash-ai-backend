<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ScreenMakingService;
use App\Http\Resources\ScreenMakingResource;
use App\Http\Requests\ScreenMaking\Store;


class ScreenMakingController extends Controller
{
    protected $service;

    public function __construct(ScreenMakingService $service)
    {
        $this->service = $service;
    }

    public function store(Store $request)
    {
        $screen = $this->service->create(
            $request->input('assignments')
        );
        return  ScreenMakingResource::collection($screen);
    }
}
