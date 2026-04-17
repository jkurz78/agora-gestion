<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Association;
use App\Support\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SuperAdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Association $association,
        public string $email,
        public string $nomAdmin,
        public string $resetToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invitation : {$this->association->nom} sur AgoraGestion");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.super-admin-invitation',
            with: [
                'resetUrl' => TenantUrl::to('/reset-password/'.$this->resetToken.'?email='.urlencode($this->email)),
                'nomAsso' => $this->association->nom,
                'nomAdmin' => $this->nomAdmin,
            ],
        );
    }
}
