<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ScreenMakerPortalService;

/**
 * Phase 5-F — Screen Maker Portal endpoints.
 *
 * Endpoints:
 *   GET /api/v2/portal/screen-maker/context/{orderStageId}
 *
 * Notes:
 *   - Screen Maker is mostly read-only. There are no portal-specific
 *     write endpoints — staff use the existing OrderStagesController
 *     to set notes and mark the stage complete.
 *   - The frontend may invoke orderStagesApi.setNotes() and
 *     orderStagesApi.markAsDone() (or whatever the project's "advance"
 *     endpoint is) directly from the page.
 *
 * Gated by portal.screen-maker permission (note the hyphen).
 */
class ScreenMakerPortalController extends Controller
{
    public function __construct(
        protected ScreenMakerPortalService $context,
    ) {
    }

    public function showContext(int $orderStageId)
    {
        $payload = $this->context->buildContext($orderStageId);
        return response()->json(['data' => $payload]);
    }
}
