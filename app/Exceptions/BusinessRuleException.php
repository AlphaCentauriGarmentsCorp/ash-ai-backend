<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * BusinessRuleException — a deliberate, user-facing business-rule failure.
 *
 * Thrown when a request is well-formed (it passed field validation) but
 * violates a domain rule — for example an order with zero line items, or
 * a non-superadmin trying to override required fields.
 *
 * The global handler in bootstrap/app.php renders these as:
 *
 *     { "type": "business", "code": <CODE>, "message": <msg>, "errors": {...} }
 *
 * with the chosen HTTP status (default 422). Unlike a 500 these are
 * EXPECTED failures and carry a stable machine `code` the frontend maps
 * to a specific message — so we never fall back to a generic catch-all.
 *
 * Note: we store the machine code in our own `$errorCode` string rather
 * than the base Exception `$code` (which is an int) to avoid the
 * string-into-int coercion that would otherwise mangle codes like
 * "ORDER_NO_LINE_ITEMS".
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        protected string $errorCode = 'BUSINESS_RULE',
        protected int $status = 422,
        protected array $errorDetails = [],
    ) {
        parent::__construct($message);
    }

    /** Stable machine code the frontend maps to a specific message. */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** HTTP status to render with (default 422). */
    public function status(): int
    {
        return $this->status;
    }

    /** Optional field-level details, same shape as validation `errors`. */
    public function details(): array
    {
        return $this->errorDetails;
    }
}
