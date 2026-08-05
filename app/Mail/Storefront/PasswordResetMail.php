<?php

namespace App\Mail\Storefront;

use App\Models\Storefront\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public int $expiresInMinutes;

    /**
     * The raw token is taken as a plain argument rather than a promoted property:
     * public properties are handed to the view and serialized when queued, and a
     * live reset token has no business sitting in either.
     */
    public function __construct(public Customer $user, string $token)
    {
        // /reset-password is a SPA route, not a Laravel one — the client reads token
        // and email off the query string and posts them back to the API. http_build_query
        // handles the encoding, which matters for the '+' addresses email allows.
        //
        // reefer.spa_url, NOT app.url: because that route is client-side only, the link
        // has to point at the STOREFRONT's origin. Where the two are split — the SPA on
        // reeferclothing.com, this app on api.sorbetesapparel.com — app.url resolves to
        // the API host, whose routes/web.php serves only '/', so the shopper lands on a
        // 404 and the token expires unredeemed. spa_url defaults to app.url, so the
        // single-origin case is unchanged.
        $this->resetUrl = rtrim((string) config('reefer.spa_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);

        // Quoted from the broker's own config so the email can never promise a
        // window different from the one the token actually has.
        //
        // The 'storefront' broker by name, NOT auth.defaults.passwords: the default
        // broker in this app is the ERP's staff one, and PasswordResetController
        // issues these tokens through Password::broker('storefront'). Reading the
        // default would quote a stranger's expiry. The literal 60 covers the case
        // where StorefrontServiceProvider has not registered the broker yet.
        $this->expiresInMinutes = (int) config('auth.passwords.storefront.expire', 60);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your REEFER MNL password');
    }

    public function content(): Content
    {
        // 'storefront.emails.*', not the source's 'emails.*': the five storefront
        // templates were relocated to resources/views/storefront/emails/ during the
        // port because resources/views/emails/ is the ERP's (quotation_pdf lives
        // there). All five mailables use the prefixed path.
        return new Content(view: 'storefront.emails.password-reset');
    }
}
