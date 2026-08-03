<?php

namespace Database\Factories\Storefront;

use App\Models\Storefront\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Storefront shoppers, not ERP staff.
 *
 * Laravel resolves this by convention: App\Models\Storefront\Customer strips the
 * App\Models\ prefix and gains the Database\Factories\ one, landing exactly here.
 * That is why the class and file are named for the model rather than kept as
 * UserFactory — rename either half and Customer::factory() stops resolving.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * The model this factory builds. Stated outright rather than inferred, because
     * the inverse convention (CustomerFactory -> App\Models\Customer) would look in
     * the ERP's namespace, where no such model exists.
     *
     * @var class-string<Customer>
     */
    protected $model = Customer::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
