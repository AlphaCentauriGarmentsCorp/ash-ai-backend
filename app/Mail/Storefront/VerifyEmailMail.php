<?php

namespace App\Mail\Storefront;

use App\Models\Storefront\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The 6-digit email verification code.
 *
 * A code, not a signed link: the SPA owns its own routes, and a link would have to
 * guess the frontend origin from config it does not currently have.
 */
class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        protected Customer $user,
        protected string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your REEFER MNL verification code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'storefront.emails.verify-email',
            // Passed explicitly rather than as public properties, so the whole
            // Customer model never lands in the template's scope.
            with: [
                'name' => $this->user->name,
                'code' => $this->code,
                'ttlMinutes' => (int) config('reefer.email_verification_ttl'),
            ],
        );
    }
}
