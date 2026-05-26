<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Client\ClientStoreRequest;
use App\Http\Requests\Client\ClientUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBrand;
use Illuminate\Http\Request;
use App\Services\ClientService;
use App\Http\Resources\ClientResource;

class ClientController extends Controller
{
    protected $service;

    public function __construct(ClientService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $clients = Client::with('brands')->get();
        return ClientResource::collection($clients);
    }

    public function store(ClientStoreRequest $request)
    {
        $client = $this->service->create($request->validated());
        $client->load('brands');
        return new ClientResource($client);
    }

    public function show(Client $clients, $id)
    {
        $clients = $this->service->find($id);
        if (!$clients) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $clients->load(['brands', 'orders']);
        return new ClientResource($clients);
    }

    public function update(ClientUpdateRequest $request, $id)
    {
        $client = $this->service->update($id, $request->validated());
        if (!$client) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new ClientResource($client);
    }

    /**
     * Issue 1 — add a brand to a client on the fly (from the quotation form).
     * Creates a client_brands row and returns the refreshed client (with its
     * full brand list) so the frontend can repopulate the Brand dropdown and
     * auto-select the new one. Idempotent on (client_id, brand_name): an
     * existing brand of the same name is returned rather than duplicated.
     */
    public function storeBrand(Request $request, $id)
    {
        $client = Client::find($id);
        if (! $client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        $validated = $request->validate([
            'brand_name' => 'required|string|max:255',
            'logo_url'   => 'nullable|string|max:1000',
        ]);

        $brandName = trim($validated['brand_name']);

        $brand = ClientBrand::firstOrCreate(
            ['client_id' => $client->id, 'brand_name' => $brandName],
            ['logo_url' => $validated['logo_url'] ?? null]
        );

        $client->load('brands');

        return (new ClientResource($client))
            ->additional(['created_brand' => $brand->brand_name])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}