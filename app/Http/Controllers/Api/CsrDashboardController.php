<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CsrActivityLogger;
use App\Services\CsrDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CsrDashboardController — single GET endpoint that returns the
 * full dashboard payload. Plus a secondary `activityLog` endpoint
 * for the cross-cutting CSR audit page (route: GET /csr/activity-log).
 */
class CsrDashboardController extends Controller
{
    public function __construct(
        protected CsrDashboardService $dashboard,
        protected CsrActivityLogger   $activityLogger,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->dashboard->summary(),
        ]);
    }

    public function activityLog(Request $request): JsonResponse
    {
        $logs = $this->activityLogger->recent([
            'order_id'  => $request->integer('order_id')  ?: null,
            'client_id' => $request->integer('client_id') ?: null,
            'user_id'   => $request->integer('user_id')   ?: null,
            'limit'     => $request->integer('limit')     ?: 50,
        ]);

        return response()->json([
            'data' => $logs->map(fn ($log) => [
                'id'         => $log->id,
                'user_id'    => $log->user_id,
                'action'     => $log->action,
                'summary'    => $log->summary,
                'order_id'   => $log->order_id,
                'client_id'  => $log->client_id,
                'subject_type' => $log->subject_type,
                'subject_id'   => $log->subject_id,
                'data'       => $log->data,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
