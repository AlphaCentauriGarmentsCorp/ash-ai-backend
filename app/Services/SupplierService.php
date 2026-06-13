<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;

class SupplierService
{
    public function getAll(): Collection
    {
        return Supplier::all();
    }

    public function find(int $id): ?Supplier
    {
        return Supplier::with('materials')->find($id);
    }

    public function create(array $data): Supplier
    {
        $data['address'] = $this->composeAddress($data);

        if (array_key_exists('order_channels', $data)) {
            $data['order_channels'] = $this->normalizeChannels($data['order_channels']);
        }

        return Supplier::create($data);
    }

    public function update(array $data, int $id): ?Supplier
    {
        $supplier = Supplier::find($id);

        if (! $supplier) {
            return null;
        }

        $data['address'] = $this->composeAddress($data);

        if (array_key_exists('order_channels', $data)) {
            $data['order_channels'] = $this->normalizeChannels($data['order_channels']);
        }

        $supplier->update($data);
        return $supplier;
    }

    /**
     * Issue 20 — inline quick-add from the PR supplier picker.
     * Name + one optional channel link; flagged is_incomplete so the
     * Purchaser completes contact/address later via the full edit form.
     */
    public function quickCreate(array $data): Supplier
    {
        $channels = [];
        if (! empty($data['channel_url'])) {
            $channels = $this->normalizeChannels([[
                'type'       => $data['channel_type'] ?? 'other',
                'url'        => $data['channel_url'],
                'is_primary' => true,
            ]]);
        }

        return Supplier::create([
            'name'           => $data['name'],
            'contact_person' => '',
            'contact_number' => '',
            'email'          => null,
            'address'        => null,
            'notes'          => null,
            'order_channels' => $channels,
            'is_incomplete'  => true,
        ]);
    }

    public function delete(int $id): bool
    {
        $supplier = Supplier::find($id);

        if (! $supplier) {
            return false;
        }

        return $supplier->delete();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Pack the granular address fields into the single pipe-delimited
     * `address` column (SupplierResource splits them back out). Tolerant of
     * missing parts so the minimal quick-add / partial payloads don't error.
     */
    private function composeAddress(array $data): string
    {
        return implode('|', [
            $data['street_address'] ?? '',
            $data['barangay']       ?? '',
            $data['city']           ?? '',
            $data['province']       ?? '',
            $data['postal_code']    ?? '',
        ]);
    }

    /**
     * Normalize an order-channels array to the stored shape and enforce
     * EXACTLY ONE primary when non-empty: the first row already flagged
     * is_primary wins; if none is flagged, the first row becomes primary.
     * Rows without a url are dropped.
     *
     * @return array<int, array{type:string,label:string,url:string,is_primary:bool}>
     */
    private function normalizeChannels($channels): array
    {
        if (! is_array($channels)) {
            return [];
        }

        $clean = [];
        foreach ($channels as $channel) {
            if (! is_array($channel) || empty($channel['url'])) {
                continue;
            }

            $type = $channel['type'] ?? 'other';
            if (! in_array($type, Supplier::CHANNEL_TYPES, true)) {
                $type = 'other';
            }

            $clean[] = [
                'type'       => $type,
                'label'      => isset($channel['label']) ? (string) $channel['label'] : '',
                'url'        => (string) $channel['url'],
                'is_primary' => (bool) ($channel['is_primary'] ?? false),
            ];
        }

        if (! empty($clean)) {
            $primaryIndex = null;
            foreach ($clean as $i => $channel) {
                if ($channel['is_primary']) {
                    $primaryIndex = $i;
                    break;
                }
            }
            if ($primaryIndex === null) {
                $primaryIndex = 0;
            }
            foreach ($clean as $i => &$channel) {
                $channel['is_primary'] = ($i === $primaryIndex);
            }
            unset($channel);
        }

        return array_values($clean);
    }
}
