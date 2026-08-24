<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Exceptions\DemoOperationBlockedException;
use App\Livewire\Parametres\Concerns\AutoriseEcranParametre;
use App\Mail\TestEmail;
use App\Models\SmtpParametres;
use App\Services\SmtpService;
use App\Support\CurrentAssociation;
use App\Support\Demo;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Livewire\Component;

final class SmtpForm extends Component
{
    use AutoriseEcranParametre;

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

    public ?string $email_from = null;

    public ?string $email_from_name = null;

    public string $testEmailTo = '';

    public bool $showTestEmailModal = false;

    public string $testFlashMessage = '';

    public string $testFlashType = '';

    protected function cleEcranParametre(): string
    {
        return 'envoi-emails';
    }

    public function mount(): void
    {
        $params = SmtpParametres::where('association_id', TenantContext::currentId())->first();
        if ($params !== null) {
            $this->enabled = $params->enabled;
            $this->smtpHost = $params->smtp_host ?? '';
            $this->smtpPort = $params->smtp_port;
            $this->smtpEncryption = $params->smtp_encryption;
            $this->smtpUsername = $params->smtp_username ?? '';
            $this->passwordDejaEnregistre = $params->smtp_password !== null;
            $this->timeout = $params->timeout;
        }

        $association = CurrentAssociation::tryGet();
        if ($association !== null) {
            $this->email_from = $association->email_from;
            $this->email_from_name = $association->email_from_name;
        }
    }

    public function sauvegarder(): void
    {
        if (Demo::isActive()) {
            throw new DemoOperationBlockedException('configuration SMTP');
        }

        $this->validate([
            'smtpHost' => ['nullable', 'string', 'max:255'],
            'smtpPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtpEncryption' => ['required', 'in:ssl,tls,starttls,none'],
            'smtpUsername' => ['nullable', 'string', 'max:255'],
            'smtpPassword' => ['nullable', 'string'],
            'timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'email_from' => ['nullable', 'email', 'max:255'],
            'email_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'enabled' => $this->enabled,
            'smtp_host' => $this->smtpHost ?: null,
            'smtp_port' => $this->smtpPort,
            'smtp_encryption' => $this->smtpEncryption,
            'smtp_username' => $this->smtpUsername ?: null,
            'timeout' => $this->timeout,
        ];

        if ($this->smtpPassword !== '') {
            $payload['smtp_password'] = $this->smtpPassword;
            $this->passwordDejaEnregistre = true;
            $this->smtpPassword = '';
        }

        SmtpParametres::updateOrCreate(['association_id' => TenantContext::currentId()], $payload);

        CurrentAssociation::get()->fill([
            'email_from' => $this->email_from ?: null,
            'email_from_name' => $this->email_from_name ?: null,
        ])->save();

        $this->testResult = null;
        $this->dispatch('form-saved');
        session()->flash('success', 'Paramètres d\'envoi d\'e-mails enregistrés.');
    }

    public function testerConnexion(SmtpService $service): void
    {
        $this->validate([
            'smtpHost' => ['required', 'string'],
            'smtpPort' => ['required', 'integer'],
            'smtpEncryption' => ['required', 'in:ssl,tls,starttls,none'],
        ]);

        $password = $this->smtpPassword;
        if ($password === '' && $this->passwordDejaEnregistre) {
            $existing = SmtpParametres::where('association_id', TenantContext::currentId())->first();
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
            'error' => $result->error,
            'banner' => $result->banner,
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
                session()->flash('error', 'Impossible d\'activer : '.implode(', ', $errors));

                return;
            }
        }

        $this->enabled = ! $this->enabled;
        $this->sauvegarder();
    }

    public function openTestEmailModal(): void
    {
        $this->testEmailTo = '';
        $this->testFlashMessage = '';
        $this->testFlashType = '';
        $this->showTestEmailModal = true;
    }

    public function sendTestEmail(): void
    {
        $this->validate([
            'email_from' => 'required|email',
            'testEmailTo' => 'required|email',
        ], [
            'email_from.required' => "L'adresse d'expédition est requise.",
            'testEmailTo.required' => 'Veuillez saisir une adresse destinataire.',
            'testEmailTo.email' => "L'adresse destinataire n'est pas valide.",
        ]);

        try {
            $nomAssociation = CurrentAssociation::get()->nom ?: 'Association';

            Mail::mailer()
                ->to($this->testEmailTo)
                ->send((new TestEmail($nomAssociation))->from($this->email_from, $this->email_from_name ?: null));

            $this->testFlashMessage = "Email de test envoyé à {$this->testEmailTo}.";
            $this->testFlashType = 'success';
        } catch (\Throwable $e) {
            $this->testFlashMessage = 'Erreur lors de l\'envoi : '.$e->getMessage();
            $this->testFlashType = 'danger';
        }
    }

    public function render(): View
    {
        return view('livewire.parametres.smtp-form');
    }
}
