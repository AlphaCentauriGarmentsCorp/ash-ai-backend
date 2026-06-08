<?php

namespace App\Http\Requests\Order;

/**
 * OrderUpdateRequest — validation for editing an existing order.
 *
 * The accepted shape is identical to creating one (same fields, same
 * superadmin override inputs, same JSON-blob decoding), so we extend
 * StoreOrderRequest rather than duplicate ~80 rules. The editability
 * boundary (order not yet in production) and the superadmin-only override
 * are enforced in OrdersController::update.
 */
class OrderUpdateRequest extends StoreOrderRequest
{
    //
}
