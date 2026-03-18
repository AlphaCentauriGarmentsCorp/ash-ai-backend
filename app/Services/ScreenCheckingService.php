<?php

namespace App\Services;

use App\Models\OrderStage;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Screens;
use App\Models\ScreenChecking;
use App\Models\ScreenCheckingItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScreenCheckingService
{
    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {

            // Find existing ScreenChecking or create new
            $checking = ScreenChecking::updateOrCreate(
                ['order_id' => $data['order_id']],
                [
                    'status' => $data['status'] ?? 'completed',
                    'verification_date' => $data['verification_date'] ?? now(),
                    'verified_by' => Auth::id(),
                ]
            );

            foreach ($data['screens'] as $screen) {
                ScreenCheckingItem::updateOrCreate(
                    [
                        'screen_checking_id' => $checking->id,
                        'placement_id'       => $screen['placement_id'],
                        'color_index'        => $screen['color_index'],
                    ],
                    [
                        'screen_id'   => $screen['screen_id'],
                        'pantone'     => $screen['pantone'] ?? null,
                        'clean'       => filter_var($screen['checks']['clean'], FILTER_VALIDATE_BOOLEAN),
                        'no_damage'   => filter_var($screen['checks']['no_damage'], FILTER_VALIDATE_BOOLEAN),
                        'emulsion_ok' => filter_var($screen['checks']['emulsion_ok'], FILTER_VALIDATE_BOOLEAN),
                        'verified'    => filter_var($screen['checks']['verified'], FILTER_VALIDATE_BOOLEAN),
                        'issues'      => $screen['issues'] ?? null,
                        'verified_at' => Carbon::now(),
                    ]
                );

                // Optional: mark damaged screens
                // if (!$screen['checks']['no_damage']) {
                //     Screens::where('id', $screen['screen_id'])
                //         ->update(['condition' => 'damaged']);
                // }
            }

            OrderStage::where('order_id', $data['order_id'])
                ->where('stage', 'screen_checking')
                ->update(['status' => 'completed']);

            return collect([$checking->load('items.screen', 'items.placement')]);
        });
    }
}
