<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Csr\StoreFabricSwatch;
use App\Services\FabricSwatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FabricSwatchController extends Controller
{
    public function __construct(
        protected FabricSwatchService $swatches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->swatches->list([
            'fabric_type'  => $request->string('fabric_type')->value() ?: null,
            'gsm'          => $request->integer('gsm') ?: null,
            'collection'   => $request->string('collection')->value() ?: null,
            'supplier_id'  => $request->integer('supplier_id') ?: null,
            'color_family' => $request->string('color_family')->value() ?: null,
        ]);

        return response()->json([
            'data' => $list->map(fn ($s) => $this->swatches->present($s))->all(),
        ]);
    }

    public function store(StoreFabricSwatch $request): JsonResponse
    {
        $swatch = $this->swatches->create($request->validated(), $request->file('photo'));

        return response()->json([
            'data' => $this->swatches->present($swatch),
        ], 201);
    }

    public function update(StoreFabricSwatch $request, int $id): JsonResponse
    {
        $swatch = $this->swatches->update($id, $request->validated(), $request->file('photo'));

        return response()->json([
            'data' => $this->swatches->present($swatch),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->swatches->delete($id);

        return response()->json(null, 204);
    }
}
