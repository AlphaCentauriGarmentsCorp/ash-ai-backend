<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';

    /**
     * Issue 20 — allowed order-channel types (drive the platform icon on the
     * PR quick-buttons). Kept here as the single source of truth so the
     * validation requests and the SupplierService normalizer agree.
     */
    public const CHANNEL_TYPES = [
        'viber',
        'messenger',
        'facebook',
        'shopee',
        'lazada',
        'tiktok',
        'website',
        'phone',
        'other',
    ];

    protected $fillable = [
        'name',
        'contact_person',
        'contact_number',
        'email',
        'address',
        'notes',
        'order_channels',
        'is_incomplete',
    ];

    protected $casts = [
        'order_channels' => 'array',
        'is_incomplete'  => 'boolean',
    ];

    public function materials()
    {
        return $this->hasMany(Materials::class);
    }
}
