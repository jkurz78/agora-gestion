<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\SmtpParametres;
use App\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

final class Wizard extends Component
{
    use WithFileUploads;

    public const TOTAL_STEPS = 9;

    public int $currentStep = 1;

    public array $state = [];

    // Step 1 — Identité
    #[Validate('required|string|max:255')]
    public string $identiteAdresse = '';

    #[Validate('required|string|max:10')]
    public string $identiteCodePostal = '';

    #[Validate('required|string|max:100')]
    public string $identiteVille = '';

    #[Validate('required|email|max:255')]
    public string $identiteEmail = '';

    #[Validate('nullable|string|max:20')]
    public ?string $identiteTelephone = null;

    #[Validate('nullable|string|size:14|regex:/^\d{14}$/')]
    public ?string $identiteSiret = null;

    #[Validate('nullable|string|max:100')]
    public ?string $identiteFormeJuridique = null;

    public $logoUpload = null;
    public $cachetUpload = null;

    // Step 2 — Exercice
    #[Validate('required|integer|between:1,12')]
    public int $exerciceMoisDebut = 1;

    // Step 3 — Compte bancaire principal
    #[Validate('required|string|max:100')]
    public string $banqueNom = '';

    #[Validate('required|string|max:34')]
    public string $banqueIban = '';

    #[Validate('nullable|string|max:11')]
    public ?string $banqueBic = null;

    #[Validate('nullable|string|max:255')]
    public ?string $banqueDomiciliation = null;

    #[Validate('required|numeric')]
    public float $banqueSoldeInitial = 0.0;

    #[Validate('required|date')]
    public string $banqueDateSoldeInitial = '';

    // Step 4 — SMTP
    #[Validate('required|string|max:255')]
    public string $smtpHost = '';

    #[Validate('required|integer|between:1,65535')]
    public int $smtpPort = 587;

    #[Validate('nullable|in:tls,ssl')]
    public ?string $smtpEncryption = 'tls';

    #[Validate('required|string|max:255')]
    public string $smtpUsername = '';

    #[Validate('required|string|max:255')]
    public string $smtpPassword = '';

    public bool $smtpEnabled = true;

    public ?string $smtpTestMessage = null;
    public ?string $smtpTestError = null;

    public function mount(): void
    {
        $association = $this->currentAssociation();

        $this->currentStep = max(1, (int) $association->wizard_current_step);
        $this->state = $association->wizard_state ?? [];

        $this->identiteAdresse = $association->adresse ?? '';
        $this->identiteCodePostal = $association->code_postal ?? '';
        $this->identiteVille = $association->ville ?? '';
        $this->identiteEmail = $association->email ?? '';
        $this->identiteTelephone = $association->telephone;
        $this->identiteSiret = $association->siret;
        $this->identiteFormeJuridique = $association->forme_juridique;
        $this->exerciceMoisDebut = (int) ($association->exercice_mois_debut ?? 1);
        $this->banqueDateSoldeInitial = now()->format('Y-m-d');

        $compteId = $this->state['compte_principal_id'] ?? null;
        if ($compteId !== null) {
            $compte = CompteBancaire::find($compteId);
            if ($compte !== null) {
                $this->banqueNom = (string) $compte->nom;
                $this->banqueIban = (string) $compte->iban;
                $this->banqueBic = $compte->bic;
                $this->banqueDomiciliation = $compte->domiciliation;
                $this->banqueSoldeInitial = (float) $compte->solde_initial;
                $this->banqueDateSoldeInitial = $compte->date_solde_initial?->format('Y-m-d') ?? now()->format('Y-m-d');
            }
        }

        $smtp = SmtpParametres::where('association_id', $association->id)->first();
        if ($smtp !== null) {
            $this->smtpHost = (string) ($smtp->smtp_host ?? '');
            $this->smtpPort = (int) ($smtp->smtp_port ?? 587);
            $this->smtpEncryption = $smtp->smtp_encryption;
            $this->smtpUsername = (string) ($smtp->smtp_username ?? '');
            $this->smtpPassword = (string) ($smtp->smtp_password ?? '');
            $this->smtpEnabled = (bool) $smtp->enabled;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step < 1 || $step > self::TOTAL_STEPS) {
            return;
        }

        if ($step > $this->currentStep) {
            return;
        }

        $this->currentStep = $step;
    }

    public function saveStep1(): void
    {
        $this->validate([
            'identiteAdresse'        => 'required|string|max:255',
            'identiteCodePostal'     => 'required|string|max:10',
            'identiteVille'          => 'required|string|max:100',
            'identiteEmail'          => 'required|email|max:255',
            'identiteTelephone'      => 'nullable|string|max:20',
            'identiteSiret'          => 'nullable|string|size:14|regex:/^\d{14}$/',
            'identiteFormeJuridique' => 'nullable|string|max:100',
            'logoUpload'             => 'nullable|image|max:2048',
            'cachetUpload'           => 'nullable|image|max:2048',
        ]);

        $association = $this->currentAssociation();

        $data = [
            'adresse'         => $this->identiteAdresse,
            'code_postal'     => $this->identiteCodePostal,
            'ville'           => $this->identiteVille,
            'email'           => $this->identiteEmail,
            'telephone'       => $this->identiteTelephone,
            'siret'           => $this->identiteSiret,
            'forme_juridique' => $this->identiteFormeJuridique,
        ];

        if ($this->logoUpload !== null) {
            $shortName = 'logo.'.$this->logoUpload->extension();
            $fullPath  = $association->storagePath('branding/'.$shortName);
            $dir       = dirname($fullPath);
            Storage::disk('local')->makeDirectory($dir);
            Storage::disk('local')->putFileAs($dir, $this->logoUpload, $shortName);

            if ($association->logo_path !== null) {
                $oldFull = $association->storagePath('branding/'.basename($association->logo_path));
                if ($oldFull !== $fullPath && Storage::disk('local')->exists($oldFull)) {
                    Storage::disk('local')->delete($oldFull);
                }
            }

            $data['logo_path'] = $shortName;
        }

        if ($this->cachetUpload !== null) {
            $shortName = 'cachet.'.$this->cachetUpload->extension();
            $fullPath  = $association->storagePath('branding/'.$shortName);
            $dir       = dirname($fullPath);
            Storage::disk('local')->makeDirectory($dir);
            Storage::disk('local')->putFileAs($dir, $this->cachetUpload, $shortName);

            if ($association->cachet_signature_path !== null) {
                $oldFull = $association->storagePath('branding/'.basename($association->cachet_signature_path));
                if ($oldFull !== $fullPath && Storage::disk('local')->exists($oldFull)) {
                    Storage::disk('local')->delete($oldFull);
                }
            }

            $data['cachet_signature_path'] = $shortName;
        }

        $association->update($data);
        $this->advanceTo(2);
    }

