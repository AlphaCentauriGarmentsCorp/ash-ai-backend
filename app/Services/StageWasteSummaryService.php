<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * StageWasteSummaryService
 *
 * Read-only aggregation of the waste/material usage that the PRODUCTION PORTALS
 * already record while doing the work — the Review Hub surfaces this instead of
 * anyone typing waste by hand on the order tab.
 *
 * Sources (per order_stage_id):
 *   - stage_fabric_logs : fabric_used_kg / waste_kg    (Cutter + Sewer portals)
 *   - stage_ink_logs    : ink_used_kg    / ink_waste_kg (Printer portal)
 *   - stage_reject_logs : quantity_pcs split by disposition = reject | repair (QA)
 *   - stage_waste_logs  : quantity_pcs   (legacy generic waste — retired as an
 *                         input, still read so historical rows keep showing)
 *
 * Design notes:
 *   - Keyed by order_stage_id (int). JSON stringifies the keys — the frontend
 *     looks them up by String(stage.id), same as `stage_details` / `payments`.
 *   - Only stages that actually have at least one waste/usage row are returned;
 *     a stage with no data is simply absent from the map.
 *   - Every table read is guarded by Schema::hasTable(). In production all four
 *     tables exist (real migrations); the guard only matters for hand-built
 *     SQLite test schemas that don't include them, so this service can join the
 *     shared stage-reviews code path WITHOUT forcing every existing endpoint
 *     test to add these tables. A missing table just means "no waste yet".
 *   - Uses SUM / conditional SUM only (SQLite- and MySQL-safe); no MySQL-only
 *     SQL functions.
 */
class StageWasteSummaryService
{
    /**
     * @return array<int, array<string, mixed>> keyed by order_stage_id
     */
    public function forOrder(int $orderId): array
    {
        $out = [];

        $this->mergeFabric($orderId, $out);
        $this->mergeInk($orderId, $out);
        $this->mergeRejects($orderId, $out);
        $this->mergeGenericWaste($orderId, $out);

        return $out;
    }

    /** Lazily create a stage bucket so groups can be merged in any order. */
    private function ensure(array &$out, int $stageId): void
    {
        if (! isset($out[$stageId])) {
            $out[$stageId] = ['has_data' => true];
        }
    }

    private function mergeFabric(int $orderId, array &$out): void
    {
        if (! Schema::hasTable('stage_fabric_logs')) {
            return;
        }

        $rows = DB::table('stage_fabric_logs')
            ->where('order_id', $orderId)
            ->groupBy('order_stage_id')
            ->selectRaw('order_stage_id, '
                . 'SUM(fabric_used_kg) as used_kg, '
                . 'SUM(waste_kg) as waste_kg, '
                . 'COUNT(*) as entries')
            ->get();

        foreach ($rows as $r) {
            $stageId = (int) $r->order_stage_id;
            $this->ensure($out, $stageId);
            $out[$stageId]['fabric'] = [
                'used_kg'  => round((float) $r->used_kg, 2),
                'waste_kg' => round((float) $r->waste_kg, 2),
                'entries'  => (int) $r->entries,
            ];
        }
    }

    private function mergeInk(int $orderId, array &$out): void
    {
        if (! Schema::hasTable('stage_ink_logs')) {
            return;
        }

        $rows = DB::table('stage_ink_logs')
            ->where('order_id', $orderId)
            ->groupBy('order_stage_id')
            ->selectRaw('order_stage_id, '
                . 'SUM(ink_used_kg) as used_kg, '
                . 'SUM(ink_waste_kg) as waste_kg, '
                . 'COUNT(*) as entries')
            ->get();

        foreach ($rows as $r) {
            $stageId = (int) $r->order_stage_id;
            $this->ensure($out, $stageId);
            $out[$stageId]['ink'] = [
                'used_kg'  => round((float) $r->used_kg, 3),
                'waste_kg' => round((float) $r->waste_kg, 3),
                'entries'  => (int) $r->entries,
            ];
        }
    }

    private function mergeRejects(int $orderId, array &$out): void
    {
        if (! Schema::hasTable('stage_reject_logs')) {
            return;
        }

        $rows = DB::table('stage_reject_logs')
            ->where('order_id', $orderId)
            ->groupBy('order_stage_id')
            ->selectRaw('order_stage_id, '
                . "SUM(CASE WHEN disposition = 'repair' THEN quantity_pcs ELSE 0 END) as repair_pcs, "
                . "SUM(CASE WHEN disposition = 'repair' THEN 0 ELSE quantity_pcs END) as reject_pcs, "
                . 'COUNT(*) as entries')
            ->get();

        foreach ($rows as $r) {
            $stageId = (int) $r->order_stage_id;
            $this->ensure($out, $stageId);
            $out[$stageId]['rejects'] = [
                'reject_pcs' => (int) $r->reject_pcs,
                'repair_pcs' => (int) $r->repair_pcs,
                'entries'    => (int) $r->entries,
            ];
        }
    }

    private function mergeGenericWaste(int $orderId, array &$out): void
    {
        if (! Schema::hasTable('stage_waste_logs')) {
            return;
        }

        $rows = DB::table('stage_waste_logs')
            ->where('order_id', $orderId)
            ->groupBy('order_stage_id')
            ->selectRaw('order_stage_id, '
                . 'SUM(quantity_pcs) as pcs, '
                . 'COUNT(*) as entries')
            ->get();

        foreach ($rows as $r) {
            $stageId = (int) $r->order_stage_id;
            $this->ensure($out, $stageId);
            $out[$stageId]['other'] = [
                'pcs'     => (int) $r->pcs,
                'entries' => (int) $r->entries,
            ];
        }
    }
}
