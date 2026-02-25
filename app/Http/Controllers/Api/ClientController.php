<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Client\ClientStoreRequest;
use App\Http\Requests\Client\ClientUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Client;
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

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
