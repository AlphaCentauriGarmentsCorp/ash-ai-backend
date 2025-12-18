<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DesignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
       return [
            'id'                 => $this->id,
            'artist_id'          => $this->artist_id,
            'po_number'          => $this->po_number,
            'design_name'        => $this->design_name,
            'type_printing_method'=> $this->type_printing_method,
            'resolution'         => $this->resolution,
            'color_count'        => $this->color_count,
            'mockup_files'       => $this->mockup_files,
            'production_files'   => $this->production_files,
            'design_placements'  => $this->design_placements,
            'color_palette'      => $this->color_palette,
            'notes'              => $this->notes,
            'status'             => $this->status,
            'version'            => $this->version,
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];

    }
}
