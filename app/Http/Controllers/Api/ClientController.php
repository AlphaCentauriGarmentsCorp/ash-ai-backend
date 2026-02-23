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
        return new ClientResource($client);
    }

    public function show(Client $clients)
    {
        $clients->load('brands');
        return new ClientResource($clients);
    }

    public function update(ClientUpdateRequest $request, Client $clients)
    {
        $client = $this->service->update($clients->id, $request->validated());
        if (!$client) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new ClientResource($client);
    }


    public function destroy(Client $clients)
    {
        $deleted = $this->service->delete($clients->id);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
