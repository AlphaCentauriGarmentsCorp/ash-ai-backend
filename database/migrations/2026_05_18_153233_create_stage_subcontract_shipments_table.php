<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-I — Logistics shipment record for a subcontract assignment.
 *
 * One assignment can have multiple shipments (outbound + inbound_return).
 * Shipment status is the lifecycle the Logistics staff drives:
 *   for_pickup -> in_transit -> delivered (or -> issue)
 *
 * This is distinct from the assignment-level status (pending/out/
 * returned/cancelled) from Phase 4 / 5-D, which tracks the higher-level
 * subcontract relationship.
 *
 * NOTE: this migration previously shipped with a duplicated body that
 * modified stage_subcontract_assignments instead of creating this table,
 * which left stage_subcontract_shipments missing in production
 * (SQLSTATE[42S02] in the Logistics Portal). Body restored below.
 */
return new class extends Migration {
    public function up(): void
    {
        // Guard so re-running migrate on an environment that somehow
        // already has the table (e.g. created from the test schema) is safe.
        if (Schema::hasTable('stage_subcontract_shipments')) {
            return;
        }

        Schema::create('stage_subcontract_shipments', function (Blueprint $t) {
            $t->id();

            // Parent assignment. Cascade so shipments disappear with the assignment.
            $t->unsignedBigInteger('stage_subcontract_assignment_id');

            // outbound | inbound_return
            $t->string('direction', 16)->default('outbound');
            // for_pickup | in_transit | delivered | issue
            $t->string('status', 16)->default('for_pickup');
            // courier | in_house_driver
            $t->string('delivery_mode', 16)->default('courier');

            // Courier / shipping references (nullable — in-house deliveries omit them)
            $t->unsignedBigInteger('courier_id')->nullable();
            $t->unsignedBigInteger('shipping_method_id')->nullable();
            $t->string('waybill_number', 64)->nullable();

            // Addressing & contact
            $t->text('pickup_address')->nullable();
            $t->text('dropoff_address')->nullable();
            $t->string('contact_person_name', 120)->nullable();
            $t->string('contact_person_number', 32)->nullable();
            $t->text('instructions')->nullable();

            // Timeline
            $t->timestamp('booking_time')->nullable();
            $t->timestamp('departure_time')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->text('issue_note')->nullable();

            // Payment (courier fee / subcontract payment)
            $t->decimal('payment_amount', 10, 2)->nullable();
            $t->string('payment_method', 32)->nullable();
            $t->string('payment_reference', 120)->nullable();
            $t->string('payment_proof_path', 255)->nullable();

            // Proof-of-pickup / delivery
            $t->string('pickup_proof_path', 255)->nullable();
            $t->string('delivery_proof_path', 255)->nullable();
            $t->string('receiver_signature_path', 255)->nullable();
            $t->string('receiver_name', 120)->nullable();

            // In-house driver fields
            $t->string('driver_name', 120)->nullable();
            $t->string('driver_vehicle_plate', 32)->nullable();
            $t->string('gas_receipt_path', 255)->nullable();
            $t->decimal('gas_amount', 10, 2)->nullable();
            $t->date('gas_date')->nullable();
            $t->text('gas_notes')->nullable();

            // Audit
            $t->unsignedBigInteger('created_by_user_id')->nullable();

            $t->timestamps();

            // Index used by the Logistics Portal list query
            // (orderByRaw on status, then updated_at).
            $t->index(['status', 'updated_at'], 'sss_status_updated_idx');
            $t->index('stage_subcontract_assignment_id', 'sss_assignment_idx');

            // Foreign keys. Short explicit names to stay well under MySQL's
            // 64-char identifier limit.
            $t->foreign('stage_subcontract_assignment_id', 'sss_assignment_fk')
                ->references('id')
                ->on('stage_subcontract_assignments')
                ->cascadeOnDelete();

            $t->foreign('courier_id', 'sss_courier_fk')
                ->references('id')
                ->on('courier_list')
                ->nullOnDelete();

            $t->foreign('shipping_method_id', 'sss_shipping_method_fk')
                ->references('id')
                ->on('shipping_methods')
                ->nullOnDelete();

            $t->foreign('created_by_user_id', 'sss_created_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_subcontract_shipments');
    }
};