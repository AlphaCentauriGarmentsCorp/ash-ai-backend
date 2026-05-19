<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6-A — CSR Hub backend
 *
 * Adds the 8 CSR-tracked fields to the existing `orders` table:
 *   - 2 communication links (messenger_link, gc_link)
 *   - 1 priority enum (low/normal/high/rush)
 *   - 1 rush_order boolean (denorm flag for fast dashboard filter)
 *   - 1 sales_channel string (FB / TikTok / Shopee / Lazada / etc.)
 *   - 1 assigned_csr_user_id FK
 *   - 1 deadline date
 *   - 1 internal_notes text
 *
 * The `messenger_link` / `gc_link` here are deliberately separate
 * from the client-level ones. A client may have a default GC link,
 * but a specific order may use a different chat thread (e.g. the
 * client created a per-order GC for that batch).
 *
 * ⚠️ BUG-016: `App\Models\Order::$fillable` AND `$casts` MUST be
 * updated in the same bundle. See modifications/Order.php.
 *
 * `rush_order` cast to boolean, `deadline` cast to date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('messenger_link')->nullable()->after('notes');
            $t->string('gc_link')->nullable()->after('messenger_link');

            // priority: low / normal / high / rush. We use string (not enum)
            // for the same reason all other Phase 5 statuses use string —
            // SQLite tests don't speak MySQL enum.
            $t->string('priority', 16)->default('normal')->after('gc_link');

            $t->boolean('rush_order')->default(false)->after('priority');

            // Free-text channel — see CSR Portal spec §5 (Sales Channel)
            $t->string('sales_channel', 32)->nullable()->after('rush_order');

            // Ownership: which CSR is on this order
            $t->unsignedBigInteger('assigned_csr_user_id')->nullable()->after('sales_channel');
            $t->foreign('assigned_csr_user_id', 'ord_assigned_csr_fk')
                ->references('id')->on('users')->nullOnDelete();

            $t->date('deadline')->nullable()->after('assigned_csr_user_id');

            $t->text('internal_notes')->nullable()->after('deadline');

            // Hot path: dashboard "My Orders" + "Rush / Overdue" filter
            $t->index('assigned_csr_user_id', 'ord_assigned_csr_idx');
            $t->index('priority',             'ord_priority_idx');
            $t->index('deadline',             'ord_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropForeign('ord_assigned_csr_fk');
            $t->dropIndex('ord_assigned_csr_idx');
            $t->dropIndex('ord_priority_idx');
            $t->dropIndex('ord_deadline_idx');

            $t->dropColumn([
                'messenger_link',
                'gc_link',
                'priority',
                'rush_order',
                'sales_channel',
                'assigned_csr_user_id',
                'deadline',
                'internal_notes',
            ]);
        });
    }
};
