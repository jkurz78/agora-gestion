<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\IncomingMailAllowedSender;
use App\Models\IncomingMailParametres;
use App\Services\IncomingDocuments\IncomingMailService;
use Illuminate\View\View;
use Livewire\Component;

final class IncomingMailForm extends Component
{
    public string $tab = 'configuration';

    public bool $enabled = false;

    public string $imapHost = '';

    public int $imapPort = 993;

    public string $imapEncryption = 'ssl';

    public string $imapUsername = '';

    public string $imapPassword = '';

    public bool $passwordDejaEnregistre = false;

    public string $processedFolder = 'INBOX.Processed';

    public string $errorsFolder = 'INBOX.Errors';

    public int $maxPerRun = 50;

    /** @var array{success: bool, error: ?string, folderCount: int, inboxUnseen: ?int, processedCreated: bool, errorsCreated: bool}|null */
    public ?array $testResult = null;

    public string $nouveauEmail = '';

    public string $nouveauLabel = '';

    public function mount(): void
    {
        $params = IncomingMailParametres::where('association_id', 1)->first();
        if ($params !== null) {
            $this->enabled = $params->enabled;
            $this->imapHost = $params->imap_host ?? '';
            $this->imapPort = $params->imap_port;
            $this->imapEncryption = $params->imap_encryption;
            $this->imapUsername = $params->imap_username ?? '';
            $this->passwordDejaEnregistre = $params->imap_password !== null;
            $this->processedFolder = $params->processed_folder;
            $this->errorsFolder = $params->errors_folder;
            $this->maxPerRun = $params->max_per_run;
        }
    }

    public function changerOnglet(string $tab): void
    {
        $this->tab = $tab;
    }

    public function sauvegarder(): void
    {
        $this->validate([
            'imapHost' => ['nullable', 'string', 'max:255'],
            'imapPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'imapEncryption' => ['required', 'in:ssl,tls,starttls,none'],
            'imapUsername' => ['nullable', 'string', 'max:255'],
            'imapPassword' => ['nullable', 'string'],
            'processedFolder' => ['required', 'string', 'max:100'],
            'errorsFolder' => ['required', 'string', 'max:100'],
            'maxPerRun' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $payload = [
            'enabled' => $this->enabled,
            'imap_host' => $this->imapHost ?: null,
            'imap_port' => $this->imapPort,
            'imap_encryption' => $this->imapEncryption,
            'imap_username' => $this->imapUsername ?: null,
            'processed_folder' => $this->processedFolder,
            'errors_folder' => $this->errorsFolder,
            'max_per_run' => $this->maxPerRun,
        ];

        if ($this->imapPassword !== '') {
            $payload['imap_password'] = $this->imapPassword;
            $this->passwordDejaEnregistre = true;
            $this->imapPassword = '';
        }

        IncomingMailParametres::updateOrCreate(['association_id' => 1], $payload);

        $this->testResult = null;
        session()->flash('success', 'Paramètres de réception enregistrés.');
    }

    public function testerConnexion(IncomingMailService $service): void
    {
        $this->validate([
            'imapHost' => ['required', 'string'],
            'imapPort' => ['required', 'integer'],
            'imapEncryption' => ['required', 'in:ssl,tls,starttls,none'],
            'imapUsername' => ['required', 'string'],
            'imapPassword' => $this->passwordDejaEnregistre ? ['nullable', 'string'] : ['required', 'string'],
        ]);

        $password = $this->imapPassword;
        if ($password === '' && $this->passwordDejaEnregistre) {
            $existing = IncomingMailParametres::where('association_id', 1)->first();
            $password = $existing?->imap_password ?? '';
        }

        $result = $service->testerConnexion(
            host: $this->imapHost,
            port: $this->imapPort,
            encryption: $this->imapEncryption,
            username: $this->imapUsername,
            password: $password,
            processedFolder: $this->processedFolder,
            errorsFolder: $this->errorsFolder,
        );

        $this->testResult = [
            'success' => $result->success,
            'error' => $result->erreur,
            'folderCount' => $result->folderCount,
            'inboxUnseen' => $result->inboxUnseen,
            'processedCreated' => $result->processedFolderCreated,
            'errorsCreated' => $result->errorsFolderCreated,
        ];
    }

    public function toggleEnabled(): void
    {
        if (! $this->enabled) {
            $errors = [];
            if ($this->imapHost === '') {
                $errors[] = 'hôte IMAP';
            }
            if ($this->imapUsername === '') {
                $errors[] = 'utilisateur';
            }
            if (! $this->passwordDejaEnregistre && $this->imapPassword === '') {
                $errors[] = 'mot de passe';
            }
            if (IncomingMailAllowedSender::where('association_id', 1)->count() === 0) {
                $errors[] = 'liste blanche expéditeurs vide';
            }

            if (! empty($errors)) {
                session()->flash('error', 'Impossible d\'activer : '.implode(', ', $errors));

                return;
            }
        }

        $this->enabled = ! $this->enabled;
        $this->sauvegarder();
    }

    public function ajouterExpediteur(): void
    {
        $this->validate([
            'nouveauEmail' => ['required', 'email', 'max:255'],
            'nouveauLabel' => ['nullable', 'string', 'max:255'],
        ]);

        $email = strtolower(trim($this->nouveauEmail));

        if (IncomingMailAllowedSender::where('association_id', 1)->where('email', $email)->exists()) {
            $this->addError('nouveauEmail', 'Cette adresse est déjà dans la liste.');

            return;
        }

        IncomingMailAllowedSender::create([
            'association_id' => 1,
            'email' => $email,
            'label' => $this->nouveauLabel ?: null,
        ]);

        $this->nouveauEmail = '';
        $this->nouveauLabel = '';
    }

    public function supprimerExpediteur(int $id): void
    {
        IncomingMailAllowedSender::where('association_id', 1)->where('id', $id)->delete();
    }

    public function render(): View
    {
        return view('livewire.parametres.incoming-mail-form', [
            'expediteurs' => IncomingMailAllowedSender::where('association_id', 1)
                ->orderBy('email')
                ->get(),
        ]);
    }
}
