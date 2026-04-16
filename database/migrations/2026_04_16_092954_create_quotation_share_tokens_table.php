<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_share_tokens', function (Blueprint $table) {
            $table->id();

            // Core relationships
            $table->foreignId('quotation_id')
                  ->constrained('quotations')
                  ->cascadeOnDelete();

            $table->foreignId('created_by')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // The shareable token — URL-safe, 64 chars
            $table->string('token', 64)->unique();

            // Access control
            // view   → read-only, no download, no edit
            // edit   → can read + update items_json and print_parts_json via public PUT
            $table->enum('permission', ['view', 'edit'])->default('view');

            // Download is a separate toggle — any permission level can have it enabled
            $table->boolean('allow_download')->default(false);

            // Lifecycle
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_revoked')->default(false);

            // Usage tracking
            $table->unsignedBigInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();

            // Optional label to help the owner identify the link
            $table->string('label')->nullable();

            $table->timestamps();

            $table->index(['token', 'is_revoked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_share_tokens');
    }
};
