<?php

namespace App\Mail;

use App\Models\OrderQuotations;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuotationReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrderQuotations $quotation
    ) {}

    public function build()
    {
        return $this->subject('New Quotation for Order ' . optional($this->quotation->order)->code)
            ->view('emails.quotation-received');
    }
}
