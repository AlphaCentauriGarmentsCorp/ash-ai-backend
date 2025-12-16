<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdersPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_id' => $this->po_id,
            'payment_type' => $this->payment_type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'reference_number' => $this->reference_number,
            'proof' => $this->proof,
            'remarks' => $this->remarks,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at,
            'status' => $this->status,
            'created_at'  => $this->created_at?->toDateTimeString(),            
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }
}
