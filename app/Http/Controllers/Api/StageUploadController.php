<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StageUpload\StoreStageUpload;
use App\Models\OrderStage;
use App\Models\StageUpload;
use App\Services\StageUploadService;

/**
 * Phase 3 — generic per-stage proof-of-work uploads (HTTP layer).
 *
 * Route gating (see routes/api.php):
 *   - list   : permission:access.orders        (anyone who can see the order)
 *   - store  : permission:action.upload-photos  (the producing roles)
 *   - delete : permission:action.upload-photos
 */
class StageUploadController extends Controller
{
    public function __construct(
        protected StageUploadService $service,
    ) {
    }

    /**
     * GET /api/v2/order-stages/{id}/uploads
     */
    public function index(int $id)
    {
        OrderStage::findOrFail($id);

        return response()->json([
            'order_stage_id' => $id,
            'uploads'        => $this->service->forStage($id),
        ]);
    }

    /**
     * POST /api/v2/order-stages/{id}/uploads  (multipart/form-data)
     */
    public function store(StoreStageUpload $request, int $id)
    {
        $data = $request->validated();

        $upload = $this->service->store(
            $id,
            $request->user(),
            $request->file('file'),
            $data['category'] ?? 'proof',
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => 'File uploaded.',
            'upload'  => $this->service->summarize($upload->load('uploadedBy:id,name')),
        ], 201);
    }

    /**
     * DELETE /api/v2/stage-uploads/{uploadId}
     *
     * Only the original uploader (or a manager, via the broader permission)
     * should remove an attachment. We enforce uploader-or-privileged here.
     */
    public function destroy(int $uploadId)
    {
        $upload = StageUpload::find($uploadId);
        if (! $upload) {
            return response()->json(['message' => 'Upload not found.'], 404);
        }

        $user = request()->user();
        $isOwner = $user && (int) $upload->uploaded_by_user_id === (int) $user->id;
        $isPrivileged = $user && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['superadmin', 'admin', 'general_manager']);

        if (! $isOwner && ! $isPrivileged) {
            return response()->json([
                'message' => 'You can only delete your own uploads.',
            ], 403);
        }

        $this->service->delete($uploadId);

        return response()->json(['message' => 'Upload deleted.']);
    }
}
