<?php

namespace App\Mail\Storefront;

use App\Models\Storefront\Customer;
use App\Models\Storefront\ProductVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The restock nudge. Under MAIL_MAILER=log this lands in storage/logs/laravel.log
 * rather than an inbox, which is the intended behaviour in this environment.
 */
class BackInStockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Customer $user,
        public readonly ProductVariant $variant,
    ) {}

    public function envelope(): Envelope
    {
        $product = $this->product();

        return new Envelope(
            // The address comes from mail config; the display name is the brand's,
            // because MAIL_FROM_NAME is still the framework default here.
            from: new Address(config('mail.from.address'), 'REEFER MNL'),
            subject: $product->name.' is back in size '.$this->variant->size.'.',
        );
    }

    public function content(): Content
    {
        $product = $this->product();

        return new Content(
            view: 'storefront.emails.back-in-stock',
            // Scalars, not the models: the template needs a name, a size and a link,
            // and nothing else about the account belongs in its scope.
            with: [
                'name' => $this->user->name,
                'productName' => $product->name,
                'size' => $this->variant->size,
                'priceFormatted' => '₱'.number_format((int) $product->price),
                // /product/{slug} is a SPA route, so this is built from the STOREFRONT's
                // origin. The comment here used to say "served by the same origin as the
                // API" — true of the original single-origin mockup, false of the split
                // deployment this integration targets, where it sent every restock
                // notification to a 404 on the API host. spa_url defaults to app.url, so
                // same-origin setups keep the old behaviour.
                'productUrl' => rtrim((string) config('reefer.spa_url'), '/').'/product/'.$product->slug,
            ],
        );
    }

    /**
     * The notifier eager-loads this; loadMissing covers any other caller so a lazy
     * relation cannot throw from inside a send.
     */
    private function product()
    {
        return $this->variant->loadMissing('product')->product;
    }
}
