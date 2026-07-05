<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderRoleNote;
use App\Models\User;
use App\Support\WorkflowStages;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * OrderRoleNoteService — the Hub → portal "instructions" channel.
 *
 * An order carries one append-only instruction thread PER production role
 * (audience_role). Hub reviewers write; the target role's portal reads its
 * own thread via the portal context payload; the Hub reads all threads via
 * the stage-reviews payload. Entries are immutable.
 *
 * Posting an entry notifies every user holding the audience role (in-app
 * bell), so instructions are seen without the role having to poll.
 */
class OrderRoleNoteService
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    /**
     * The role slugs a note may target — the unique set of stage-owning
     * roles from the canonical workflow definition.
     *
     * @return string[]
     */
    public static function allowedRoles(): array
    {
        return array_values(array_unique(array_column(WorkflowStages::all(), 'role')));
    }

    /**
     * Append an instruction entry to the order's thread for a role and
     * notify the users holding that role.
     */
    public function create(Order $order, User $author, string $audienceRole, string $body): OrderRoleNote
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['An instruction cannot be empty.'],
            ]);
        }

        if (! in_array($audienceRole, self::allowedRoles(), true)) {
            throw ValidationException::withMessages([
                'audience_role' => ["'{$audienceRole}' is not a valid production role."],
            ]);
        }

        $note = OrderRoleNote::create([
            'order_id'       => $order->id,
            'audience_role'  => $audienceRole,
            'author_user_id' => $author->id,
            'body'           => $body,
        ]);

        $this->notifications->roleNoteCreated($order, $note);

        return $note->load('author:id,name');
    }

    /**
     * Every thread on the order, grouped by audience_role, chronological
     * within each thread. Shape mirrors StageReviewService::historyForOrder
     * so the Hub frontend consumes both the same way.
     *
     * @return Collection<string, Collection<int, array>>
     */
    public function forOrderGrouped(int $orderId): Collection
    {
        return OrderRoleNote::where('order_id', $orderId)
            ->with('author:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy('audience_role')
            ->map(fn ($rows) => $rows->map(fn ($n) => $this->summarize($n))->values());
    }

    /**
     * One role's thread on the order, chronological. Rides the role's
     * portal context payload (Graphic Artist first).
     *
     * @return Collection<int, array>
     */
    public function forRole(int $orderId, string $role): Collection
    {
        return OrderRoleNote::where('order_id', $orderId)
            ->forRole($role)
            ->with('author:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($n) => $this->summarize($n))
            ->values();
    }

    /**
     * Flat array shape for payloads — mirrors StageReviewService::summarize.
     */
    public function summarize(OrderRoleNote $note): array
    {
        return [
            'id'            => $note->id,
            'order_id'      => $note->order_id,
            'audience_role' => $note->audience_role,
            'body'          => $note->body,
            'author'        => $note->relationLoaded('author') && $note->author
                ? ['id' => $note->author->id, 'name' => $note->author->name]
                : ['id' => $note->author_user_id, 'name' => null],
            'created_at'    => $note->created_at?->toDateTimeString(),
        ];
    }
}
