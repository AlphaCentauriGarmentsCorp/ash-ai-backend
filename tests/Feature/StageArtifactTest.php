<?php

/**
 * StageArtifactService — aggregation tests.
 *
 * Run with: php artisan test --filter=StageArtifactTest
 *
 * Verifies the hub sees artifacts from ALL sources, each routed to the right
 * stage:
 *   1. generic stage_uploads        → its own stage
 *   2. stage_sample_uploads         → front + back become two artifacts
 *   3. order_design_files (latest)  → routed to the graphic_artwork stage
 *   4. qa_packer final_photos_json  → one artifact per photo entry
 *   5. multiple sources on one order land under the correct stage ids
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Services\StageArtifactService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'stage_uploads', 'stage_sample_uploads', 'order_design_files',
        'qa_packer_task_completions', 'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id(); $t->string('name'); $t->string('email')->unique();
        $t->string('password')->default('x'); $t->timestamps(); $t->softDeletes();
    });
    Schema::create('orders', function (Blueprint $t) {
        $t->id(); $t->string('po_code')->unique();
        $t->string('workflow_status', 32)->default('inquiry'); $t->timestamps(); $t->softDeletes();
    });
    Schema::create('order_stages', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('order_id'); $t->text('stage');
        $t->unsignedSmallInteger('sequence')->default(0);
        $t->string('status')->default('pending'); $t->timestamps();
    });
    Schema::create('stage_uploads', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('uploaded_by_user_id'); $t->string('category', 32)->default('proof');
        $t->string('file_path'); $t->string('original_name')->nullable();
        $t->string('mime_type', 64)->nullable(); $t->unsignedBigInteger('size_bytes')->nullable();
        $t->text('notes')->nullable(); $t->timestamps();
    });
    Schema::create('stage_sample_uploads', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('uploaded_by_user_id');
        $t->string('photo_front_path')->nullable(); $t->string('photo_back_path')->nullable();
        $t->text('remarks')->nullable(); $t->string('sample_status', 16)->default('for_approval');
        $t->timestamp('completed_at')->nullable(); $t->timestamps();
    });
    Schema::create('order_design_files', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('order_design_id')->nullable();
        $t->string('kind', 32); $t->unsignedInteger('version')->default(1);
        $t->string('file_path'); $t->string('original_name'); $t->string('mime_type', 64);
        $t->unsignedBigInteger('size_bytes')->default(0); $t->boolean('is_latest')->default(true);
        $t->unsignedBigInteger('uploaded_by_user_id'); $t->text('notes')->nullable(); $t->timestamps();
    });
    Schema::create('qa_packer_task_completions', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('submitted_by_user_id');
        $t->json('checklist_state_json')->nullable(); $t->json('final_photos_json')->nullable();
        $t->json('reject_summary_json')->nullable(); $t->text('notes')->nullable();
        $t->timestamp('submitted_at')->nullable(); $t->timestamps();
    });
});

afterEach(function () {
    foreach ([
        'stage_uploads', 'stage_sample_uploads', 'order_design_files',
        'qa_packer_task_completions', 'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

function art_order(): Order
{
    return Order::create(['po_code' => 'ASH-ART-' . uniqid(), 'workflow_status' => 'graphic_artwork']);
}
function art_stage(Order $o, string $slug, int $seq): OrderStage
{
    return OrderStage::create(['order_id' => $o->id, 'stage' => $slug, 'sequence' => $seq, 'status' => 'in_progress']);
}
function art_service(): StageArtifactService
{
    return new StageArtifactService();
}

it('routes generic proof uploads to their own stage', function () {
    $o = art_order();
    $screen = art_stage($o, 'screen_making', 6);

    DB::table('stage_uploads')->insert([
        'order_id' => $o->id, 'order_stage_id' => $screen->id, 'uploaded_by_user_id' => 1,
        'category' => 'screen', 'file_path' => 'stage-uploads/s.png', 'original_name' => 's.png',
        'mime_type' => 'image/png', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $map = art_service()->forOrder($o->id);
    expect($map[$screen->id])->toHaveCount(1)
        ->and($map[$screen->id][0]['source'])->toBe('proof')
        ->and($map[$screen->id][0]['is_image'])->toBeTrue();
});

it('expands sample front and back into two artifacts', function () {
    $o = art_order();
    $cut = art_stage($o, 'cutting', 11);

    DB::table('stage_sample_uploads')->insert([
        'order_id' => $o->id, 'order_stage_id' => $cut->id, 'uploaded_by_user_id' => 1,
        'photo_front_path' => 'samples/f.jpg', 'photo_back_path' => 'samples/b.jpg',
        'sample_status' => 'for_approval', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $map = art_service()->forOrder($o->id);
    expect($map[$cut->id])->toHaveCount(2)
        ->and(collect($map[$cut->id])->pluck('source')->unique()->all())->toBe(['sample']);
});

it('routes design files to the graphic_artwork stage', function () {
    $o = art_order();
    $ga = art_stage($o, 'graphic_artwork', 5);
    art_stage($o, 'screen_making', 6);

    DB::table('order_design_files')->insert([
        'order_id' => $o->id, 'kind' => 'design_front', 'version' => 1,
        'file_path' => 'design/manadult.png', 'original_name' => 'Manadult.png',
        'mime_type' => 'image/png', 'size_bytes' => 100, 'is_latest' => true,
        'uploaded_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $map = art_service()->forOrder($o->id);
    expect($map[$ga->id])->toHaveCount(1)
        ->and($map[$ga->id][0]['source'])->toBe('design')
        ->and($map[$ga->id][0]['original_name'])->toBe('Manadult.png');
});

it('shows only the latest version of a design file kind', function () {
    $o = art_order();
    $ga = art_stage($o, 'graphic_artwork', 5);

    DB::table('order_design_files')->insert([
        ['order_id' => $o->id, 'kind' => 'design_front', 'version' => 1, 'file_path' => 'design/v1.png',
         'original_name' => 'v1.png', 'mime_type' => 'image/png', 'size_bytes' => 1, 'is_latest' => false,
         'uploaded_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['order_id' => $o->id, 'kind' => 'design_front', 'version' => 2, 'file_path' => 'design/v2.png',
         'original_name' => 'v2.png', 'mime_type' => 'image/png', 'size_bytes' => 1, 'is_latest' => true,
         'uploaded_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $map = art_service()->forOrder($o->id);
    expect($map[$ga->id])->toHaveCount(1)
        ->and($map[$ga->id][0]['original_name'])->toBe('v2.png');
});

it('expands qa final photos json into artifacts', function () {
    $o = art_order();
    $qa = art_stage($o, 'quality_control', 13);

    DB::table('qa_packer_task_completions')->insert([
        'order_id' => $o->id, 'order_stage_id' => $qa->id, 'submitted_by_user_id' => 1,
        'final_photos_json' => json_encode(['packed_items' => 'qa/p.jpg', 'box' => 'qa/box.jpg']),
        'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $map = art_service()->forOrder($o->id);
    expect($map[$qa->id])->toHaveCount(2)
        ->and(collect($map[$qa->id])->pluck('source')->unique()->all())->toBe(['qa']);
});

it('keeps artifacts from different sources under the correct stages', function () {
    $o = art_order();
    $ga = art_stage($o, 'graphic_artwork', 5);
    $screen = art_stage($o, 'screen_making', 6);

    DB::table('order_design_files')->insert([
        'order_id' => $o->id, 'kind' => 'design_front', 'version' => 1, 'file_path' => 'd/a.png',
        'original_name' => 'a.png', 'mime_type' => 'image/png', 'size_bytes' => 1, 'is_latest' => true,
        'uploaded_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stage_uploads')->insert([
        'order_id' => $o->id, 'order_stage_id' => $screen->id, 'uploaded_by_user_id' => 1,
        'category' => 'screen', 'file_path' => 'u/s.png', 'original_name' => 's.png',
        'mime_type' => 'image/png', 'size_bytes' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $map = art_service()->forOrder($o->id);
    expect($map[$ga->id])->toHaveCount(1)
        ->and($map[$ga->id][0]['source'])->toBe('design')
        ->and($map[$screen->id])->toHaveCount(1)
        ->and($map[$screen->id][0]['source'])->toBe('proof');
});
