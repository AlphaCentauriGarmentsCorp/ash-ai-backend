<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7-B Bundle 1 — notification_settings key/value store.
 *
 * Originally added so the QA/Packer Super Admin reject-alert thresholds
 * (≥5 pcs OR ≥10% of order qty) can be tuned without a deploy, but
 * deliberately built generic so future notification thresholds and
 * preferences can land here without another migration.
 *
 * Shape mirrors a standard config-row table:
 *   `key`        unique slug (e.g. 'qa_reject_alert_threshold_pcs')
 *   `value_json` json-cast scalar/object/array
 *   `description` admin-readable note about what the row controls
 *
 * No existing settings table was found in the repo (grep on
 * app_settings, system_settings, etc. returned nothing), so this is
 * the first one. Future settings of any kind can use this table or
 * a sibling — we're not locking the pattern.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 128)->unique();
            $t->json('value_json');
            $t->string('description', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
