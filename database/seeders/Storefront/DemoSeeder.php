<?php

namespace Database\Seeders\Storefront;

use App\Models\Storefront\Customer;
use App\Models\Storefront\Order;
use App\Models\Storefront\Product;
use App\Services\Storefront\PricingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    /** Login: demo@reefer.mnl / password */
    public function run(): void
    {
        $user = Customer::updateOrCreate(
            ['email' => 'demo@reefer.mnl'],
            [
                'name' => 'Rico Dela Cruz',
                'phone' => '0917 555 0142',
                'password' => Hash::make('password'),
            ]
        );

        $user->addresses()->updateOrCreate(
            ['label' => 'Home'],
            [
                'name' => 'Rico Dela Cruz',
                'phone' => '0917 555 0142',
                'street' => '24 Mahogany St, Project 6',
                'barangay' => 'Barangay Vasra',
                'city' => 'Quezon City',
                'province' => 'Metro Manila',
                'region' => 'NCR',
                'postal' => '1105',
                'is_default_shipping' => true,
                'is_default_billing' => true,
            ]
        );

        $pricing = app(PricingService::class);

        // A delivered order and an in-transit one, mirroring the account page demo.
        $this->makeOrder($user, $pricing, 'RFR-PH0018452', 'deep-current', 'L', 1,
            status: 'Delivered', stage: 4, courier: 'LBC Express', tracking: 'LBC55120983',
            placedAt: '2026-05-12', eta: '2026-05-15', paymentStatus: 'paid');

        $this->makeOrder($user, $pricing, 'RFR-PH0019004', 'undertow', 'M', 1,
            status: 'Processing', stage: 2, courier: 'J&T Express', tracking: 'JT8842019PH',
            placedAt: '2026-07-11', eta: '2026-07-15', paymentStatus: 'paid');
    }

    private function makeOrder(
        Customer $user, PricingService $pricing, string $number, string $slug, string $size, int $qty,
        string $status, int $stage, string $courier, string $tracking,
        string $placedAt, string $eta, string $paymentStatus,
    ): void {
        if (Order::where('order_number', $number)->exists()) {
            return;
        }

        $product = Product::where('slug', $slug)->firstOrFail();
        $subtotal = $product->price * $qty;
        $shippingFee = $pricing->shippingFee($subtotal, 'golocal');

        $address = $user->addresses()->first();

        $order = Order::create([
            'order_number' => $number,
            'user_id' => $user->id,
            'email' => $user->email,
            'ship_to_name' => $address->name,
            'phone' => $address->phone,
            'street' => $address->street,
            'barangay' => $address->barangay,
            'city' => $address->city,
            'province' => $address->province,
            'region' => $address->region,
            'postal' => $address->postal,
            'shipping_method' => 'golocal',
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'total' => $subtotal + $shippingFee,
            'payment_method' => 'gcash',
            'payment_status' => $paymentStatus,
            'status' => $status,
            'stage' => $stage,
            'courier' => $courier,
            'tracking_number' => $tracking,
            'eta' => $eta,
            'placed_at' => $placedAt . ' 10:00:00',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_slug' => $product->slug,
            'name' => $product->name,
            'size' => $size,
            'unit_price' => $product->price,
            'qty' => $qty,
            'line_total' => $subtotal,
        ]);
    }
}
