<?php

namespace App\Http\Controllers\Storefront\Stock;

use App\Http\Controllers\Controller;
use App\Models\Storefront\Stock\StockUser;
use App\Support\Storefront\Stock\TokenSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff accounts for the Stock manager module — mounted at /api/stocks/auth/*.
 *
 * Port of the standalone Stock manager's AuthController. Same endpoints, same JSON
 * keys, same error strings, same status codes: the React pages
 * (Login.jsx / Register.jsx / AdminUsers.jsx) move over unchanged and must not be
 * able to tell that the backend changed underneath them.
 *
 * WHAT THIS CONTROLLER MUST NEVER TOUCH
 * -------------------------------------
 * `users`, config/auth.php, Sanctum, or any shop guard. Every query here goes to
 * stock_users. Reefer_Backend's `users` table is the shop's 34 customers — people
 * with carts and delivery addresses — and this table is the warehouse staff who pick
 * and pack for them. Two populations, two credential stores, two session mechanisms,
 * and no bridge between them. In particular `StockUser::count() === 0` below asks
 * "has any *staff* account ever been created", which is why a live shop full of
 * customers still bootstraps its first ERP admin correctly.
 *
 * ACCOUNT MODEL (unchanged from the source)
 * -----------------------------------------
 * Registration collects username + first/last name + password only. Every new account
 * starts role 'staff', status 'pending' — locked out until an admin approves it —
 * except the very first account ever created, which is auto-approved as an admin so
 * there is someone able to approve everyone after them.
 *
 * The UI's single Active/Inactive/Suspended value maps onto status + active:
 *   "active"    -> status 'approved', active 1
 *   "inactive"  -> status 'approved', active 0
 *   "suspended" -> status 'rejected', active 0
 */
class AuthController extends Controller
{
    /** @return array<string, mixed> */
    private function presentUser(StockUser $u): array
    {
        return [
            'username' => $u->username,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'full_name' => $u->full_name,
            'role' => $u->role,
            'status' => $u->status,
            'active' => (bool) $u->active,
            'display_status' => $u->displayStatus(),
            'created_date' => $u->created_date,
        ];
    }

