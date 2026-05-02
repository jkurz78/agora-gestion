<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Newsletter\SubscriptionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class NewsletterConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SubscriptionRequest $subscription,
        public readonly string $confirmationToken,
        public readonly string $unsubscribeToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmez votre inscription');
    }

    public function content(): Content
    {
        // stub : sera enrichi en Task 7
        return new Content(htmlString: '<p>Stub</p>');
    }
}
