<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\EmailLogo;
use App\Models\Newsletter\SubscriptionRequest;
use App\Support\TenantUrl;
use App\Tenant\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
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
        $assoNom = TenantContext::current()?->nom ?? 'AgoraGestion';

        return new Envelope(
            subject: "Confirmez votre inscription à la newsletter — {$assoNom}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter.confirmation',
            text: 'emails.newsletter.confirmation-text',
            with: [
                'prenom' => $this->subscription->prenom,
                'associationNom' => TenantContext::current()?->nom ?? 'AgoraGestion',
                'hasLogo' => EmailLogo::resolve() !== null,
                'logoCid' => EmailLogo::CID_ASSO,
                'confirmUrl' => TenantUrl::route('newsletter.confirm', ['token' => $this->confirmationToken]),
                'unsubscribeUrl' => TenantUrl::route('newsletter.unsubscribe', ['token' => $this->unsubscribeToken]),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $logo = EmailLogo::resolve();
        if (! $logo) {
            return [];
        }

        return [
            Attachment::fromPath($logo['path'])
                ->as(EmailLogo::CID_ASSO)
                ->withMime($logo['mime']),
        ];
    }
}
