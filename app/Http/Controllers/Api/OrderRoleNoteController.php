<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRoleNote\StoreOrderRoleNote;
use App\Models\Order;
use App\Services\OrderRoleNoteService;

/**
 * Role-directed order notes — HTTP layer.
 *
 * One endpoint: Hub reviewers (permission:access.production-review, see
 * routes/api.php) POST an instruction entry aimed at a production role.
 * READS deliberately have no endpoint — the Hub receives all threads
 * inside GET /orders/{id}/stage-reviews, and each portal receives its own
 * thread inside its context payload, so no new read-permission surface.
 */
class OrderRoleNoteController extends Controller
{
    public function __construct(
        protected OrderRoleNoteService $service,
    ) {
    }

    /**
     * POST /api/v2/orders/{orderId}/role-notes
     */
    public function store(StoreOrderRoleNote $request, int $orderId)
    {
        $order = Order::find($orderId);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $data = $request->validated();

        $note = $this->service->create(
            $order,
            $request->user(),
            $data['audience_role'],
            $data['body'],
        );

        return response()->json([
            'message' => 'Instruction posted.',
            'note'    => $this->service->summarize($note),
        ], 201);
    }
}
