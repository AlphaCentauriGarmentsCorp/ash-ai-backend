<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Change 6 (option B) — give the client master REAL granular address columns.
 *
 * Previously the five address parts collected by the client form were
 * concatenated into the single `clients.address` string on save, and split
 * back out positionally on edit. That positional split corrupts any address
 * whose parts contain a comma (e.g. "123 Main St, Unit 4"). This migration
 * adds dedicated columns and backfills them best-effort from the legacy
 * string. The legacy `address` column is KEPT and now serves as a derived
 * single-line convenience value (read by the order's own `address` field,
 * PDFs, and anything else still pointing at it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'street_address')) {
                $table->string('street_address')->nullable()->after('address');
            }
            if (! Schema::hasColumn('clients', 'barangay')) {
                $table->string('barangay')->nullable()->after('street_address');
            }
            if (! Schema::hasColumn('clients', 'city')) {
                $table->string('city')->nullable()->after('barangay');
            }
            if (! Schema::hasColumn('clients', 'province')) {
                $table->string('province')->nullable()->after('city');
            }
            if (! Schema::hasColumn('clients', 'postal_code')) {
                $table->string('postal_code', 10)->nullable()->after('province');
            }
        });

        // Best-effort backfill of existing rows from the concatenated address,
        // mirroring the positional order the UI used (street, barangay, city,
        // province, postal). Rows already carrying granular data are skipped.
        DB::table('clients')->orderBy('id')->chunkById(200, function ($clients) {
            foreach ($clients as $client) {
                $hasGranular = ($client->street_address ?? null)
                    || ($client->barangay ?? null)
                    || ($client->city ?? null)
                    || ($client->province ?? null)
                    || ($client->postal_code ?? null);

                if ($hasGranular) {
                    continue;
                }

                $parts = array_map('trim', explode(',', (string) ($client->address ?? '')));

                DB::table('clients')->where('id', $client->id)->update([
                    'street_address' => $parts[0] ?? null,
                    'barangay'       => $parts[1] ?? null,
                    'city'           => $parts[2] ?? null,
                    'province'       => $parts[3] ?? null,
                    'postal_code'    => $parts[4] ?? null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['street_address', 'barangay', 'city', 'province', 'postal_code'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
