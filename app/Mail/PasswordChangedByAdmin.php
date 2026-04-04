<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PasswordChangedByAdmin extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $changedByName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre mot de passe a été modifié',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-changed-by-admin',
        );
    }
}
