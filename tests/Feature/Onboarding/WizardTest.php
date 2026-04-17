<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Livewire\Onboarding\Wizard;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\IncomingMailParametres;
use App\Models\SmtpParametres;
use App\Models\SousCategorie;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\SmtpService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->unonboarded()->create([
        'wizard_current_step' => 1,
        'wizard_state' => null,
    ]);
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

it('loads at step 1 on fresh mount', function () {
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('currentStep', 1);
});

it('resumes at persisted step on mount', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('currentStep', 3);
});

it('allows jumping backwards to a previous step', function () {
    $this->association->update(['wizard_current_step' => 4]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 2)
        ->assertSet('currentStep', 2);
});

it('rejects jumping forward beyond current step', function () {
    $this->association->update(['wizard_current_step' => 2]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 5)
        ->assertSet('currentStep', 2);
});

it('hydrates state from wizard_state on mount', function () {
    $this->association->update(['wizard_state' => ['identite' => ['nom' => 'Foo']]]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('state.identite.nom', 'Foo');
});

it('rejects goToStep 0 (out of lower bound)', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 0)
        ->assertSet('currentStep', 3);
});

it('rejects goToStep -1 (negative)', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', -1)
        ->assertSet('currentStep', 3);
});

it('rejects goToStep beyond TOTAL_STEPS', function () {
    $this->association->update(['wizard_current_step' => 5]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 10)
        ->assertSet('currentStep', 5);
});

it('saves step 1 identité and advances to step 2', function () {
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '1 rue de la Paix')
        ->set('identiteCodePostal', '75001')
        ->set('identiteVille', 'Paris')
        ->set('identiteEmail', 'contact@asso.example')
        ->set('identiteTelephone', '0123456789')
        ->set('identiteSiret', '12345678901234')
        ->set('identiteFormeJuridique', 'Association loi 1901')
        ->call('saveStep1')
        ->assertSet('currentStep', 2);

    $fresh = $this->association->fresh();
    expect($fresh->adresse)->toBe('1 rue de la Paix');
    expect($fresh->code_postal)->toBe('75001');
    expect($fresh->ville)->toBe('Paris');
    expect($fresh->email)->toBe('contact@asso.example');
    expect($fresh->telephone)->toBe('0123456789');
    expect($fresh->siret)->toBe('12345678901234');
    expect($fresh->forme_juridique)->toBe('Association loi 1901');
    expect($fresh->wizard_current_step)->toBe(2);
});

it('rejects step 1 with missing required fields', function () {
    // NOTE : mount() hydrates from factory defaults. Empty them explicitly to trigger required-rule errors.
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '')
        ->set('identiteCodePostal', '')
        ->set('identiteVille', '')
        ->set('identiteEmail', '')
        ->call('saveStep1')
        ->assertHasErrors(['identiteAdresse', 'identiteCodePostal', 'identiteVille', 'identiteEmail']);
});

it('saves step 2 exercice (mois de début) and advances to step 3', function () {
    $this->association->update(['wizard_current_step' => 2]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('exerciceMoisDebut', 9)
        ->call('saveStep2')
        ->assertSet('currentStep', 3);

    expect($this->association->fresh()->exercice_mois_debut)->toBe(9);
    expect($this->association->fresh()->wizard_current_step)->toBe(3);
});

it('rejects step 2 with mois hors plage 1..12', function () {
    $this->association->update(['wizard_current_step' => 2]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('exerciceMoisDebut', 13)
        ->call('saveStep2')
        ->assertHasErrors(['exerciceMoisDebut']);
});

it('stores uploaded logo at associations/{id}/branding/ with short filename', function () {
    Storage::fake('local');
    $id = $this->association->id;
    $file = UploadedFile::fake()->image('original.png', 200, 200);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '1 rue X')
        ->set('identiteCodePostal', '75001')
        ->set('identiteVille', 'Paris')
        ->set('identiteEmail', 'a@b.c')
        ->set('logoUpload', $file)
        ->call('saveStep1')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists("associations/{$id}/branding/logo.png");
    expect($this->association->fresh()->logo_path)->toBe('logo.png');
});

it('stores uploaded cachet at associations/{id}/branding/ with short filename', function () {
    Storage::fake('local');
    $id = $this->association->id;
    $file = UploadedFile::fake()->image('signature.png', 200, 200);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '1 rue X')
        ->set('identiteCodePostal', '75001')
        ->set('identiteVille', 'Paris')
        ->set('identiteEmail', 'a@b.c')
        ->set('cachetUpload', $file)
        ->call('saveStep1')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists("associations/{$id}/branding/cachet.png");
    expect($this->association->fresh()->cachet_signature_path)->toBe('cachet.png');
});

