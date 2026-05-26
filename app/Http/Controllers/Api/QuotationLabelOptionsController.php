<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SizeLabel;
use App\Models\PrintLabelPlacement;
use App\Models\PlacementMeasurement;
use Illuminate\Http\JsonResponse;

/**
 * Issue 7 — locked option lists for the Quotation label system.
 *
 * Returns the four controlled vocabularies a CSR can choose from when
 * speccing a Brand Label or Care/Size Label. Strictly-locked: only these
 * server-controlled values are offered (no free typing on the form), exactly
 * like Free Items.
 *
 * Sources (Blueprint §2.2 — wire to EXISTING Drop Down Settings, do not
 * create new lists):
 *   - methods      → size_labels            (Sew / Print / None)
 *   - placements   → print_label_placements (nape / sleeves / hem ...)
 *   - measurements → placement_measurements (optional detail)
 *
 * Materials ("Woven Tag", "DTF", "Sublimation", "Taffeta") are the one
 * genuinely-new dimension Issue 7 introduces and have no existing dropdown.
 * They are served here as a server-controlled constant so the list stays
 * strictly-locked today; promoting them to a managed Drop Down Settings table
 * later only requires swapping the source of $this->materials() — the API
 * shape stays the same.
 */
class QuotationLabelOptionsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'materials'    => $this->materials(),
            'methods'      => $this->mapNamed(SizeLabel::orderBy('name')->get()),
            'placements'   => $this->mapNamed(PrintLabelPlacement::orderBy('name')->get()),
            'measurements' => $this->mapNamed(PlacementMeasurement::orderBy('name')->get()),
        ]);
    }

    /**
     * Server-controlled label materials. Confirmed set from the Blueprint
     * (§4 Add-ons → Brand Label). Edit here (or migrate to a managed table)
     * to change the locked list.
     *
     * @return array<int, array{value:string,label:string}>
     */
    protected function materials(): array
    {
        $values = ['Woven Tag', 'DTF', 'Sublimation', 'Taffeta'];

        return array_map(
            static fn (string $name): array => ['value' => $name, 'label' => $name],
            $values,
        );
    }

    /**
     * Normalise a {name, description} dropdown collection to a stable shape
     * for the picker: value = name (what we store on the quotation), plus id
     * + description for display.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @return array<int, array{id:mixed,value:string,label:string,description:?string}>
     */
    protected function mapNamed($rows): array
    {
        return $rows->map(static function ($row): array {
            return [
                'id'          => $row->id,
                'value'       => (string) $row->name,
                'label'       => (string) $row->name,
                'description' => $row->description !== null ? (string) $row->description : null,
            ];
        })->all();
    }
}
