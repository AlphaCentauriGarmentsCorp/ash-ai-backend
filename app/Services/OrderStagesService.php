<?php

namespace App\Services;

use App\Models\OrderStage;
use Illuminate\Database\Eloquent\Collection;

class OrderStagesService
{
    public function create(array $data): array
    {
        $orderId = $data['order_id'];
        $newStages = $data['stages'] ?? [];
        $defaultStatus = $data['status'] ?? 'pending';

        $currentStages = OrderStage::where('order_id', $orderId)
            ->pluck('stage')
            ->toArray();

        $stagesToDelete = array_diff($currentStages, $newStages);
        if (!empty($stagesToDelete)) {
            OrderStage::where('order_id', $orderId)
                ->whereIn('stage', $stagesToDelete)
                ->delete();
        }

        $results = [];
        foreach ($newStages as $stage) {
            $results[] = OrderStage::updateOrCreate(
                [
                    'order_id' => $orderId,
                    'stage' => $stage
                ],
                [
                    'status' => $defaultStatus
                ]
            );
        }

        return $results;
    }
}
