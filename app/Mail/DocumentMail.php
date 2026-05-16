<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\ArticleFr;
use App\Helpers\EmailLogo;
use App\Mail\Concerns\HasPolitesseVariables;
use App\Support\TemplateSubstitution;
use App\Support\TenantUrl;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DocumentMail extends Mailable
{
    use HasPolitesseVariables;

    public readonly string $corpsHtml;

    public function __construct(
        public readonly string $prenomDestinataire,
        public readonly string $nomDestinataire,
        public readonly string $typeDocument,
        public readonly string $typeDocumentArticle,
        public readonly string $typeDocumentArticleDe,
        public readonly string $numeroDocument,
        public readonly string $dateDocument,
        public readonly string $montantTotal,
        public readonly ?string $customObjet,
        public readonly ?string $customCorps,
        public readonly string $pdfContent,
        public readonly string $pdfFilename,
        public readonly ?int $typeOperationId = null,
        public readonly ?string $civilite = null,
        public readonly ?string $politesse = null,
        public readonly ?string $operationLabel = null,
        public readonly ?string $typeOperationLabel = null,
        public readonly ?string $trackingToken = null,
    ) {
        $corps = $this->customCorps
            ?? '<p>Bonjour {prenom},</p><p>Veuillez trouver ci-joint {type_document_article} n° {numero_document}.</p>';
        $allVars = $this->variables() + EmailLogo::variables($this->typeOperationId);
        $corps = TemplateSubstitution::apply(
            strip_tags($corps, EmailLogo::ALLOWED_TAGS),
            $allVars
        );
        $html = ArticleFr::contracter($corps);

        // Append tracking pixel if token provided
        if ($this->trackingToken) {
            $pixelUrl = TenantUrl::route('email.tracking', ['token' => $this->trackingToken]);
            $html .= '<img src="'.htmlspecialchars($pixelUrl).'" width="1" height="1" alt="" style="display:none">';
        }

        $this->corpsHtml = $html;
    }

    public function envelope(): Envelope
    {
        $objet = $this->customObjet ?? '{type_document_uc} n° {numero_document}';
        $subject = TemplateSubstitution::apply($objet, $this->variables());

        return new Envelope(subject: ArticleFr::contracter($subject));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.document');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn () => $this->pdfContent, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];

        // Only attach the association logo if the body actually references it.
        // Without this guard, the logo is attached to every mail, and clients
        // (Gmail, Apple Mail) display unreferenced inline attachments at the bottom
        // of the message — producing an unwanted "giant logo" footer.
        if (str_contains($this->corpsHtml, 'cid:'.EmailLogo::CID_ASSO)) {
            $logo = EmailLogo::resolve();
            if ($logo) {
                $attachments[] = Attachment::fromPath($logo['path'])
                    ->as(EmailLogo::CID_ASSO)
                    ->withMime($logo['mime']);
            }
        }

        return $attachments;
    }

    /** @return array<string, string> */
    private function variables(): array
    {
        return [
            '{prenom}' => $this->prenomDestinataire,
            '{nom}' => $this->nomDestinataire,
            '{type_document}' => $this->typeDocument,
            '{type_document_uc}' => ucfirst($this->typeDocument),
            '{type_document_article}' => $this->typeDocumentArticle,
            '{type_document_article_de}' => $this->typeDocumentArticleDe,
            '{numero_document}' => $this->numeroDocument,
            '{date_document}' => $this->dateDocument,
            '{montant_total}' => $this->montantTotal,
            '{operation}' => $this->operationLabel ?? '',
            '{type_operation}' => $this->typeOperationLabel ?? '',
        ] + $this->politesseVariables(
            $this->civilite,
            $this->politesse,
            $this->prenomDestinataire,
            $this->nomDestinataire,
        );
    }
}
