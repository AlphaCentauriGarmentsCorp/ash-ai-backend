<?php

namespace App\Models\Storefront;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\Storefront\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $google_id
 * @property string|null $avatar_url
 * @property string|null $phone
 * @property \Illuminate\Support\Carbon|null $birth_date
 * @property string|null $gender
 * @property string|null $api_token
 * @property string|null $email_verification_code
 * @property \Illuminate\Support\Carbon|null $email_verification_sent_at
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, Notifiable;

    /*
     * $fillable/$hidden as PROPERTIES, not the #[Fillable]/#[Hidden] attributes the
     * source used. Those attributes are Laravel 13; this app runs Laravel 12, which
     * ignores them silently — leaving $fillable empty, so every Customer::create()
     * threw MassAssignmentException. The property form works on both versions.
     *
     * The emailed code and the Google identity are never client-supplied — the server
     * mints or verifies both — so they stay out of $fillable and are written with
     * forceFill(). google_id in particular: a request that could mass-assign it could
     * hand itself somebody else's Google sign-in.
     */
    protected $fillable = ['name', 'email', 'password', 'phone', 'birth_date', 'gender'];

    protected $hidden = ['password', 'remember_token', 'api_token', 'email_verification_code', 'google_id'];

    // Storefront shoppers live in their own table. The ERP owns `users` (staff),
    // and Eloquent would otherwise infer `customers` from the class name — both
    // wrong, so the table is named outright.
    protected $table = 'storefront_users';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'api_token_last_used_at' => 'datetime',
            'birth_date' => 'date',
            'password' => 'hashed',
            'email_verification_sent_at' => 'datetime',
        ];
    }

    /**
     * False for an account created through Google, which has no password and does not
     * need one. Anything that asks the owner to prove or change a password has to ask
     * this first — there is nothing to check against, and Hash::check() on a null
     * hash answers false, so those flows would otherwise refuse the rightful owner.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /**
     * The user shape every auth endpoint returns. It lives here so register, login
     * and /me cannot drift apart on what the SPA is told — the flag is how the client
     * knows whether signup still owes a step.
     */
    public function toAuthArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
        ];
    }

    /**
     * Digest a bearer token for storage/lookup. SHA-256 is deliberate, not an
     * oversight: the token is 40 random chars, so it needs no stretching against
     * guessing, and an unsalted digest is what makes an indexed lookup possible.
     */
    public static function hashApiToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Mint a fresh token, persist only its digest, and return the plaintext —
     * this is the one and only time the caller can see it.
     */
    public function issueApiToken(): string
    {
        $token = Str::random(40);

        $this->forceFill([
            'api_token' => static::hashApiToken($token),
            'api_token_last_used_at' => null,
        ])->save();

        return $token;
    }

    public function revokeApiToken(): void
    {
        $this->forceFill([
            'api_token' => null,
            'api_token_last_used_at' => null,
        ])->save();
    }

    /**
     * Resolve a bearer token to its account, or null. Shared by the middleware that
     * requires a user and the one that merely tolerates one, so the two can never
     * drift apart on what counts as a valid token.
     */
    public static function findByApiToken(?string $token): ?self
    {
        if (! $token) {
            return null;
        }

        $digest = static::hashApiToken($token);
        $user = static::query()->where('api_token', $digest)->first();

        // The digest is what we looked up by, so this can only fail on a hash
        // collision — but compare anyway, in constant time, rather than trusting the
        // index to have told the truth.
        if (! $user || ! hash_equals($user->api_token, $digest)) {
            return null;
        }

        return $user;
    }

    // Every relation below names its foreign key outright. The schema keeps the
    // original `user_id` columns, but Eloquent derives a hasMany/belongsToMany key
    // from the CLASS name — which is now Customer, so it would look for
    // `customer_id` and silently return nothing.

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'user_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class, 'user_id');
    }

    /**
     * The products this account has wishlisted. belongsToMany over the favorites
     * join table, so toggle()/detach() do the set arithmetic and the pivot
     * timestamps give us "newest first".
     */
    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'storefront_favorites', 'user_id', 'product_id')
            ->withTimestamps();
    }

    /** The account's cart, created on first use — a shopper always has exactly one. */
    public function currentCart(): Cart
    {
        return $this->cart()->firstOrCreate([]);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }
}
