<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cutter\StoreFabricLog;
use App\Http\Requests\Cutter\StoreSampleUpload;
use App\Services\CutterPortalService;
use App\Services\FabricLogService;
use App\Services\SampleUploadService;
use Illuminate\Http\Request;

/**
 * Phase 5-B — HTTP layer for Cutter portal.
 *
 * Endpoints:
 *   GET    /api/v2/portal/cutter/context/{orderStageId}
 *   POST   /api/v2/portal/cutter/fabric-logs        (JSON)
 *   DELETE /api/v2/portal/cutter/fabric-logs/{id}
 *   POST   /api/v2/portal/cutter/sample-uploads     (multipart)
 *   PATCH  /api/v2/portal/cutter/sample-uploads/{id}
 *   DELETE /api/v2/portal/cutter/sample-uploads/{id}
 *
 * Permission gating is via portal.cutter on the route group.
 * Stage-state + per-permission checks happen in the services.
 */
class CutterPortalController extends Controller
{
    public function __construct(
        protected CutterPortalService $context,
        protected FabricLogService $fabricLogs,
        protected SampleUploadService $sampleUploads,
    ) {
    }

    /**
     * GET /portal/cutter/context/{orderStageId}
     * Returns the full portal payload for one stage.
     */
    public function showContext(int $orderStageId)
    {
        $payload = $this->context->buildContext($orderStageId);
        return response()->json(['data' => $payload]);
    }

    // ── Fabric logs ──────────────────────────────────────────────────

    /**
     * POST /portal/cutter/fabric-logs (JSON)
     */
    public function storeFabricLog(StoreFabricLog $request)
    {
        $log = $this->fabricLogs->create($request->validated(), $request->user());
        return response()->json([
            'data' => [
                'id'                  => $log->id,
                'order_id'            => $log->order_id,
                'order_stage_id'      => $log->order_stage_id,
                'fabric_used_kg'      => (float) $log->fabric_used_kg,
                'waste_kg'            => (float) $log->waste_kg,
                'usable_remaining_kg' => (float) $log->usable_remaining_kg,
                'fabric_roll_id'      => $log->fabric_roll_id,
                'notes'               => $log->notes,
                'created_at'          => $log->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    public function destroyFabricLog(int $id, Request $request)
    {
        $this->fabricLogs->delete($id, $request->user());
        return response()->json(['message' => 'Fabric log deleted'], 200);
    }

    // ── Sample uploads ───────────────────────────────────────────────

    /**
     * POST /portal/cutter/sample-uploads (multipart/form-data)
     */
    public function storeSampleUpload(StoreSampleUpload $request)
    {
        $data = $request->validated();

        if ($request->hasFile('photo_front') && $request->file('photo_front')->isValid()) {
            $data['photo_front_path'] = $request->file('photo_front')
                ->store('sample-uploads/front', 'public');
        }
        if ($request->hasFile('photo_back') && $request->file('photo_back')->isValid()) {
            $data['photo_back_path'] = $request->file('photo_back')
                ->store('sample-uploads/back', 'public');
        }
        unset($data['photo_front'], $data['photo_back']);

        $upload = $this->sampleUploads->create($data, $request->user());

        return response()->json([
            'data' => $this->presentSampleUpload($upload->fresh()),
        ], 201);
    }

    /**
     * PATCH /portal/cutter/sample-uploads/{id}
     * For re-uploads + status flips (mark-as-done).
     */
    public function updateSampleUpload(int $id, Request $request)
    {
        $data = $request->validate([
            'remarks'       => 'nullable|string|max:1000',
            'sample_status' => 'nullable|in:pending,for_approval',
            'photo_front'   => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
            'photo_back'    => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($request->hasFile('photo_front') && $request->file('photo_front')->isValid()) {
            $data['photo_front_path'] = $request->file('photo_front')
                ->store('sample-uploads/front', 'public');
        }
        if ($request->hasFile('photo_back') && $request->file('photo_back')->isValid()) {
            $data['photo_back_path'] = $request->file('photo_back')
                ->store('sample-uploads/back', 'public');
        }
        unset($data['photo_front'], $data['photo_back']);

        $upload = $this->sampleUploads->update($id, $data, $request->user());

        return response()->json([
            'data' => $this->presentSampleUpload($upload),
        ]);
    }

    public function destroySampleUpload(int $id, Request $request)
    {
        $this->sampleUploads->delete($id, $request->user());
        return response()->json(['message' => 'Sample upload deleted'], 200);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function presentSampleUpload($upload): array
    {
        return [
            'id'                => $upload->id,
            'order_id'          => $upload->order_id,
            'order_stage_id'    => $upload->order_stage_id,
            'photo_front_path'  => $upload->photo_front_path,
            'photo_back_path'   => $upload->photo_back_path,
            'photo_front_url'   => $upload->photo_front_path
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($upload->photo_front_path)
                : null,
            'photo_back_url'    => $upload->photo_back_path
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($upload->photo_back_path)
                : null,
            'remarks'           => $upload->remarks,
            'sample_status'     => $upload->sample_status,
            'completed_at'      => $upload->completed_at?->toDateTimeString(),
            'created_at'        => $upload->created_at?->toDateTimeString(),
        ];
    }
}
