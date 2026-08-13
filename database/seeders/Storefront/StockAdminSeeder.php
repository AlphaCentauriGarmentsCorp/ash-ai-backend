<?php

namespace Database\Seeders\Storefront;

use App\Models\Storefront\Stock\StockUser;
use Illuminate\Database\Seeder;

/**
 * The stock manager's first administrator.
 *
 * WHY THIS EXISTS, and why it should run on every fresh deployment.
 *
 * Stock\AuthController::register() hands the FIRST account ever created both
 * `admin` and `approved`:
 *
 *     $isFirstUser = StockUser::query()->count() === 0;
 *     $role   = $isFirstUser ? 'admin'    : 'staff';
 *     $status = $isFirstUser ? 'approved' : 'pending';
 *
 * That is a sensible bootstrap on a laptop and a land-grab on a public domain: if
 * the production database has no staff rows, the first stranger to POST to
 * /api/storefront/stocks/auth/register owns the warehouse. Seeding one account
 * closes the window — every later registration lands `pending` and needs an
 * existing admin to approve it.
 *
 * CREDENTIALS come from the environment, never from this file:
 *
 *     STOCK_ADMIN_USERNAME   defaults to "admin"
 *     STOCK_ADMIN_PASSWORD   no default — one is GENERATED and printed once
 *
 * A committed default password would be worse than no seeder at all, since it
 * would be identical on every deployment and readable by anyone with the repo.
 * When the env var is absent this generates a strong random password and prints
 * it exactly once; there is no way to recover it afterwards, only to reset it.
 *
 * IDEMPOTENT. If the username already exists the row is left completely alone —
 * re-running a deploy must never reset a password an operator has changed, and
 * must never silently re-approve an account somebody deliberately deactivated.
 */
class StockAdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = trim((string) env('STOCK_ADMIN_USERNAME', 'admin'));

        if ($username === '') {
            $this->command?->error('STOCK_ADMIN_USERNAME is empty — refusing to seed a nameless admin.');

            return;
        }

        if (StockUser::query()->where('username', $username)->exists()) {
            $this->command?->info("Stock admin '{$username}' already exists — left untouched.");

            return;
        }

        $password = (string) env('STOCK_ADMIN_PASSWORD', '');
        $generated = false;

        if ($password === '') {
            // Ambiguous characters (0/O, 1/l/I) left out: this gets read off a
            // deploy log and typed into a login form, usually more than once.
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
            $password = '';
            for ($i = 0; $i < 20; $i++) {
                $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $generated = true;
        }

        StockUser::query()->insert([
            'username' => $username,
            // password_hash() with bcrypt cost 10, matching AuthController::register()
            // exactly — accounts made by either path must verify against the other.
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            'first_name' => 'Stock',
            'last_name' => 'Administrator',
            'full_name' => 'Stock Administrator',
            'role' => 'admin',
            'status' => 'approved',
            'active' => 1,
            // Plain Y-m-d in Manila time: printed verbatim in the approvals table.
            'created_date' => now('Asia/Manila')->format('Y-m-d'),
        ]);

        $this->command?->info("Stock admin '{$username}' created.");

        if ($generated) {
            $this->command?->warn('Generated password (shown ONCE, not recoverable):');
            $this->command?->line("    {$password}");
            $this->command?->warn('Store it now, then change it from the stock manager.');
        }
    }
}
