<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\SupplierService;
use App\Http\Requests\Supplier\Store;
use App\Http\Requests\Supplier\Update;
use App\Http\Resources\SupplierResource;

class SupplierController extends Controller
{
    protected $service;

    public function __construct(SupplierService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $supplier = $this->service->getAll();
        return SupplierResource::collection($supplier);
    }

    public function show($id)
    {
        $supplier = $this->service->find($id);
        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }
        return new SupplierResource($supplier);
    }

    public function store(Store $request)
    {
        $supplier = $this->service->create($request->validated());
        return new SupplierResource($supplier);
    }

    public function update(Update $request, $id)
    {
        $supplier = $this->service->update($request->validated(), $id);
        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }

        return new SupplierResource($supplier);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }

        return response()->json(['message' => 'Supplier deleted successfully']);
    }
}
