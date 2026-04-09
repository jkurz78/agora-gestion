<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IncomingMailAllowedSender;
use App\Models\IncomingMailParametres;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use App\Services\IncomingDocuments\IncomingDocumentIngester;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

final class IncomingMailFetchCommand extends Command
{
    protected $signature = 'incoming-mail:fetch {--dry-run : N\'écrit rien et ne déplace aucun message}';

    protected $description = 'Récupère les documents entrants depuis la boîte IMAP dédiée';

    public function __construct(
        private readonly IncomingDocumentIngester $ingester,
        private readonly ClientManager $clientManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $params = IncomingMailParametres::where('association_id', 1)->first();

        if ($params === null || ! $params->enabled) {
            $this->info('incoming-mail:fetch désactivé (paramètres absents ou ingestion éteinte)');

            return self::SUCCESS;
        }

        if ($params->imap_host === null || $params->imap_username === null || $params->imap_password === null) {
            $this->error('Configuration IMAP incomplète.');

            return self::FAILURE;
        }

        $allowedSenders = IncomingMailAllowedSender::where('association_id', 1)
            ->pluck('email')
            ->map(fn (string $e): string => strtolower($e))
            ->all();

        if ($allowedSenders === []) {
            $this->error('Liste blanche expéditeurs vide — refus explicite de poller.');

            return self::FAILURE;
        }

        try {
            $stats = $this->processMailbox($params, $allowedSenders);
            $this->info(sprintf(
                'fetch: %d message(s), %d traité(s), %d en attente, %d erreur(s)',
                $stats['fetched'],
                $stats['handled'],
                $stats['pending'],
                $stats['errors'],
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('incoming-mail:fetch erreur fatale', ['exception' => $e]);
            $this->error("Erreur fatale : {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string>  $allowedSenders
     * @return array{fetched:int,handled:int,pending:int,errors:int}
     */
    private function processMailbox(IncomingMailParametres $params, array $allowedSenders): array
    {
        $client = $this->clientManager->make([
            'host' => $params->imap_host,
            'port' => $params->imap_port,
            'encryption' => $params->imap_encryption === 'none' ? false : $params->imap_encryption,
            'validate_cert' => true,
            'username' => $params->imap_username,
            'password' => $params->imap_password,
            'protocol' => 'imap',
        ]);

        $client->connect();

        // Ensure processed/errors folders exist
        foreach ([$params->processed_folder, $params->errors_folder] as $folderName) {
            if ($client->getFolder($folderName) === null) {
                $client->createFolder($folderName, true);
            }
        }

        $inbox = $client->getFolder('INBOX');
        $messages = $inbox->query()->unseen()->limit($params->max_per_run)->get();

        $stats = [
            'fetched' => $messages->count(),
            'handled' => 0,
            'pending' => 0,
            'errors' => 0,
        ];

        foreach ($messages as $message) {
            try {
                $outcome = $this->processMessage($message, $allowedSenders, $params);
                $stats[$outcome]++;
            } catch (Throwable $e) {
                Log::warning('incoming-mail:fetch — message ignoré', [
                    'uid' => $this->safeUid($message),
                    'from' => $this->extractEmail($message->getFrom()) ?? 'unknown',
                    'subject' => (string) $message->getSubject(),
                    'exception' => $e->getMessage(),
                ]);
                $stats['errors']++;
                if (! $this->option('dry-run')) {
                    try {
                        $message->move($params->errors_folder);
                    } catch (Throwable $moveError) {
                        Log::error('Impossible de déplacer le message en erreurs', [
                            'exception' => $moveError->getMessage(),
                        ]);
                    }
                }
            }
        }

        $client->disconnect();

        return $stats;
    }

    /**
     * @param  array<string>  $allowedSenders
     */
    private function processMessage(Message $message, array $allowedSenders, IncomingMailParametres $params): string
    {
        $from = strtolower($this->extractEmail($message->getFrom()) ?? '');

        if ($from === '' || ! in_array($from, $allowedSenders, true)) {
            Log::warning('Expéditeur non autorisé', [
                'from' => $from,
                'subject' => (string) $message->getSubject(),
            ]);
            if (! $this->option('dry-run')) {
                $message->move($params->errors_folder);
            }

            return 'errors';
        }

        $recipient = $this->extractEmail($message->getTo());
        if ($recipient !== null) {
            $recipient = strtolower($recipient);
        }

        $subject = (string) $message->getSubject();
        $messageId = (string) $message->getMessageId();
        if ($messageId === '') {
            $messageId = null;
        }

        $receivedAt = $this->extractDate($message);

        $handled = 0;
        $pending = 0;
        $pdfAttachments = 0;

        foreach ($message->getAttachments() as $attachment) {
            /** @var Attachment $attachment */
            $name = (string) $attachment->getName();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = (string) $attachment->getContentType();

            if ($ext !== 'pdf' && $mime !== 'application/pdf') {
                continue;
            }

            $pdfAttachments++;

            $tempPath = storage_path('app/private/temp/emargement-ingestion/'.Str::uuid()->toString().'.pdf');
            File::ensureDirectoryExists(dirname($tempPath));
            file_put_contents($tempPath, $attachment->getContent());

            $file = new IncomingDocumentFile(
                tempPath: $tempPath,
                originalFilename: $name !== '' ? $name : 'document.pdf',
                source: 'email',
                senderEmail: $from,
                recipientEmail: $recipient,
                subject: $subject !== '' ? $subject : null,
                receivedAt: $receivedAt,
                sourceMessageId: $messageId,
            );

            if ($this->option('dry-run')) {
                $this->line("  [dry-run] Traiterait : {$name}");
                @unlink($tempPath);

                continue;
            }

            try {
                $result = $this->ingester->ingest($file);
                if ($result->outcome === 'handled') {
                    $handled++;
                } else {
                    $pending++;
                }
            } catch (Throwable $e) {
                Log::warning('Échec ingestion pièce jointe', [
                    'attachment' => $name,
                    'exception' => $e->getMessage(),
                ]);
                @unlink($tempPath);
            }
        }

        if (! $this->option('dry-run')) {
            $message->move($params->processed_folder);
        }

        if ($handled > 0) {
            return 'handled';
        }

        if ($pending > 0) {
            return 'pending';
        }

        // Aucun PDF traité (pas de pièce jointe PDF, ou toutes ont échoué)
        Log::info('Message sans PDF exploitable', [
            'from' => $from,
            'subject' => $subject,
            'pdf_attachments' => $pdfAttachments,
        ]);

        return 'errors';
    }

    /**
     * Extrait l'adresse mail d'un Attribute (collection d'Address) webklex.
     */
    private function extractEmail(mixed $attribute): ?string
    {
        if ($attribute === null) {
            return null;
        }

        if (is_object($attribute) && method_exists($attribute, 'first')) {
            $address = $attribute->first();
            if ($address instanceof Address) {
                return $address->mail !== '' ? $address->mail : null;
            }
            if (is_object($address) && property_exists($address, 'mail')) {
                return $address->mail !== '' ? $address->mail : null;
            }
        }

        return null;
    }

    private function extractDate(Message $message): DateTimeImmutable
    {
        try {
            $dateAttr = $message->getDate();
            if (is_object($dateAttr) && method_exists($dateAttr, 'toDate')) {
                $carbon = $dateAttr->toDate();
                if ($carbon instanceof Carbon) {
                    return new DateTimeImmutable($carbon->toIso8601String());
                }
            }
        } catch (Throwable) {
            // Tombe sur fallback
        }

        return new DateTimeImmutable;
    }

    private function safeUid(Message $message): ?int
    {
        try {
            $uid = $message->getUid();

            return is_int($uid) ? $uid : (int) $uid;
        } catch (Throwable) {
            return null;
        }
    }
}
