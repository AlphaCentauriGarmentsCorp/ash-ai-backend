<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7-B Bundle 1 — QA checklist item (master list).
 *
 * 7 canonical items seeded via QaChecklistItemSeeder. The packer's
 * tick state lives in localStorage during a task (per Q5 decision)
 * and lands as JSON on the qa_packer_task_completions row at submit
 * time (Bundle 4).
 */
class QaChecklistItem extends Model
{
    protected $table = 'qa_checklist_items';

    protected $fillable = [
        'slug',
        'label',
        'display_order',
        'active',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'active'        => 'boolean',
    ];
}
