<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\CategorieEmail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Centralized EmailLog persistence.
 *
 * Ensures that corps_html, objet, and attachment_path are always consistently
 * filled, regardless of which call site sends the email.
 *
 * Usage:
 *   $emailLogStorageService->logSent($mail, $tiers, CategorieEmail::Document, 'a@b.com', pdfContent: $pdf, pdfFilename: 'facture.pdf');
 *   $emailLogStorageService->logError($tiers, CategorieEmail::Document, 'a@b.com', 'Fallback subject', $e->getMessage());
 */
final class EmailLogStorageService
{
    /**
     * Persist an EmailLog for a successfully sent email.
     *
     * If $pdfContent is provided, the binary content is written to the tenant's
     * storage path under `associations/{id}/email_attachments/` and the resolved
     * path is stored in `attachment_path`.
     *
     * The mail's rendered HTML is sanitized via EmailTemplate::sanitizeCorps()
     * before storage (same treatment as OperationCommunication).
     *
     * @param  array<string, mixed>  $extra  Additional fillable fields (e.g. campagne_id, tracking_token)
     */
    public function logSent(
        Mailable $mail,
        Tiers $tiers,
        CategorieEmail $categorie,
        string $destinataireEmail,
        ?string $destinataireNom = null,
        ?int $participantId = null,
        ?int $operationId = null,
        ?int $emailTemplateId = null,
        ?string $pdfContent = null,
        ?string $pdfFilename = null,
        array $extra = [],
    ): EmailLog {
        $attachmentPath = null;

        if ($pdfContent !== null) {
            $attachmentPath = $this->persistPdf($pdfContent, $pdfFilename ?? 'attachment.pdf');
        }

        $objet = $mail->envelope()->subject;
        $corpsHtml = EmailTemplate::sanitizeCorps($mail->render());

        $data = array_merge([
            'tiers_id' => (int) $tiers->id,
            'participant_id' => $participantId !== null ? (int) $participantId : null,
            'operation_id' => $operationId !== null ? (int) $operationId : null,
            'email_template_id' => $emailTemplateId !== null ? (int) $emailTemplateId : null,
            'categorie' => $categorie->value,
            'destinataire_email' => $destinataireEmail,
            'destinataire_nom' => $destinataireNom ?? $tiers->displayName(),
            'objet' => $objet,
            'corps_html' => $corpsHtml,
            'statut' => 'envoye',
            'attachment_path' => $attachmentPath,
        ], $extra);

        return EmailLog::create($data);
    }

    /**
     * Persist an EmailLog for a failed email send.
     *
     * Does NOT call $mail->render() (which could throw again) and does NOT
     * write anything to disk. Uses $objetFallback as the stored subject.
     *
     * @param  array<string, mixed>  $extra  Additional fillable fields
     */
    public function logError(
        Tiers $tiers,
        CategorieEmail $categorie,
        string $destinataireEmail,
        string $objetFallback,
        string $erreurMessage,
        ?string $destinataireNom = null,
        ?int $participantId = null,
        ?int $operationId = null,
        ?int $emailTemplateId = null,
        array $extra = [],
    ): EmailLog {
        $data = array_merge([
            'tiers_id' => (int) $tiers->id,
            'participant_id' => $participantId !== null ? (int) $participantId : null,
            'operation_id' => $operationId !== null ? (int) $operationId : null,
            'email_template_id' => $emailTemplateId !== null ? (int) $emailTemplateId : null,
            'categorie' => $categorie->value,
            'destinataire_email' => $destinataireEmail,
            'destinataire_nom' => $destinataireNom ?? $tiers->displayName(),
            'objet' => $objetFallback,
            'corps_html' => null,
            'statut' => 'erreur',
            'erreur_message' => $erreurMessage,
            'attachment_path' => null,
        ], $extra);

        return EmailLog::create($data);
    }

    /**
     * Write PDF binary content to tenant storage and return the relative path.
     *
     * Path format: associations/{associationId}/email_attachments/{uuid}-{sanitized_filename}
     *
     * The filename is sanitized to prevent path traversal and keep only safe
     * characters (alphanumeric, dash, underscore, dot). Slashes (e.g. `../`)
     * are collapsed into dashes.
     *
     * @throws \RuntimeException if TenantContext is not booted
     */
    private function persistPdf(string $pdfContent, string $filename): string
    {
        $associationId = TenantContext::currentId();

        if ($associationId === null) {
            throw new \RuntimeException('TenantContext not booted — cannot persist email attachment.');
        }

        $sanitized = $this->sanitizeFilename($filename);
        $uuid = (string) Str::uuid();
        $path = "associations/{$associationId}/email_attachments/{$uuid}-{$sanitized}";

        Storage::disk('local')->put($path, $pdfContent);

        return $path;
    }

    /**
     * Sanitize a filename for safe disk storage.
     *
     * Rules:
     * - Strip any directory components (basename only).
     * - Replace any character that is not alphanumeric, dash, underscore, or dot with a dash.
     * - Collapse consecutive dashes.
     * - Strip leading/trailing dashes.
     * - Fall back to 'attachment.pdf' if the result is empty.
     * - Cap to 200 characters (before the UUID prefix).
     */
    private function sanitizeFilename(string $filename): string
    {
        // Use only the basename — drops any directory traversal like ../../../
        $name = basename($filename);

        // Replace unsafe chars with dashes
        $name = (string) preg_replace('/[^a-zA-Z0-9\-_.]/', '-', $name);

        // Collapse consecutive dashes
        $name = (string) preg_replace('/-{2,}/', '-', $name);

        // Trim leading/trailing dashes and dots
        $name = trim($name, '-.');

        // Cap length
        if (strlen($name) > 200) {
            $name = substr($name, 0, 200);
        }

        if ($name === '' || $name === '.') {
            $name = 'attachment.pdf';
        }

        return $name;
    }
}
