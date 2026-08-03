<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $table = 'storefront_addresses';

    // user_id is deliberately not fillable — ownership comes from the relationship
    // ($user->addresses()->create(...)), never from client input.
    protected $fillable = [
        'label', 'name', 'phone', 'street', 'barangay',
        'city', 'province', 'region', 'postal',
        'is_default_shipping', 'is_default_billing',
    ];

    protected function casts(): array
    {
        return [
            'is_default_shipping' => 'boolean',
            'is_default_billing' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }
}
