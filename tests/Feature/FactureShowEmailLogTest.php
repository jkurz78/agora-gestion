<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Livewire\FactureShow;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();

    $this->association = Association::factory()->create([
        'email_from' => 'asso@example.com',
        'email_from_name' => 'Association Test',
    ]);
    TenantContext::boot($this->association);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    $exercice = app(ExerciceService::class)->current();

    // Register stub routes needed by FactureShow::mount
    Route::middleware('web')->name('facturation.')->prefix('facturation')->group(function (): void {
        Route::get('/factures/{facture}/edit', fn () => '')->name('factures.edit');
    });

    $this->tiers = Tiers::factory()->pourRecettes()->create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@example.com',
        'adresse_ligne1' => '12 rue des Lilas',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $this->facture = Facture::create([
        'association_id' => $this->association->id,
        'numero' => 'F-'.$exercice.'-0001',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $this->tiers->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 150.00,
        'conditions_reglement' => 'Paiement à réception',
        'mentions_legales' => 'Association loi 1901',
        'exercice' => $exercice,
        'saisi_par' => $this->user->id,
    ]);

    FactureLigne::create([
        'facture_id' => $this->facture->id,
        'type' => TypeLigneFacture::Montant,
        'libelle' => 'Cotisation annuelle',
        'montant' => 150.00,
        'ordre' => 1,
    ]);

    // Email template for Document category
    $this->template = EmailTemplate::create([
        'association_id' => $this->association->id,
        'categorie' => CategorieEmail::Document->value,
        'type_operation_id' => null,
        'objet' => 'Votre facture n° {numero_document}',
        'corps' => '<p>Bonjour {prenom},</p><p>Veuillez trouver votre facture ci-joint.</p>',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('enregistre un EmailLog avec corps_html et attachment_path après envoi de facture depuis FactureShow', function (): void {
    Livewire::test(FactureShow::class, ['facture' => $this->facture])
        ->call('envoyerEmail');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();

    // corps_html doit être rempli (bug 3 fixé)
    expect($log->corps_html)->not->toBeNull();
    expect($log->corps_html)->toContain('Jean');

    // attachment_path doit pointer vers le dossier tenant (bug 4 fixé)
    expect($log->attachment_path)->not->toBeNull();
    expect($log->attachment_path)->toStartWith("associations/{$this->association->id}/email_attachments/");

    // Le fichier doit exister sur le disque local fake
    Storage::disk('local')->assertExists($log->attachment_path);

    // Le PDF persisté commence par %PDF
    $pdfContent = Storage::disk('local')->get($log->attachment_path);
    expect($pdfContent)->toStartWith('%PDF');

    // objet ne doit pas contenir de placeholders non substitués
    expect($log->objet)->not->toMatch('/\{[a-z_]+\}/');

    // catégorie correcte
    expect($log->categorie)->toBe(CategorieEmail::Document->value);
    expect($log->statut)->toBe('envoye');

    // tracking_token persisté + pixel embarqué dans corps_html (ouvertures activées)
    expect($log->tracking_token)->not->toBeNull()->toHaveLength(32);
    expect($log->corps_html)->toContain('/t/'.$log->tracking_token.'.gif');

    // envoye_par pointe vers l'utilisateur authentifié
    expect((int) $log->envoye_par)->toBe((int) $this->user->id);
});

it('enregistre un EmailLog statut erreur quand Mail::send lève une exception depuis FactureShow', function (): void {
    // Force Mail to throw on send
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP connection refused'));

    Livewire::test(FactureShow::class, ['facture' => $this->facture])
        ->call('envoyerEmail');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();
    expect($log->statut)->toBe('erreur');
    expect($log->erreur_message)->toBe('SMTP connection refused');
    expect($log->categorie)->toBe(CategorieEmail::Document->value);
    expect($log->destinataire_email)->toBe('jean@example.com');
    expect($log->attachment_path)->toBeNull();
    expect($log->corps_html)->toBeNull();
});

it('n\'enregistre pas d\'EmailLog quand le tiers n\'a pas d\'email dans FactureShow', function (): void {
    $tiersNoEmail = Tiers::factory()->pourRecettes()->create([
        'association_id' => $this->association->id,
        'email' => null,
        'nom' => 'Martin',
        'prenom' => 'Marie',
        'adresse_ligne1' => '1 rue Test',
        'code_postal' => '75000',
        'ville' => 'Paris',
    ]);

    $exercice = app(ExerciceService::class)->current();
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);

    $factureNoEmail = Facture::create([
        'association_id' => $this->association->id,
        'numero' => 'F-'.$exercice.'-0002',
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => $tiersNoEmail->id,
        'compte_bancaire_id' => $compte->id,
        'montant_total' => 100.00,
        'conditions_reglement' => 'Paiement à réception',
        'mentions_legales' => 'Association loi 1901',
        'exercice' => $exercice,
        'saisi_par' => $this->user->id,
    ]);

    FactureLigne::create([
        'facture_id' => $factureNoEmail->id,
        'type' => TypeLigneFacture::Montant,
        'libelle' => 'Cotisation',
        'montant' => 100.00,
        'ordre' => 1,
    ]);

    Livewire::test(FactureShow::class, ['facture' => $factureNoEmail])
        ->call('envoyerEmail');

    // Guard fires before try/catch — no EmailLog
    expect(EmailLog::query()->count())->toBe(0);
});

it('couvre une facture libre (sans opération liée) — operation_id null dans EmailLog', function (): void {
    // This facture has no linked operation (facture libre)
    // The EmailLog should store operation_id = null (no {operation} placeholder leak)
    Livewire::test(FactureShow::class, ['facture' => $this->facture])
        ->call('envoyerEmail');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();
    expect($log->operation_id)->toBeNull();
    expect($log->statut)->toBe('envoye');

    // objet must not contain any unresolved {xxx} placeholder
    expect($log->objet)->not->toMatch('/\{[a-z_]+\}/');
});
