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

            $folders = $client->getFolders(false);
            $folderCount = $folders->count();

            $processedCreated = $this->ensureFolder($client, $processedFolder);
            $errorsCreated = $this->ensureFolder($client, $errorsFolder);

            $inbox = $client->getFolder('INBOX');
            $unseenCount = $inbox !== null
                ? $inbox->query()->unseen()->count()
                : null;

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
        if ($client->getFolder($name) !== null) {
            return false;
        }

        $client->createFolder($name, true);

        return true;
    }
}