it('saves step 3 compte bancaire and advances to step 4', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('banqueNom', 'Compte courant principal')
        ->set('banqueIban', 'FR7630001007941234567890185')
        ->set('banqueBic', 'BDFEFRPPCCT')
        ->set('banqueDomiciliation', 'Banque de France Paris')
        ->set('banqueSoldeInitial', 1500.50)
        ->set('banqueDateSoldeInitial', '2026-01-01')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    $compte = CompteBancaire::where('association_id', $this->association->id)->first();
    expect($compte)->not->toBeNull();
    expect($compte->nom)->toBe('Compte courant principal');
    expect($compte->iban)->toBe('FR7630001007941234567890185');
    expect($compte->bic)->toBe('BDFEFRPPCCT');
    expect((float) $compte->solde_initial)->toBe(1500.50);
    expect($compte->actif_recettes_depenses)->toBeTrue();
    expect($compte->est_systeme)->toBeFalse();
    expect($this->association->fresh()->wizard_current_step)->toBe(4);
});

it('rejects step 3 with missing IBAN', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('banqueNom', 'Test')
        ->set('banqueIban', '')
        ->call('saveStep3')
        ->assertHasErrors(['banqueIban']);
});

it('reuses existing compte principal on step 3 re-submit (no duplicate row)', function () {
    $this->association->update(['wizard_current_step' => 3]);

    // First submission
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('banqueNom', 'Compte A')
        ->set('banqueIban', 'FR7630001007941234567890185')
        ->set('banqueSoldeInitial', 100.00)
        ->set('banqueDateSoldeInitial', '2026-01-01')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    expect(CompteBancaire::where('association_id', $this->association->id)->count())->toBe(1);
    $firstId = CompteBancaire::where('association_id', $this->association->id)->value('id');

    // Second submission with updated values (wizard_state now has compte_principal_id)
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('banqueNom', 'Compte A renommé')
        ->set('banqueIban', 'FR7630001007941234567890185')
        ->set('banqueSoldeInitial', 250.00)
        ->set('banqueDateSoldeInitial', '2026-02-01')
        ->call('saveStep3');

    expect(CompteBancaire::where('association_id', $this->association->id)->count())->toBe(1);
    $compte = CompteBancaire::find($firstId);
    expect($compte->nom)->toBe('Compte A renommé');
    expect((float) $compte->solde_initial)->toBe(250.00);
});

