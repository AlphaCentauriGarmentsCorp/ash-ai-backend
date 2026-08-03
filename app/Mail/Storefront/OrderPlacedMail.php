<?php

namespace App\Mail\Storefront;

use App\Models\Storefront\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The order receipt. Under MAIL_MAILER=log this lands in storage/logs/laravel.log
 * rather than an inbox, which is the intended behaviour in this environment.
 */
class OrderPlacedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // The address comes from mail config; the display name is the brand's,
            // because MAIL_FROM_NAME is still the framework default here.
            from: new Address(config('mail.from.address'), 'REEFER MNL'),
            subject: 'Order '.$this->order->order_number.' — we got it.',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'storefront.emails.order-placed',
            // The template walks $order->items, so a caller that forgot to load
            // them would otherwise send a receipt with no lines on it.
            with: ['order' => $this->order->loadMissing('items')],
        );
    }
}
