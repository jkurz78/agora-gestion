<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\SmtpParametres;
use App\Services\SmtpService;
use Illuminate\View\View;
use Livewire\Component;

final class SmtpForm extends Component
{
    public bool $enabled = false;

    public string $smtpHost = '';

    public int $smtpPort = 587;

    public string $smtpEncryption = 'tls';

    public string $smtpUsername = '';

    public string $smtpPassword = '';

    public bool $passwordDejaEnregistre = false;

    public int $timeout = 30;

    /** @var array{success: bool, error: ?string, banner: ?string}|null */
    public ?array $testResult = null;

    public function mount(): void
    {
        $params = SmtpParametres::where('association_id', 1)->first();
        if ($params === null) {
            return;
        }

        $this->enabled                = $params->enabled;
        $this->smtpHost               = $params->smtp_host ?? '';
        $this->smtpPort               = $params->smtp_port;
        $this->smtpEncryption         = $params->smtp_encryption;
        $this->smtpUsername           = $params->smtp_username ?? '';
        $this->passwordDejaEnregistre = $params->smtp_password !== null;
        $this->timeout                = $params->timeout;
    }

    public function sauvegarder(): void
    {
        $this->validate([
            'smtpHost'       => ['nullable', 'string', 'max:255'],
            'smtpPort'       => ['required', 'integer', 'min:1', 'max:65535'],
            'smtpEncryption' => ['required', 'in:ssl,tls,starttls,none'],
            'smtpUsername'   => ['nullable', 'string', 'max:255'],
            'smtpPassword'   => ['nullable', 'string'],
            'timeout'        => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        $payload = [
            'enabled'         => $this->enabled,
            'smtp_host'       => $this->smtpHost ?: null,
            'smtp_port'       => $this->smtpPort,
            'smtp_encryption' => $this->smtpEncryption,
            'smtp_username'   => $this->smtpUsername ?: null,
            'timeout'         => $this->timeout,
        ];

        if ($this->smtpPassword !== '') {
            $payload['smtp_password']     = $this->smtpPassword;
            $this->passwordDejaEnregistre = true;
            $this->smtpPassword           = '';
        }

        SmtpParametres::updateOrCreate(['association_id' => 1], $payload);
        $this->testResult = null;
        $this->dispatch('form-saved');
        session()->flash('success', 'Paramètres SMTP enregistrés.');
    }

    public function testerConnexion(SmtpService $service): void
    {
        $this->validate([
            'smtpHost'       => ['required', 'string'],
            'smtpPort'       => ['required', 'integer'],
            'smtpEncryption' => ['required', 'in:ssl,tls,starttls,none'],
        ]);

        $password = $this->smtpPassword;
        if ($password === '' && $this->passwordDejaEnregistre) {
            $existing = SmtpParametres::where('association_id', 1)->first();
            $password = $existing?->smtp_password ?? '';
        }

        $result = $service->testerConnexion(
            host: $this->smtpHost,
            port: $this->smtpPort,
            encryption: $this->smtpEncryption,
            username: $this->smtpUsername,
            password: $password,
            timeout: $this->timeout,
        );

        $this->testResult = [
            'success' => $result->success,
            'error'   => $result->error,
            'banner'  => $result->banner,
        ];
    }

    public function toggleEnabled(): void
    {
        if (! $this->enabled) {
            $errors = [];
            if ($this->smtpHost === '') {
                $errors[] = 'hôte SMTP';
            }
            if ($this->smtpUsername === '') {
                $errors[] = 'utilisateur';
            }
            if (! $this->passwordDejaEnregistre && $this->smtpPassword === '') {
                $errors[] = 'mot de passe';
            }

            if ($errors !== []) {
                session()->flash('error', 'Impossible d\'activer : ' . implode(', ', $errors));

                return;
            }
        }

        $this->enabled = ! $this->enabled;
        $this->sauvegarder();
    }

    public function render(): View
    {
        return view('livewire.parametres.smtp-form');
    }
}
