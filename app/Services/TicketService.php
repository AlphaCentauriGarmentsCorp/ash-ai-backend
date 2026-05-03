<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class TicketService
{
    private const DISK = 'public';
    private const FILE_DIR = 'tickets';

    public function getAll(): Collection
    {
        return Ticket::all();
    }

    public function getFromRoles(): array
    {
        return Ticket::query()
            ->whereNotNull('from_role')
            ->distinct()
            ->orderBy('from_role')
            ->pluck('from_role')
            ->values()
            ->all();
    }

    public function getToRoles(): array
    {
        return Ticket::query()
            ->whereNotNull('to_role')
            ->distinct()
            ->orderBy('to_role')
            ->pluck('to_role')
            ->values()
            ->all();
    }

    public function getTicketsByRole(string $role): array
    {
        return [
            'incoming' => Ticket::query()
                ->where('to_role', $role)
                ->orderByDesc('date_created')
                ->get(),
            'outgoing' => Ticket::query()
                ->where('from_role', $role)
                ->orderByDesc('date_created')
                ->get(),
        ];
    }

    public function find(int $id): ?Ticket
    {
        return Ticket::find($id);
    }

    public function create(array $data): Ticket
    {
        $data['date_created'] = now();
        $data['attachments'] = $this->uploadAttachments($data['attachments'] ?? []);

        return Ticket::create($data);
    }

    public function update(array $data, int $id): ?Ticket
    {
        $ticket = Ticket::find($id);

        if (! $ticket) {
            return null;
        }

        $existing = $ticket->attachments ?? [];
        $new = $this->uploadAttachments($data['attachments'] ?? []);

        $data['attachments'] = array_merge($existing, $new);
        unset($data['date_created']);

        $ticket->update($data);

        return $ticket;
    }

    public function delete(int $id): bool
    {
        $ticket = Ticket::find($id);

        if (! $ticket) {
            return false;
        }

        foreach ($ticket->attachments ?? [] as $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $ticket->delete();
    }

    /**
     * @param  \Illuminate\Http\UploadedFile[]  $files
     * @return string[]
     */
    private function uploadAttachments(array $files): array
    {
        return array_map(
            fn ($file) => $file->store(self::FILE_DIR, self::DISK),
            $files
        );
    }
}