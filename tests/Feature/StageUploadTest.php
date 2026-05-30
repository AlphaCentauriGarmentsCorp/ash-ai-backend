<?php

/**
 * Phase 3 — StageUploadService tests.
 *
 * Run with:
 *     php artisan test --filter=StageUploadTest
 *
 * Same in-memory SQLite isolation as the other Phase tests. Uses
 * Storage::fake('public') so file writes/deletes don't touch the real disk.
 *
 * Coverage:
 *   1. store() writes a row + persists the file on the public disk
 *   2. store() captures metadata (original name, mime, size) and is_image flag
 *   3. forStage() returns a stage's uploads newest-first
 *   4. forOrderGrouped() groups uploads by stage id
 *   5. delete() removes the row and the underlying file
 *   6. summarize() builds a public URL and image flag
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageUpload;
use App\Services\StageUploadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    foreach (['stage_uploads', 'order_stages', 'orders', 'users'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('hashed');
        $t->timestamps();
        $t->softDeletes(); // User model uses SoftDeletes.
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->text('stage');
        $t->unsignedSmallInteger('sequence')->default(0);
        $t->string('status')->default('pending');
        $t->timestamps();
    });

    Schema::create('stage_uploads', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('uploaded_by_user_id');
        $t->string('category', 32)->default('proof');
        $t->string('file_path', 255);
        $t->string('original_name', 255)->nullable();
        $t->string('mime_type', 64)->nullable();
        $t->unsignedBigInteger('size_bytes')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Storage::fake('public');
});

afterEach(function () {
    foreach (['stage_uploads', 'order_stages', 'orders', 'users'] as $t) {
        Schema::dropIfExists($t);
    }
});

function su_user(string $name = 'sm1'): App\Models\User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name, 'email' => $name . '@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return App\Models\User::find($id);
}

function su_stage(string $slug = 'screen_making'): OrderStage
{
    $order = Order::create(['po_code' => 'ASH-U-' . uniqid(), 'workflow_status' => $slug]);
    return OrderStage::create([
        'order_id' => $order->id, 'stage' => $slug, 'sequence' => 6, 'status' => 'in_progress',
    ]);
}

function su_service(): StageUploadService
{
    return new StageUploadService();
}

it('stores a row and persists the file on the public disk', function () {
    $user  = su_user();
    $stage = su_stage();
    $file  = UploadedFile::fake()->image('screen.png', 800, 600);

    $upload = su_service()->store($stage->id, $user, $file, 'screen', 'mesh 110');

    expect($upload->order_stage_id)->toBe($stage->id)
        ->and($upload->category)->toBe('screen')
        ->and($upload->notes)->toBe('mesh 110');

    Storage::disk('public')->assertExists($upload->file_path);
});

it('captures metadata and flags images', function () {
    $user  = su_user();
    $stage = su_stage();
    $file  = UploadedFile::fake()->image('proof.jpg');

    $upload = su_service()->store($stage->id, $user, $file);
    $summary = su_service()->summarize($upload);

    expect($upload->original_name)->toBe('proof.jpg')
        ->and($upload->mime_type)->toContain('image/')
        ->and($upload->size_bytes)->toBeGreaterThan(0)
        ->and($summary['is_image'])->toBeTrue()
        ->and($summary['url'])->toContain('storage/');
});

it('lists a stage uploads newest first', function () {
    $user  = su_user();
    $stage = su_stage();

    $a = su_service()->store($stage->id, $user, UploadedFile::fake()->image('a.png'));
    $b = su_service()->store($stage->id, $user, UploadedFile::fake()->image('b.png'));

    $list = su_service()->forStage($stage->id);

    expect($list)->toHaveCount(2)
        ->and($list[0]['id'])->toBe($b->id)   // newest first
        ->and($list[1]['id'])->toBe($a->id);
});

it('groups uploads by stage id for an order', function () {
    $user = su_user();

    $order = Order::create(['po_code' => 'ASH-U-' . uniqid(), 'workflow_status' => 'cutting']);
    $s1 = OrderStage::create(['order_id' => $order->id, 'stage' => 'cutting', 'sequence' => 11, 'status' => 'in_progress']);
    $s2 = OrderStage::create(['order_id' => $order->id, 'stage' => 'printing', 'sequence' => 12, 'status' => 'pending']);

    su_service()->store($s1->id, $user, UploadedFile::fake()->image('cut.png'));
    su_service()->store($s2->id, $user, UploadedFile::fake()->image('print.png'));
    su_service()->store($s2->id, $user, UploadedFile::fake()->image('print2.png'));

    $grouped = su_service()->forOrderGrouped($order->id);

    expect($grouped[$s1->id])->toHaveCount(1)
        ->and($grouped[$s2->id])->toHaveCount(2);
});

it('deletes the row and the underlying file', function () {
    $user  = su_user();
    $stage = su_stage();
    $upload = su_service()->store($stage->id, $user, UploadedFile::fake()->image('x.png'));
    $path = $upload->file_path;

    Storage::disk('public')->assertExists($path);

    su_service()->delete($upload->id);

    Storage::disk('public')->assertMissing($path);
    expect(StageUpload::find($upload->id))->toBeNull();
});
