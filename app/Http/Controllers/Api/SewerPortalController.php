<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sewer\StoreMaterialLog;
use App\Http\Requests\Sewer\StoreSampleUpload;
use App\Services\SampleUploadService;
use App\Services\SewerMaterialLogService;
use App\Services\SewerPortalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 5-E — Sewer Portal endpoints.
 *
 * Endpoints:
 *   GET    /api/v2/portal/sewer/context/{orderStageId}
 *   POST   /api/v2/portal/sewer/material-logs           (JSON)
 *   DELETE /api/v2/portal/sewer/material-logs/{id}
 *   POST   /api/v2/portal/sewer/sample-uploads          (multipart)
 *   PATCH  /api/v2/portal/sewer/sample-uploads/{id}
 *   DELETE /api/v2/portal/sewer/sample-uploads/{id}
 *
 * Gated by portal.sewer permission.
 */
class SewerPortalController extends Controller
{
    public function __construct(
        protected SewerPortalService $context,
        protected SewerMaterialLogService $materialLogs,
        protected SampleUploadService $sampleUploads,
    ) {
    }

    public function showContext(int $orderStageId)
    {
        $payload = $this->context->buildContext($orderStageId);
        return response()->json(['data' => $payload]);
    }

    // ── Material logs ────────────────────────────────────────────

    public function storeMaterialLog(StoreMaterialLog $request)
    {
        $log = $this->materialLogs->create($request->validated(), $request->user());
        return response()->json([
            'data' => [
                'id'                  => $log->id,
                'order_id'            => $log->order_id,
                'order_stage_id'      => $log->order_stage_id,
                'material_type'       => $log->material_type,
                'fabric_used_kg'      => (float) $log->fabric_used_kg,
                'waste_kg'            => (float) $log->waste_kg,
                'usable_remaining_kg' => (float) $log->usable_remaining_kg,
                'fabric_roll_id'      => $log->fabric_roll_id,
                'notes'               => $log->notes,
                'created_at'          => $log->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    public function destroyMaterialLog(int $id, Request $request)
    {
        $this->materialLogs->delete($id, $request->user());
        return response()->json(['message' => 'Material log deleted'], 200);
    }

    // ── Sample uploads ───────────────────────────────────────────

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

    protected function presentSampleUpload($upload): array
    {
        return [
            'id'                => $upload->id,
            'order_id'          => $upload->order_id,
            'order_stage_id'    => $upload->order_stage_id,
            'photo_front_path'  => $upload->photo_front_path,
            'photo_back_path'   => $upload->photo_back_path,
            'photo_front_url'   => $upload->photo_front_path
                ? Storage::disk('public')->url($upload->photo_front_path)
                : null,
            'photo_back_url'    => $upload->photo_back_path
                ? Storage::disk('public')->url($upload->photo_back_path)
                : null,
            'remarks'           => $upload->remarks,
            'sample_status'     => $upload->sample_status,
            'completed_at'      => $upload->completed_at?->toDateTimeString(),
            'created_at'        => $upload->created_at?->toDateTimeString(),
        ];
    }
}
