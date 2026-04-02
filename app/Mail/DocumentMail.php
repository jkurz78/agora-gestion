<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\ArticleFr;
use App\Helpers\EmailLogo;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DocumentMail extends Mailable
{
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
    ) {
        $corps = $this->customCorps
            ?? '<p>Bonjour {prenom},</p><p>Veuillez trouver ci-joint {type_document_article} n° {numero_document}.</p>';
        $allVars = $this->variables() + EmailLogo::variables($this->typeOperationId);
        $corps = str_replace(
            array_keys($allVars),
            array_values($allVars),
            strip_tags($corps, EmailLogo::ALLOWED_TAGS)
        );
        $this->corpsHtml = ArticleFr::contracter($corps);
    }

    public function envelope(): Envelope
    {
        $objet = $this->customObjet ?? '{type_document_uc} n° {numero_document}';
        $subject = str_replace(
            array_keys($this->variables()),
            array_values($this->variables()),
            $objet
        );

        return new Envelope(subject: ArticleFr::contracter($subject));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.document');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
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
        ];
    }
}
