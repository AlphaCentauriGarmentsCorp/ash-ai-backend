<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2 — In-app notifications REST surface.
 *
 * Endpoints (all require auth, all scoped to the current user):
 *   GET    /v2/notifications                  – paginated inbox
 *   GET    /v2/notifications/recent           – top 10 for the bell dropdown
 *   GET    /v2/notifications/unread-count     – just a count
 *   POST   /v2/notifications/{id}/read        – mark one as read
 *   POST   /v2/notifications/read-all         – mark all as read
 *   DELETE /v2/notifications/{id}             – delete one
 *
 * No admin endpoints – users only ever see their own notifications.
 */
class NotificationsController extends Controller
{
    protected NotificationService $service;

    public function __construct(NotificationService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        $page = $this->service->listForUser($userId, $perPage);

        return response()->json([
            'data'         => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
            'unread_count' => $this->service->unreadCount($userId),
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min(50, $limit));

        return response()->json([
            'data'         => $this->service->recentForUser($userId, $limit),
            'unread_count' => $this->service->unreadCount($userId),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->service->unreadCount($request->user()->id),
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $n = $this->service->markRead($id, $request->user()->id);

        if (! $n) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        return response()->json([
            'message'      => 'Marked as read.',
            'notification' => $n,
            'unread_count' => $this->service->unreadCount($request->user()->id),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->service->markAllRead($request->user()->id);

        return response()->json([
            'message'      => "Marked {$count} notification(s) as read.",
            'updated'      => $count,
            'unread_count' => 0,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $this->service->delete($id, $request->user()->id);

        if (! $deleted) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        return response()->json([
            'message'      => 'Deleted.',
            'unread_count' => $this->service->unreadCount($request->user()->id),
        ]);
    }
}
