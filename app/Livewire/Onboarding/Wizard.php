<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\IncomingMailParametres;
use App\Models\SmtpParametres;
use App\Models\SousCategorie;
use App\Models\TypeOperation;
use App\Services\Onboarding\DefaultChartOfAccountsService;
use App\Services\SmtpService;
use App\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    #[Validate('required|in:ssl,tls,starttls,none')]
    public string $smtpEncryption = 'tls';

    #[Validate('nullable|string|max:255')]
    public string $smtpUsername = '';

    #[Validate('nullable|string|max:255')]
    public string $smtpPassword = '';

    public bool $passwordDejaEnregistre = false;

    public bool $smtpEnabled = true;

    public ?string $smtpTestMessage = null;

    public ?string $smtpTestError = null;

    // Step 5 — HelloAsso
    #[Validate('required|string|max:255')]
    public string $helloClientId = '';

    #[Validate('required|string|max:255')]
    public string $helloClientSecret = '';

    #[Validate('required|string|max:100')]
    public string $helloOrganisationSlug = '';

    #[Validate('required|in:sandbox,production')]
    public string $helloEnvironnement = 'production';

    public bool $helloSecretDejaEnregistre = false;

    // Step 6 — IMAP
    #[Validate('required|string|max:255')]
    public string $imapHost = '';

    #[Validate('required|integer|between:1,65535')]
    public int $imapPort = 993;

    #[Validate('required|in:ssl,tls,starttls,none')]
    public ?string $imapEncryption = 'ssl';

    #[Validate('required|string|max:255')]
    public string $imapUsername = '';

    #[Validate('nullable|string|max:255')]
    public string $imapPassword = '';

    public bool $imapPasswordDejaEnregistre = false;

    public string $imapProcessedFolder = 'INBOX.Processed';

    public string $imapErrorsFolder = 'INBOX.Errors';

    // Step 7 — Plan comptable
    #[Validate('required|in:default,empty')]
    public string $planComptableChoix = 'default';

    public ?int $planComptableCategoriesCount = null;

    // Step 8 — TypeOperation
    #[Validate('required|string|max:100')]
    public string $typeOpNom = '';

    #[Validate('nullable|string|max:255')]
    public ?string $typeOpDescription = null;

    #[Validate('required|integer|exists:sous_categories,id')]
    public ?int $typeOpSousCategorieId = null;

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
            $this->smtpEncryption = $smtp->smtp_encryption ?? 'tls';
            $this->smtpUsername = (string) ($smtp->smtp_username ?? '');
            $this->passwordDejaEnregistre = $smtp->smtp_password !== null;
            // Do not decrypt password into view — leave smtpPassword as ''
            $this->smtpEnabled = (bool) $smtp->enabled;
        }

        $hello = HelloAssoParametres::where('association_id', $association->id)->first();
        if ($hello !== null) {
            $this->helloClientId = (string) ($hello->client_id ?? '');
            $this->helloOrganisationSlug = (string) ($hello->organisation_slug ?? '');
            $env = $hello->environnement;
            $this->helloEnvironnement = $env instanceof \BackedEnum ? (string) $env->value : (string) $env;
            $this->helloSecretDejaEnregistre = $hello->client_secret !== null;
        }

        $imap = IncomingMailParametres::where('association_id', $association->id)->first();
        if ($imap !== null) {
            $this->imapHost = (string) ($imap->imap_host ?? '');
            $this->imapPort = (int) ($imap->imap_port ?? 993);
            $this->imapEncryption = $imap->imap_encryption;
            $this->imapUsername = (string) ($imap->imap_username ?? '');
            $this->imapProcessedFolder = (string) ($imap->processed_folder ?? 'INBOX.Processed');
            $this->imapErrorsFolder = (string) ($imap->errors_folder ?? 'INBOX.Errors');
            $this->imapPasswordDejaEnregistre = $imap->imap_password !== null;
        }

        if (isset($this->state['plan_comptable_categories_count'])) {
            $this->planComptableCategoriesCount = (int) $this->state['plan_comptable_categories_count'];
        }

        $typeOpId = $this->state['type_operation_id'] ?? null;
        if ($typeOpId !== null) {
            $typeOp = TypeOperation::find($typeOpId);
            if ($typeOp !== null) {
                $this->typeOpNom = (string) $typeOp->nom;
                $this->typeOpDescription = $typeOp->description;
                $this->typeOpSousCategorieId = (int) $typeOp->sous_categorie_id;
            }
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
            'identiteAdresse' => 'required|string|max:255',
            'identiteCodePostal' => 'required|string|max:10',
            'identiteVille' => 'required|string|max:100',
            'identiteEmail' => 'required|email|max:255',
            'identiteTelephone' => 'nullable|string|max:20',
            'identiteSiret' => 'nullable|string|size:14|regex:/^\d{14}$/',
            'identiteFormeJuridique' => 'nullable|string|max:100',
            'logoUpload' => 'nullable|image|max:2048',
            'cachetUpload' => 'nullable|image|max:2048',
        ]);

        $association = $this->currentAssociation();

        $data = [
            'adresse' => $this->identiteAdresse,
            'code_postal' => $this->identiteCodePostal,
            'ville' => $this->identiteVille,
            'email' => $this->identiteEmail,
            'telephone' => $this->identiteTelephone,
            'siret' => $this->identiteSiret,
            'forme_juridique' => $this->identiteFormeJuridique,
        ];

        if ($this->logoUpload !== null) {
            $shortName = 'logo.'.$this->logoUpload->extension();
            $fullPath = $association->storagePath('branding/'.$shortName);
            $dir = dirname($fullPath);
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
            $fullPath = $association->storagePath('branding/'.$shortName);
            $dir = dirname($fullPath);
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
            'banqueNom' => 'required|string|max:100',
            'banqueIban' => 'required|string|max:34',
            'banqueBic' => 'nullable|string|max:11',
            'banqueDomiciliation' => 'nullable|string|max:255',
            'banqueSoldeInitial' => 'required|numeric',
            'banqueDateSoldeInitial' => 'required|date',
        ]);

        $association = $this->currentAssociation();

        $attributes = [
            'nom' => $this->banqueNom,
            'iban' => $this->banqueIban,
            'bic' => $this->banqueBic,
            'domiciliation' => $this->banqueDomiciliation,
            'solde_initial' => $this->banqueSoldeInitial,
            'date_solde_initial' => $this->banqueDateSoldeInitial,
            'actif_recettes_depenses' => true,
            'est_systeme' => false,
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
            'smtpHost' => 'required|string|max:255',
            'smtpPort' => 'required|integer|between:1,65535',
            'smtpEncryption' => 'required|in:ssl,tls,starttls,none',
            'smtpUsername' => 'nullable|string|max:255',
            'smtpPassword' => 'nullable|string|max:255',
        ]);

        if (! $this->passwordDejaEnregistre && $this->smtpPassword === '') {
            $this->addError('smtpPassword', 'Le mot de passe est obligatoire.');

            return;
        }

        $association = $this->currentAssociation();

        $payload = [
            'enabled' => $this->smtpEnabled,
            'smtp_host' => $this->smtpHost,
            'smtp_port' => $this->smtpPort,
            'smtp_encryption' => $this->smtpEncryption,
            'smtp_username' => $this->smtpUsername ?: null,
            // Runtime mail timeout (connexion test uses 10s, see SmtpService).
            'timeout' => 30,
        ];

        if ($this->smtpPassword !== '') {
            $payload['smtp_password'] = $this->smtpPassword;
            $this->passwordDejaEnregistre = true;
            $this->smtpPassword = '';
        }

        SmtpParametres::updateOrCreate(
            ['association_id' => $association->id],
            $payload,
        );

        $this->advanceTo(5);
    }

    public function skipStep4(): void
    {
        $association = $this->currentAssociation();
        // Keep existing host/credentials intact — user may resume configuration later.
        SmtpParametres::updateOrCreate(
            ['association_id' => $association->id],
            ['enabled' => false],
        );
        $this->advanceTo(5);
    }

    public function testSmtp(): void
    {
        $this->validate([
            'smtpHost' => 'required|string',
            'smtpPort' => 'required|integer',
            'smtpEncryption' => 'required|in:ssl,tls,starttls,none',
        ]);

        $this->smtpTestMessage = null;
        $this->smtpTestError = null;

        /** @var SmtpService $service */
        $service = app(SmtpService::class);

        $result = $service->testerConnexion(
            host: $this->smtpHost,
            port: $this->smtpPort,
            encryption: $this->smtpEncryption,
            username: $this->smtpUsername,
            password: $this->smtpPassword,
            timeout: 10,
        );

        if ($result->success === true) {
            $this->smtpTestMessage = 'Connexion SMTP réussie. '.$result->banner;
        } else {
            $this->smtpTestError = 'Échec : '.$result->error;
        }
    }

    public function saveStep5(): void
    {
        $this->validate([
            'helloClientId' => 'required|string|max:255',
            'helloClientSecret' => ($this->helloSecretDejaEnregistre ? 'nullable' : 'required').'|string|max:255',
            'helloOrganisationSlug' => 'required|string|max:100',
            'helloEnvironnement' => 'required|in:sandbox,production',
        ]);

        $asso = $this->currentAssociation();

        $existing = HelloAssoParametres::where('association_id', $asso->id)->first();

        $payload = [
            'client_id' => $this->helloClientId,
            'organisation_slug' => $this->helloOrganisationSlug,
            'environnement' => $this->helloEnvironnement,
        ];

        if ($this->helloClientSecret !== '') {
            $payload['client_secret'] = $this->helloClientSecret;
        }

        if ($existing === null) {
            $payload['callback_token'] = Str::random(40);
            HelloAssoParametres::create($payload + ['association_id' => $asso->id]);
        } else {
            $existing->update($payload);
        }

        $this->helloSecretDejaEnregistre = true;
        $this->helloClientSecret = '';

        $this->advanceTo(6);
    }

    public function skipStep5(): void
    {
        $this->advanceTo(6);
    }

    public function saveStep6(): void
    {
        $this->validate([
            'imapHost' => 'required|string|max:255',
            'imapPort' => 'required|integer|between:1,65535',
            'imapEncryption' => 'required|in:ssl,tls,starttls,none',
            'imapUsername' => 'required|string|max:255',
            'imapPassword' => ($this->imapPasswordDejaEnregistre ? 'nullable' : 'required').'|string|max:255',
            'imapProcessedFolder' => 'required|string|max:100',
            'imapErrorsFolder' => 'required|string|max:100',
        ]);

        $asso = $this->currentAssociation();
        $existing = IncomingMailParametres::where('association_id', $asso->id)->first();

        $payload = [
            'enabled' => true,
            'imap_host' => $this->imapHost,
            'imap_port' => $this->imapPort,
            'imap_encryption' => $this->imapEncryption,
            'imap_username' => $this->imapUsername,
            'processed_folder' => $this->imapProcessedFolder,
            'errors_folder' => $this->imapErrorsFolder,
            'max_per_run' => 50,
        ];

        if ($this->imapPassword !== '') {
            $payload['imap_password'] = $this->imapPassword;
        }

        if ($existing === null) {
            IncomingMailParametres::create($payload + ['association_id' => $asso->id]);
        } else {
            $existing->update($payload);
        }

        $this->imapPasswordDejaEnregistre = true;
        $this->imapPassword = '';

        $this->advanceTo(7);
    }

    public function skipStep6(): void
    {
        $this->advanceTo(7);
    }

    public function saveStep7(): void
    {
        $this->validate(['planComptableChoix' => 'required|in:default,empty']);

        $asso = $this->currentAssociation();

        if ($this->planComptableChoix === 'default' && ! ($this->state['plan_comptable_applied'] ?? false)) {
            $result = app(DefaultChartOfAccountsService::class)->applyTo($asso);
            $this->planComptableCategoriesCount = $result['categories'];
            $this->state['plan_comptable_applied'] = true;
            $this->state['plan_comptable_categories_count'] = $result['categories'];
            $asso->update(['wizard_state' => $this->state]);
        }

        $this->advanceTo(8);
    }

    public function saveStep8(): void
    {
        $this->validate([
            'typeOpNom' => 'required|string|max:100',
            'typeOpDescription' => 'nullable|string|max:255',
            'typeOpSousCategorieId' => 'required|integer|exists:sous_categories,id',
        ]);

        $asso = $this->currentAssociation();

        $attributes = [
            'nom' => $this->typeOpNom,
            'description' => $this->typeOpDescription,
            'sous_categorie_id' => $this->typeOpSousCategorieId,
            'actif' => true,
        ];

        $existingId = $this->state['type_operation_id'] ?? null;
        $type = $existingId !== null ? TypeOperation::find($existingId) : null;

        if ($type !== null) {
            $type->update($attributes);
        } else {
            $type = TypeOperation::create($attributes + ['association_id' => $asso->id]);
            $this->state['type_operation_id'] = $type->id;
            $asso->update(['wizard_state' => $this->state]);
        }

        $this->advanceTo(9);
    }

    public function skipStep8(): void
    {
        $this->advanceTo(9);
    }

    public function finalize(): void
    {
        if ($this->currentStep < 9) {
            return;
        }

        $this->currentAssociation()->update([
            'wizard_completed_at' => now(),
        ]);

        $this->redirect('/dashboard');
    }

    public function getSousCategoriesProperty(): Collection
    {
        return SousCategorie::orderBy('nom')->get();
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