    public function saveStep2(): void
    {
        $this->validate([
            'exerciceMoisDebut' => 'required|integer|between:1,12',
        ]);

        $this->currentAssociation()->update([
            'exercice_mois_debut' => $this->exerciceMoisDebut,
        ]);

        $this->advanceTo(3);
    }

    public function saveStep3(): void
    {
        $this->validate([
            'banqueNom'              => 'required|string|max:100',
            'banqueIban'             => 'required|string|max:34',
            'banqueBic'              => 'nullable|string|max:11',
            'banqueDomiciliation'    => 'nullable|string|max:255',
            'banqueSoldeInitial'     => 'required|numeric',
            'banqueDateSoldeInitial' => 'required|date',
        ]);

        $association = $this->currentAssociation();

        $attributes = [
            'nom'                     => $this->banqueNom,
            'iban'                    => $this->banqueIban,
            'bic'                     => $this->banqueBic,
            'domiciliation'           => $this->banqueDomiciliation,
            'solde_initial'           => $this->banqueSoldeInitial,
            'date_solde_initial'      => $this->banqueDateSoldeInitial,
            'actif_recettes_depenses' => true,
            'est_systeme'             => false,
        ];

        $existingId = $this->state['compte_principal_id'] ?? null;
        $compte = $existingId !== null
            ? CompteBancaire::find($existingId)
            : null;

        if ($compte !== null) {
            $compte->update($attributes);
        } else {
            $compte = CompteBancaire::create($attributes + ['association_id' => $association->id]);
            $this->state['compte_principal_id'] = $compte->id;
            $association->update(['wizard_state' => $this->state]);
        }

        $this->advanceTo(4);
    }

    public function saveStep4(): void
    {
        $this->validate([
            'smtpHost'       => 'required|string|max:255',
            'smtpPort'       => 'required|integer|between:1,65535',
            'smtpEncryption' => 'nullable|in:tls,ssl',
            'smtpUsername'   => 'required|string|max:255',
            'smtpPassword'   => 'required|string|max:255',
        ]);

        $association = $this->currentAssociation();

        SmtpParametres::updateOrCreate(
            ['association_id' => $association->id],
            [
                'enabled'         => $this->smtpEnabled,
                'smtp_host'       => $this->smtpHost,
                'smtp_port'       => $this->smtpPort,
                'smtp_encryption' => $this->smtpEncryption,
                'smtp_username'   => $this->smtpUsername,
                'smtp_password'   => $this->smtpPassword,
                'timeout'         => 30,
            ],
        );

        $this->advanceTo(5);
    }

    public function skipStep4(): void
    {
        $association = $this->currentAssociation();
        SmtpParametres::updateOrCreate(
            ['association_id' => $association->id],
            ['enabled' => false],
        );
        $this->advanceTo(5);
    }

    public function testSmtp(): void
    {
        $this->validate([
            'smtpHost'       => 'required|string',
            'smtpPort'       => 'required|integer',
            'smtpEncryption' => 'nullable|in:tls,ssl',
            'smtpUsername'   => 'required|string',
            'smtpPassword'   => 'required|string',
        ]);

        $this->smtpTestMessage = null;
        $this->smtpTestError = null;

        try {
            $scheme = $this->smtpEncryption === 'ssl' ? 'smtps' : 'smtp';
            $transport = (new \Symfony\Component\Mailer\Transport\EsmtpTransportFactory())
                ->create(new \Symfony\Component\Mailer\Transport\Dsn(
                    $scheme,
                    $this->smtpHost,
                    $this->smtpUsername,
                    $this->smtpPassword,
                    $this->smtpPort,
                    ['encryption' => $this->smtpEncryption],
                ));
            $transport->start();
            $transport->stop();
            $this->smtpTestMessage = 'Connexion SMTP réussie.';
        } catch (\Throwable $e) {
            $this->smtpTestError = 'Échec : '.$e->getMessage();
        }
    }

    protected function advanceTo(int $step): void
    {
        $association = $this->currentAssociation();
        $this->currentStep = $step;
        $association->update([
            'wizard_current_step' => max($step, (int) $association->wizard_current_step),
        ]);
    }

    protected function currentAssociation(): Association
    {
        $association = TenantContext::current();

        if ($association === null) {
            abort(404, 'Tenant non résolu pour l\'onboarding.');
        }

        return $association;
    }

    public function render(): View
    {
        return view('livewire.onboarding.wizard');
    }
}
