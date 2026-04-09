<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments;

use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

final class IncomingMailService
{
    public function __construct(
        private readonly ClientManager $clientManager,
    ) {}

    public function testerConnexion(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $processedFolder,
        string $errorsFolder,
    ): ConnectionTestResult {
        try {
            $client = $this->clientManager->make([
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption === 'none' ? false : $encryption,
                'validate_cert' => true,
                'username' => $username,
                'password' => $password,
                'protocol' => 'imap',
            ]);

            $client->connect();

            // getFolders(true) pour inclure les sous-dossiers (INBOX.Processed, etc.)
            $folders = $client->getFolders(true);
            $folderCount = $folders->count();

            $processedCreated = $this->ensureFolder($client, $processedFolder);
            $errorsCreated = $this->ensureFolder($client, $errorsFolder);

            // Compter les messages non lus via STATUS (pas besoin de SELECT)
            $unseenCount = null;

            try {
                $inbox = $client->getFolder('INBOX');
                if ($inbox !== null) {
                    $status = $inbox->status();
                    $unseenCount = $status['unseen'] ?? null;
                }
            } catch (Throwable) {
                // Certains serveurs restreignent STATUS ; on ignore
            }

            $client->disconnect();

            return new ConnectionTestResult(
                success: true,
                folderCount: $folderCount,
                inboxUnseen: $unseenCount,
                processedFolderCreated: $processedCreated,
                errorsFolderCreated: $errorsCreated,
            );
        } catch (Throwable $e) {
            return new ConnectionTestResult(
                success: false,
                erreur: $e->getMessage(),
            );
        }
    }

    private function ensureFolder(Client $client, string $name): bool
    {
        // Vérifier d'abord si le dossier existe déjà (getFolders inclut les sous-dossiers)
        try {
            $existing = $client->getFolder($name);
            if ($existing !== null) {
                return false;
            }
        } catch (Throwable) {
            // getFolder peut échouer sur certains serveurs ; on tente la création
        }

        // Créer le dossier, en ignorant ALREADYEXISTS (race condition ou lookup raté)
        try {
            $client->createFolder($name, true);

            return true;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'ALREADYEXISTS')) {
                return false;
            }
            throw $e;
        }
    }
}
