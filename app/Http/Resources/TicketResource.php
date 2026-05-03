<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'request_type' => $this->request_type,
            'quotation_id' => $this->quotation_id,
            'order_id'     => $this->order_id,
            'from_role'    => $this->from_role,
            'to_role'      => $this->to_role,
            'message'      => $this->message,
            'status'       => $this->status,
            'attachments'  => collect($this->attachments ?? [])->map(
                fn ($path) => Storage::disk('public')->url($path)
            ),
            'date_created' => $this->date_created?->toDateTimeString(),
            'created_at'   => $this->created_at?->toDateTimeString(),
            'updated_at'   => $this->updated_at?->toDateTimeString(),
        ];
    }
}