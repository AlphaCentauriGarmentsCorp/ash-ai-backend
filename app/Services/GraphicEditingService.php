<?php

namespace App\Services;

use App\Models\Freebie;
use Illuminate\Database\Eloquent\Collection;
use App\Models\OrderDesign;
use App\Models\OrderStage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GraphicEditingService
{

    public function create(array $data): OrderDesign
    {
        $artistId = Auth::id();

        OrderStage::where('order_id', $data['order_id'])
            ->where('stage', 'graphic_editing')
            ->update(['status' => 'completed']);

        $design = OrderDesign::updateOrCreate(
            ['order_id' => $data['order_id'], 'artist_id' => $artistId],
            ['notes' => $data['notes'] ?? null]
        );

        $order = $design->order;
        $folder = "Orders/{$order->po_code}/graphic_designs";

        if (request()->hasFile('size_label')) {
            $path = $this->uploadFile(request()->file('size_label'), $folder);
            $design->update(['size_label' => $path]);
        }

        $existingPlacements = $design->placements()->get()->keyBy('type');
    
        if (!empty($data['placements'])) {
            foreach ($data['placements'] as $index => $placement) {

                $type = $placement['type'];
                $pantones = $placement['pantones'] ?? [];
                $fileKey = "placements.{$index}.mockup";

                $placementData = [
                    'type' => $type,
                    'pantones' => $pantones
                ];

                if (request()->hasFile($fileKey)) {
                    $placementData['mockup_image'] = $this->uploadFile(
                        request()->file($fileKey),
                        $folder
                    );
                }

                if (isset($existingPlacements[$type])) {

                    $existingPlacements[$type]->update($placementData);
                    unset($existingPlacements[$type]);
                } else {

                    $design->placements()->create($placementData);
                }
            }
        }

        foreach ($existingPlacements as $placement) {
            $placement->delete();
        }

        return $design->load('placements');
    }

    private function uploadFile($file, $folder): string
    {
        $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)
            . '_' . now()->timestamp
            . '.' . $file->getClientOriginalExtension();

        $file->storeAs($folder, $filename, 'public');

        return "/storage/{$folder}/{$filename}";
    }
}
