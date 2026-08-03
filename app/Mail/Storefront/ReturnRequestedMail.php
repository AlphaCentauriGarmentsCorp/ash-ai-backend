<?php

namespace App\Mail\Storefront;

use App\Models\Storefront\ReturnRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "we got your return request" acknowledgement. Under MAIL_MAILER=log this lands
 * in storage/logs/laravel.log rather than an inbox, which is intended here.
 */
class ReturnRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ReturnRequest $returnRequest)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // The address comes from mail config; the display name is the brand's,
            // because MAIL_FROM_NAME is still the framework default here.
            from: new Address(config('mail.from.address'), 'REEFER MNL'),
            subject: 'Return '.$this->returnRequest->reference.' — we got your request.',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'storefront.emails.return-requested',
            // The template walks the lines and names the order, so a caller that
            // forgot to load them would otherwise send a blank acknowledgement.
            with: ['returnRequest' => $this->returnRequest->loadMissing('order', 'items.orderItem')],
        );
    }
}