it('advanceTo does not downgrade wizard_current_step', function () {
    $this->association->update(['wizard_current_step' => 5]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('currentStep', 2)
        ->set('exerciceMoisDebut', 9)
        ->call('saveStep2');  // advanceTo(3) but persisted was 5

    expect($this->association->fresh()->wizard_current_step)->toBe(5);
});

it('saves step 4 SMTP and advances to step 5', function () {
    $this->association->update(['wizard_current_step' => 4]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpPort', 587)
        ->set('smtpEncryption', 'tls')
        ->set('smtpUsername', 'user@example.com')
        ->set('smtpPassword', 'secret')
        ->set('smtpEnabled', true)
        ->call('saveStep4')
        ->assertSet('currentStep', 5);

    $smtp = SmtpParametres::where('association_id', $this->association->id)->first();
    expect($smtp)->not->toBeNull();
    expect($smtp->smtp_host)->toBe('smtp.example.com');
    expect($smtp->smtp_port)->toBe(587);
    expect($smtp->enabled)->toBeTrue();
    expect($this->association->fresh()->wizard_current_step)->toBe(5);
});

it('rejects step 4 with missing host', function () {
    $this->association->update(['wizard_current_step' => 4]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('smtpHost', '')
        ->call('saveStep4')
        ->assertHasErrors(['smtpHost']);
});

it('allows step 4 to be skipped (SMTP disabled)', function () {
    $this->association->update(['wizard_current_step' => 4]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('skipStep4')
        ->assertSet('currentStep', 5);

    $smtp = SmtpParametres::where('association_id', $this->association->id)->first();
    expect($smtp?->enabled ?? false)->toBeFalse();
});

it('preserves prior SMTP credentials when skipping step 4', function () {
    $this->association->update(['wizard_current_step' => 4]);

    SmtpParametres::create([
        'association_id' => $this->association->id,
        'enabled' => true,
        'smtp_host' => 'smtp.ex.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'foo@ex.com',
        'smtp_password' => 'secret',
        'timeout' => 30,
    ]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('skipStep4')
        ->assertSet('currentStep', 5);

    $smtp = SmtpParametres::where('association_id', $this->association->id)->first();
    expect($smtp)->not->toBeNull();
    expect($smtp->smtp_host)->toBe('smtp.ex.com');
    expect($smtp->smtp_username)->toBe('foo@ex.com');
    expect($smtp->smtp_password)->toBe('secret');
    expect($smtp->enabled)->toBeFalse();
});

it('testSmtp surfaces success banner from service', function () {
    $this->association->update(['wizard_current_step' => 4]);

    $r = new stdClass;
    $r->success = true;
    $r->error = null;
    $r->banner = '220 smtp.ex.com ESMTP';

    app()->bind(SmtpService::class, fn () => new class($r)
    {
        public function __construct(private readonly stdClass $result) {}

        public function testerConnexion(string $host, int $port, string $encryption, string $username, string $password, int $timeout = 10): stdClass
        {
            return $this->result;
        }
    });

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('smtpHost', 'smtp.ex.com')
        ->set('smtpPort', 587)
        ->set('smtpEncryption', 'tls')
        ->call('testSmtp')
        ->assertSet('smtpTestError', null)
        ->assertSeeHtml('Connexion SMTP réussie');
});

it('testSmtp surfaces failure error from service', function () {
    $this->association->update(['wizard_current_step' => 4]);

    $r = new stdClass;
    $r->success = false;
    $r->error = 'Connexion refusée (errno 111)';
    $r->banner = null;

    app()->bind(SmtpService::class, fn () => new class($r)
    {
        public function __construct(private readonly stdClass $result) {}

        public function testerConnexion(string $host, int $port, string $encryption, string $username, string $password, int $timeout = 10): stdClass
        {
            return $this->result;
        }
    });

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('smtpHost', 'smtp.ex.com')
        ->set('smtpPort', 587)
        ->set('smtpEncryption', 'tls')
        ->call('testSmtp')
        ->assertSet('smtpTestMessage', null)
        ->assertSeeHtml('Connexion refusée (errno 111)');
});

it('saves step 5 HelloAsso and advances to step 6', function () {
    $this->association->update(['wizard_current_step' => 5]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('helloClientId', 'abc123')
        ->set('helloClientSecret', 'supersecret')
        ->set('helloOrganisationSlug', 'mon-asso')
        ->set('helloEnvironnement', 'sandbox')
        ->call('saveStep5')
        ->assertSet('currentStep', 6);

    $ha = HelloAssoParametres::where('association_id', $this->association->id)->first();
    expect($ha)->not->toBeNull();
    expect($ha->client_id)->toBe('abc123');
    expect($ha->organisation_slug)->toBe('mon-asso');
    expect($ha->client_secret)->toBe('supersecret');
    expect($ha->callback_token)->not->toBeNull();
    $env = $ha->environnement;
    expect($env instanceof BackedEnum ? $env->value : $env)->toBe('sandbox');
});

it('skips step 5 HelloAsso without creating parameter row', function () {
    $this->association->update(['wizard_current_step' => 5]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('skipStep5')
        ->assertSet('currentStep', 6);

    $ha = HelloAssoParametres::where('association_id', $this->association->id)->first();
    expect($ha)->toBeNull();
});

it('preserves HelloAsso client secret on re-submit with blank field', function () {
    HelloAssoParametres::create([
        'association_id' => $this->association->id,
        'client_id' => 'old',
        'client_secret' => 'oldsecret',
        'organisation_slug' => 'old-slug',
        'environnement' => 'production',
        'callback_token' => 'tokoko',
    ]);
    $this->association->update(['wizard_current_step' => 5]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('helloClientId', 'newid')
        ->set('helloClientSecret', '')
        ->set('helloOrganisationSlug', 'new-slug')
        ->set('helloEnvironnement', 'sandbox')
        ->call('saveStep5')
        ->assertSet('currentStep', 6);

    $ha = HelloAssoParametres::where('association_id', $this->association->id)->first();
    expect($ha->client_id)->toBe('newid');
    expect($ha->client_secret)->toBe('oldsecret');
});

it('saves step 6 IMAP and advances to step 7', function () {
    $this->association->update(['wizard_current_step' => 6]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('imapHost', 'imap.example.com')
        ->set('imapPort', 993)
        ->set('imapEncryption', 'ssl')
        ->set('imapUsername', 'inbox@example.com')
        ->set('imapPassword', 'secret')
        ->call('saveStep6')
        ->assertSet('currentStep', 7);

    $imap = IncomingMailParametres::where('association_id', $this->association->id)->first();
    expect($imap)->not->toBeNull();
    expect($imap->imap_host)->toBe('imap.example.com');
    expect($imap->imap_port)->toBe(993);
    expect($imap->imap_username)->toBe('inbox@example.com');
    expect($imap->imap_password)->toBe('secret');
    expect($imap->enabled)->toBeTrue();
});

it('skips step 6 IMAP without creating parameter row', function () {
    $this->association->update(['wizard_current_step' => 6]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('skipStep6')
        ->assertSet('currentStep', 7);

    $imap = IncomingMailParametres::where('association_id', $this->association->id)->first();
    expect($imap)->toBeNull();
});

it('accepts step 6 IMAP with encryption none', function () {
    $this->association->update(['wizard_current_step' => 6]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('imapHost', 'imap.example.com')
        ->set('imapPort', 143)
        ->set('imapEncryption', 'none')
        ->set('imapUsername', 'inbox@example.com')
        ->set('imapPassword', 'secret')
        ->call('saveStep6')
        ->assertSet('currentStep', 7);

    $imap = IncomingMailParametres::where('association_id', $this->association->id)->first();
    expect($imap->imap_encryption)->toBe('none');
    expect($imap->processed_folder)->toBe('INBOX.Processed');
    expect($imap->errors_folder)->toBe('INBOX.Errors');
});

it('saves step 7 with default plan comptable and advances to step 8', function () {
    $this->association->update(['wizard_current_step' => 7]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('planComptableChoix', 'default')
        ->call('saveStep7')
        ->assertSet('currentStep', 8);

    $catCount = Categorie::where('association_id', $this->association->id)->count();
    $scCount = SousCategorie::where('association_id', $this->association->id)->count();
    expect($catCount)->toBeGreaterThan(0);
    expect($scCount)->toBeGreaterThan(0);
});

it('saves step 7 with empty plan and advances to step 8', function () {
    $this->association->update(['wizard_current_step' => 7]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('planComptableChoix', 'empty')
        ->call('saveStep7')
        ->assertSet('currentStep', 8);

    $catCount = Categorie::where('association_id', $this->association->id)->count();
    expect($catCount)->toBe(0);
});

it('saves step 8 TypeOperation and advances to step 9', function () {
    $this->association->update(['wizard_current_step' => 8]);
    $categorie = Categorie::create([
        'association_id' => $this->association->id,
        'nom' => 'Cat test',
        'type' => TypeCategorie::Recette,
    ]);
    $sc = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
        'nom' => 'Sous-cat test',
        'pour_dons' => false,
        'pour_cotisations' => false,
        'pour_inscriptions' => false,
    ]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('typeOpNom', 'Adhésion annuelle')
        ->set('typeOpDescription', 'Cotisation annuelle membre')
        ->set('typeOpSousCategorieId', $sc->id)
        ->call('saveStep8')
        ->assertSet('currentStep', 9);

    $type = TypeOperation::where('association_id', $this->association->id)->first();
    expect($type)->not->toBeNull();
    expect($type->nom)->toBe('Adhésion annuelle');
    expect((int) $type->sous_categorie_id)->toBe((int) $sc->id);
    expect($type->actif)->toBeTrue();
});

it('skips step 8 TypeOperation', function () {
    $this->association->update(['wizard_current_step' => 8]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('skipStep8')
        ->assertSet('currentStep', 9);

    $count = TypeOperation::where('association_id', $this->association->id)->count();
    expect($count)->toBe(0);
});

it('does not re-apply default plan on step 7 re-submit', function () {
    $this->association->update(['wizard_current_step' => 7]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('planComptableChoix', 'default')
        ->call('saveStep7')
        ->assertSet('currentStep', 8);

    $firstCount = Categorie::where('association_id', $this->association->id)->count();
    expect($firstCount)->toBeGreaterThan(0);

    // Simulate back + re-submit
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('planComptableChoix', 'default')
        ->call('saveStep7');

    $secondCount = Categorie::where('association_id', $this->association->id)->count();
    expect($secondCount)->toBe($firstCount);
});

it('reuses existing TypeOperation on step 8 re-submit (no duplicate)', function () {
    $this->association->update(['wizard_current_step' => 8]);
    $categorie = Categorie::create([
        'association_id' => $this->association->id,
        'nom' => 'Cat test',
        'type' => TypeCategorie::Recette,
    ]);
    $sc = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
        'nom' => 'Sous-cat test',
        'pour_dons' => false,
        'pour_cotisations' => false,
        'pour_inscriptions' => false,
    ]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('typeOpNom', 'Initial')
        ->set('typeOpSousCategorieId', $sc->id)
        ->call('saveStep8')
        ->assertSet('currentStep', 9);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('typeOpNom', 'Renommé')
        ->set('typeOpSousCategorieId', $sc->id)
        ->call('saveStep8');

    $count = TypeOperation::where('association_id', $this->association->id)->count();
    expect($count)->toBe(1);
    $type = TypeOperation::where('association_id', $this->association->id)->first();
    expect($type->nom)->toBe('Renommé');
});

it('saves step 7 with empty plan leaves zero sous-categories', function () {
    $this->association->update(['wizard_current_step' => 7]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('planComptableChoix', 'empty')
        ->call('saveStep7')
        ->assertSet('currentStep', 8);

    $scCount = SousCategorie::where('association_id', $this->association->id)->count();
    expect($scCount)->toBe(0);
});

it('finalizes wizard and redirects to dashboard', function () {
    $this->association->update(['wizard_current_step' => 9]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('finalize')
        ->assertRedirect('/dashboard');

    $fresh = $this->association->fresh();
    expect($fresh->wizard_completed_at)->not->toBeNull();
});

it('cannot finalize from a step earlier than 9', function () {
    $this->association->update(['wizard_current_step' => 5]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('finalize')
        ->assertNoRedirect();

    expect($this->association->fresh()->wizard_completed_at)->toBeNull();
});

it('allows access to dashboard after finalize', function () {
    $this->association->update(['wizard_current_step' => 9]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('finalize');

    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk();
});

it('completes the full 9-step wizard end to end', function () {
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '1 rue de la Paix')
        ->set('identiteCodePostal', '75001')
        ->set('identiteVille', 'Paris')
        ->set('identiteEmail', 'contact@asso.example')
        ->call('saveStep1')
        ->assertSet('currentStep', 2)
        ->set('exerciceMoisDebut', 1)
        ->call('saveStep2')
        ->assertSet('currentStep', 3)
        ->set('banqueNom', 'Compte principal')
        ->set('banqueIban', 'FR7630001007941234567890185')
        ->set('banqueSoldeInitial', 0)
        ->set('banqueDateSoldeInitial', now()->format('Y-m-d'))
        ->call('saveStep3')
        ->assertSet('currentStep', 4)
        ->call('skipStep4')
        ->assertSet('currentStep', 5)
        ->call('skipStep5')
        ->assertSet('currentStep', 6)
        ->call('skipStep6')
        ->assertSet('currentStep', 7)
        ->set('planComptableChoix', 'default')
        ->call('saveStep7')
        ->assertSet('currentStep', 8)
        ->call('skipStep8')
        ->assertSet('currentStep', 9)
        ->call('finalize')
        ->assertRedirect('/dashboard');

    expect($this->association->fresh()->wizard_completed_at)->not->toBeNull();
});
