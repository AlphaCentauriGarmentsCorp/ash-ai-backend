<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuotationPdfMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $pdfPath;

    public function __construct(string $pdfPath)
    {
        $this->pdfPath = $pdfPath;
    }

    public function build()
    {
        return $this->subject('Your Quotation PDF')
            ->view('emails.quotation_pdf')
            ->attach(storage_path('app/public/' . $this->pdfPath), [
                'as' => basename($this->pdfPath),
                'mime' => 'application/pdf',
            ]);
    }
}
