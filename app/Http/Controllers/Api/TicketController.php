<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\Store;
use App\Http\Requests\Ticket\Update;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketService;

class TicketController extends Controller
{
    protected $service;

    public function __construct(TicketService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $tickets = $this->service->getAll();

        return TicketResource::collection($tickets);
    }

    public function getFromRoles()
    {
        return response()->json(['data' => $this->service->getFromRoles()]);
    }

    public function getToRoles()
    {
        return response()->json(['data' => $this->service->getToRoles()]);
    }

    public function getByRole(string $role)
    {
        return response()->json([
            'data' => $this->service->getTicketsByRole($role),
        ]);
    }

    public function store(Store $request)
    {
        $ticket = $this->service->create($request->validated());

        return new TicketResource($ticket);
    }

    public function show(Ticket $ticket, $id)
    {
        $ticket = $this->service->find($id);

        if (! $ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        return new TicketResource($ticket);
    }

    public function update(Update $request, $id)
    {
        $ticket = $this->service->update($request->validated(), $id);

        if (! $ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        return new TicketResource($ticket);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        return response()->json(['message' => 'Ticket deleted successfully']);
    }
}