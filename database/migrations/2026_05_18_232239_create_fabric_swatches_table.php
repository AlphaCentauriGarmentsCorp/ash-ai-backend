<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6-B — Fabric Swatch Catalog
 *
 * Visual fabric reference catalog with Pantone + HEX + GSM grouping
 * + supplier + linked inventory material. Ships in the same bundle
 * as 6-A backend per the locked plan (C9).
 *
 * The catalog is the data source for the Quotation + Create Order
 * fabric color dropdown in Phase 6-C. For this bundle it's just
 * stand-alone CRUD.
 *
 * `material_id` is the optional bridge to inventory — when set, the
 * service can join `materials.stock_on_hand` to display live stock
 * status next to each swatch in the catalog UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabric_swatches', function (Blueprint $t) {
            $t->id();

            // Display identity
            $t->string('name');                         // "Jet Black", "Royal Blue"

            // Pantone linkage (optional — some swatches are CMYK-only)
            $t->unsignedBigInteger('pantone_id')->nullable();
            $t->foreign('pantone_id', 'fs_pantone_id_fk')
                ->references('id')->on('pantones')->nullOnDelete();

            // HEX swatch (with '#' prefix → 7 chars; allow 8 for #RRGGBBAA edge case)
            $t->string('hex_color', 8)->nullable();

            // Fabric classification
            $t->string('fabric_type', 64)->nullable();  // CVC / 100% Cotton / Polycotton
            $t->smallInteger('gsm')->unsigned()->nullable();

            // Catalog grouping — see CSR Portal spec §4 "Collections":
            // Hoodie Collection / 280 GSM / 220-240 GSM Greens & Blues / etc.
            $t->string('collection', 64)->nullable();

            // Supplier linkage (nullable — house stock vs vendor-specific)
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->foreign('supplier_id', 'fs_supplier_id_fk')
                ->references('id')->on('suppliers')->nullOnDelete();

            // Inventory linkage — when set, dashboards can surface
            // stock_on_hand from materials table next to the swatch.
            $t->unsignedBigInteger('material_id')->nullable();
            $t->foreign('material_id', 'fs_material_id_fk')
                ->references('id')->on('materials')->nullOnDelete();

            // Coarse filter family — Black / Blue / Green / Red / Neutral / etc.
            $t->string('color_family', 32)->nullable();

            // Storage::disk('public') relative path to swatch photo
            $t->string('photo_path', 255)->nullable();

            $t->text('notes')->nullable();

            $t->timestamps();

            // Filter combinations the catalog UI uses
            $t->index(['fabric_type', 'gsm'], 'fs_type_gsm_idx');
            $t->index('collection',           'fs_collection_idx');
            $t->index('color_family',         'fs_color_family_idx');
            $t->index('supplier_id',          'fs_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_swatches');
    }
};
