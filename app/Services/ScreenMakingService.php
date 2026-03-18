<?php

namespace App\Services;

use App\Models\OrderStage;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Screens;
use App\Models\ScreenAssignment;

class ScreenMakingService
{
    public function create(array $data)
    {
        $savedAssignments = collect();

        foreach ($data as $assignment) {

            if (!isset($assignment['placement_id'])) {
                continue;
            }

            OrderStage::where('order_id', $assignment['order_id'])
                ->where('stage', 'screen_making')
                ->update(['status' => 'completed']);

            $screenAssignment = ScreenAssignment::updateOrCreate(
                [
                    'placement_id' => $assignment['placement_id'],
                    'color_index' => $assignment['color_index']
                ],
                [
                    'order_id' => $assignment['order_id'],
                    'screen_id' => $assignment['screen_id']
                ]
            );

            // if ($screenAssignment->wasRecentlyCreated) {
            //     Screens::where('id', $assignment['screen_id'])
            //         ->increment('total_use');
            // }

            $savedAssignments->push($screenAssignment);
        }

        return $savedAssignments;
    }
}
