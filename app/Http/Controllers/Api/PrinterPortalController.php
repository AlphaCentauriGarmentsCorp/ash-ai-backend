<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Printer\StoreInkLog;
use App\Http\Requests\Printer\StoreSampleUpload;
use App\Services\InkLogService;
use App\Services\PrinterPortalService;
use App\Services\SampleUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 5-C — Printer Portal HTTP endpoints.
 *
 * Endpoints (mirroring Cutter's shape):
 *   GET    /api/v2/portal/printer/context/{orderStageId}
 *   POST   /api/v2/portal/printer/ink-logs              (JSON)
 *   DELETE /api/v2/portal/printer/ink-logs/{id}
 *   POST   /api/v2/portal/printer/sample-uploads        (multipart)
 *   PATCH  /api/v2/portal/printer/sample-uploads/{id}
 *   DELETE /api/v2/portal/printer/sample-uploads/{id}
 *
 * Sample upload writes reuse SampleUploadService (shared with Cutter).
 */
class PrinterPortalController extends Controller
{
    public function __construct(
        protected PrinterPortalService $context,
        protected InkLogService $inkLogs,
        protected SampleUploadService $sampleUploads,
    ) {
    }

    public function showContext(int $orderStageId)
    {
        $payload = $this->context->buildContext($orderStageId);
        return response()->json(['data' => $payload]);
    }

    // ── Ink logs ─────────────────────────────────────────────

    public function storeInkLog(StoreInkLog $request)
    {
        $log = $this->inkLogs->create($request->validated(), $request->user());
        return response()->json([
            'data' => [
                'id'                  => $log->id,
                'order_id'            => $log->order_id,
                'order_stage_id'      => $log->order_stage_id,
                'ink_color'           => $log->ink_color,
                'ink_used_kg'         => (float) $log->ink_used_kg,
                'ink_waste_kg'        => (float) $log->ink_waste_kg,
                'usable_remaining_kg' => (float) $log->usable_remaining_kg,
                'notes'               => $log->notes,
                'created_at'          => $log->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    public function destroyInkLog(int $id, Request $request)
    {
        $this->inkLogs->delete($id, $request->user());
        return response()->json(['message' => 'Ink log deleted'], 200);
    }

    // ── Sample uploads ───────────────────────────────────────

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

    // ── Helpers ──────────────────────────────────────────────

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
