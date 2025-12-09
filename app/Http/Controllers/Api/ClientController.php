<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use App\Http\Requests\Client\ClientStoreRequest;
use App\Http\Requests\Client\ClientUpdateRequest;
use App\Services\ClientService;
use App\Http\Resources\ClientResource;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    protected $service;

    public function __construct(ClientService $service)
    {
        $this->service = $service;
    }
    public function index()
    {
        //
        return ClientResource::collection(
            $this->service->list()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ClientStoreRequest $request)
    {
        
        $method = $this->service->create($request->validated());
        // return $method;
        return [new ClientResource($method), "Client Added Successfully"];

    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {
        //
        return new ClientResource($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ClientUpdateRequest $request, Client $client)
    {
        $result = $this->service->update($client, $request->validated());
        return new ClientResource($result);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client)
    {
        //
        $this->service->delete($client);
        return response()->json(['message' => 'Deleted successfully']);
    }
}
