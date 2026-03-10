<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ScreenServices;
use App\Http\Resources\ScreenResources;
use App\Http\Requests\Screens\Store;
use App\Http\Requests\Screens\Update;

class ScreenController extends Controller
{
    protected $service;

    public function __construct(ScreenServices $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $screens = $this->service->getAll();
        return ScreenResources::collection($screens);
    }

    public function show($id)
    {
        $screen = $this->service->find($id);
        if (! $screen) {
            return response()->json(['message' => 'Screen not found'], 404);
        }
        return new ScreenResources($screen);
    }

    public function store(Store $request)
    {
        $screens = $this->service->create($request->validated());
        return new ScreenResources($screens);
    }

    public function update(Update $request, $id)
    {
        $screens = $this->service->update($request->validated(), $id);
        if (! $screens) {
            return response()->json(['message' => 'Screen not found'], 404);
        }

        return new ScreenResources($screens);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Screen not found'], 404);
        }

        return response()->json(['message' => 'Screen deleted successfully']);
    }
}
