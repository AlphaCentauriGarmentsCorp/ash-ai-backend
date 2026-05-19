<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Csr\RecordApproval;
use App\Services\ClientApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientApprovalController extends Controller
{
    public function __construct(
        protected ClientApprovalService $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->approvals->list([
            'order_id' => $request->integer('order_id') ?: null,
            'kind'     => $request->string('kind')->value() ?: null,
            'status'   => $request->string('status')->value() ?: null,
        ]);

        return response()->json([
            'data' => $list->map(fn ($a) => $this->approvals->present($a))->all(),
        ]);
    }

    /**
     * POST /csr/approvals — open a new approval request (status: waiting).
     */
    public function store(RecordApproval $request): JsonResponse
    {
        $data = $request->validated();

        $approval = $this->approvals->record(
            $data['order_id'],
            $data,
            $request->file('screenshot'),
        );

        return response()->json([
            'data' => $this->approvals->present($approval),
        ], 201);
    }

    /**
     * PATCH /csr/approvals/{id}/respond — record the client's response.
     */
    public function respond(RecordApproval $request, int $id): JsonResponse
    {
        $data = $request->validated();

        $approval = $this->approvals->respond(
            $id,
            $data['decision'],
            $data,
            $request->file('screenshot'),
        );

        return response()->json([
            'data' => $this->approvals->present($approval),
        ]);
    }
}