    public function register(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $firstName = trim((string) $request->input('first_name', ''));
        $lastName = trim((string) $request->input('last_name', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $firstName === '' || $lastName === '' || $password === '') {
            return response()->json(['error' => 'Username, first name, last name, and password are required.'], 400);
        }

        if (StockUser::query()->username($username)->exists()) {
            return response()->json(['error' => 'An account with that username already exists.'], 409);
        }

        // "Has any staff account ever existed", NOT "does the shop have users".
        $isFirstUser = StockUser::query()->count() === 0;

        // password_hash() rather than Hash::make(): the source hashed with bcrypt
        // cost 10 and accounts created before the port must keep working. bcryptjs
        // (the Express original), PHP's password_hash and Laravel's Hash driver all
        // produce mutually verifiable bcrypt digests, so this is interoperable in
        // every direction — it just pins the cost the existing rows were made with.
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $fullName = trim($firstName.' '.$lastName);
        $role = $isFirstUser ? 'admin' : 'staff';
        $status = $isFirstUser ? 'approved' : 'pending';

        StockUser::query()->insert([
            'username' => $username,
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'role' => $role,
            'status' => $status,
            'active' => 1,
            // A plain Y-m-d in Manila time, not a UTC timestamp: it is printed
            // verbatim in the Registered column of the approvals table.
            'created_date' => now('Asia/Manila')->format('Y-m-d'),
        ]);

        // Register.jsx prints `data.message` verbatim and must not second-guess which
        // of the two it got, so both strings stay exactly as the source wrote them.
        return response()->json([
            'username' => $username,
            'full_name' => $fullName,
            'status' => $status,
            'message' => $isFirstUser
                ? 'Account created and approved as the first administrator.'
                : 'Registration submitted. An administrator needs to approve your account before you can log in.',
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        $user = StockUser::query()->username($username)->first();

        // One message for "no such user" and "wrong password" alike — splitting them
        // would turn this endpoint into a way to enumerate staff usernames.
        if ($user === null || ! password_verify($password, (string) $user->password_hash)) {
            return response()->json(['error' => 'Incorrect username or password.'], 401);
        }

        // Login.jsx shows `error`; the extra `status` key is what the source sent and
        // is kept so anything keying off it keeps working.
        if ($user->status === 'pending') {
            return response()->json(['error' => 'Your account is awaiting administrator approval.', 'status' => 'pending'], 403);
        }

        if ($user->status === 'rejected') {
            return response()->json(['error' => 'Your registration was not approved. Contact an administrator.', 'status' => 'rejected'], 403);
        }

        if (! $user->active) {
            return response()->json(['error' => 'This account has been deactivated. Contact an administrator.', 'status' => 'deactivated'], 403);
        }

        $token = TokenSessions::create($user->username, $user->role);

        // AuthContext stores this whole payload under localStorage 'ash_session' and
        // reads session.token / session.user.role off it — the shape is load-bearing.
        return response()->json([
            'token' => $token,
            'user' => ['username' => $user->username, 'full_name' => $user->full_name, 'role' => $user->role],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        TokenSessions::destroy(TokenSessions::tokenFromRequest($request));

        return response()->json(['loggedOut' => true]);
    }

    public function listUsers(Request $request): JsonResponse
    {
        $query = StockUser::query();

        // AdminUsers.jsx fires three of these in parallel, one per tab. is_string
        // rather than a bare truthiness check: `?status[]=pending` arrives as an
        // array, which where() cannot compare and would blow up as a 500.
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return response()->json(
            $query->get()->map(fn (StockUser $u) => $this->presentUser($u))->values()
        );
    }

    public function approve(string $username): JsonResponse
    {
        if (! $this->applyToUser($username, ['status' => 'approved', 'active' => 1])) {
            return response()->json(['error' => 'User not found: '.$username], 404);
        }

        return response()->json(['username' => $username, 'status' => 'approved']);
    }

    public function reject(string $username): JsonResponse
    {
        if (! $this->applyToUser($username, ['status' => 'rejected'])) {
            return response()->json(['error' => 'User not found: '.$username], 404);
        }

        return response()->json(['username' => $username, 'status' => 'rejected']);
    }

    public function deactivate(string $username): JsonResponse
    {
        if (! $this->applyToUser($username, ['active' => 0])) {
            return response()->json(['error' => 'User not found: '.$username], 404);
        }

        return response()->json(['username' => $username, 'active' => false]);
    }

    public function reactivate(string $username): JsonResponse
    {
        if (! $this->applyToUser($username, ['active' => 1])) {
            return response()->json(['error' => 'User not found: '.$username], 404);
        }

        return response()->json(['username' => $username, 'active' => true]);
    }

    /**
     * Existence check first, then the write.
     *
     * The source decided "not found" from the UPDATE's affected-row count, which MySQL
     * reports as 0 when the row already holds the values being written. Re-pressing
     * Deactivate on an already-inactive account therefore answered
     * 404 "User not found: jdelacruz" about a user sitting right there in the table.
     * This is the one deliberate divergence in the file: a real 404 still means the
     * account is gone, and a no-op update now answers 200 like the idempotent write it
     * is. No response key or shape changes.
     *
     * @param  array<string, mixed>  $values
     */
    private function applyToUser(string $username, array $values): bool
    {
        $user = StockUser::query()->where('username', $username)->first();

        if ($user === null) {
            return false;
        }

        StockUser::query()->where('id', $user->id)->update($values);

        return true;
    }

    /**
     * Edit an existing account's profile info. Deliberately cannot create an account
     * and never touches password_hash — the row must already exist, and a password is
     * something only its owner sets.
     */
    public function edit(Request $request, string $username): JsonResponse
    {
        $user = StockUser::query()->where('username', $username)->first();
        if ($user === null) {
            return response()->json(['error' => 'User not found: '.$username], 404);
        }

        $newUsername = trim((string) $request->input('username', ''));
        $firstName = trim((string) $request->input('first_name', ''));
        $lastName = trim((string) $request->input('last_name', ''));
        $role = trim((string) $request->input('role', ''));
        $status = strtolower(trim((string) $request->input('status', '')));

        if ($newUsername === '' || $firstName === '' || $lastName === '' || $role === '' || $status === '') {
            return response()->json(['error' => 'Username, first name, last name, role, and status are all required.'], 400);
        }

        if (! in_array($status, ['active', 'inactive', 'suspended'], true)) {
            return response()->json(['error' => 'Status must be one of: active, inactive, suspended.'], 400);
        }

        // A rename has to clear the unique index; changing only the letter case of
        // your own username is not a clash with yourself.
        if (strtolower($newUsername) !== strtolower($user->username)) {
            $clash = StockUser::query()
                ->username($newUsername)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($clash) {
                return response()->json(['error' => 'That username is already taken.'], 409);
            }
        }

        // Fold the UI's one value back into the two columns behind it.
        $dbStatus = 'approved';
        $dbActive = 1;
        if ($status === 'inactive') {
            $dbActive = 0;
        } elseif ($status === 'suspended') {
            $dbStatus = 'rejected';
            $dbActive = 0;
        }

        StockUser::query()->where('id', $user->id)->update([
            'username' => $newUsername,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim($firstName.' '.$lastName),
            'role' => $role,
            'status' => $dbStatus,
            'active' => $dbActive,
        ]);

        // refresh() re-reads by primary key, so it survives the rename above and
        // hands back a StockUser rather than a possibly-null query result.
        $user->refresh();

        return response()->json($this->presentUser($user));
    }
}
