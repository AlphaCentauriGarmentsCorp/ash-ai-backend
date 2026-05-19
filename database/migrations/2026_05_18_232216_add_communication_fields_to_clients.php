<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6-A — CSR Hub backend
 *
 * Adds the 4 communication-link fields to the existing `clients`
 * table per the CSR portal spec (§2 Client Communication Links).
 *
 * ⚠️ BUG-016: `App\Models\Client::$fillable` MUST be updated in the
 * same bundle (see modifications/Client.php in this bundle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('messenger_link')->nullable()->after('notes');
            $t->string('facebook_link')->nullable()->after('messenger_link');
            $t->string('gc_link')->nullable()->after('facebook_link');
            $t->text('internal_notes')->nullable()->after('gc_link');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->dropColumn(['messenger_link', 'facebook_link', 'gc_link', 'internal_notes']);
        });
    }
};
