<?php

declare(strict_types=1);

namespace App\Support;

final class FlashMessages
{
    /**
     * Flash message for a generic email sent successfully.
     *
     * In demo mode, substituted with a demo-specific wording to signal
     * the email was logged but not actually delivered.
     *
     * Used by: DevisManuel\DevisEdit
     */
    public static function emailSent(): string
    {
        if (Demo::isActive()) {
            return __('demo.email_sent');
        }

        return 'Email envoyé avec succès.';
    }

    /**
     * Flash message for an email sent to a specific address (test emails).
     *
     * In demo mode, substituted with a demo-specific wording regardless
     * of the recipient address.
     *
     * Used by: CommunicationTiers, OperationCommunication
     */
    public static function emailSentTo(string $email): string
    {
        if (Demo::isActive()) {
            return __('demo.email_sent_to');
        }

        return "Email de test envoyé à {$email}.";
    }

    /**
     * Flash message for a document (devis, pro forma, attestation…) sent to a tiers.
     *
     * In demo mode, substituted with a demo-specific wording.
     *
     * Used by: ParticipantShow
     *
     * @param  string  $typeLabel  e.g. 'devis', 'pro forma', 'attestation'
     */
    public static function documentSentTo(string $typeLabel, string $email): string
    {
        if (Demo::isActive()) {
            return __('demo.document_sent');
        }

        return ucfirst($typeLabel)." envoyé à {$email}.";
    }
}
