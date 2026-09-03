<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Livewire\RapprochementList;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // saisieManuelle() exige actif_recettes_depenses=true et
    // saisie_automatisee=false : ce sont déjà les valeurs par défaut de la
    // factory, donc ce compte est éligible sans état supplémentaire.
    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);
});

afterEach(fn () => TenantContext::clear());

it('upload sans IA attache le fichier au rapprochement', function (): void {
    // Pas de anthropic_api_key sur l'association : ReleveOcrService::isConfigured()
    // est faux, updatedExtraitCompte() s'arrête avant tout appel réseau.
    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->set('showCreateForm', true)
        ->set('extraitCompte', $file)
        ->assertSet('extraitAnalyse', null)
        ->assertSet('extraitBloquant', false)
        ->set('date_fin', '2025-10-31')
        ->set('solde_fin', '1200')
        ->call('create');

    $rapprochement = RapprochementBancaire::where('compte_id', $this->compte->id)->latest('id')->first();

    expect($rapprochement)->not->toBeNull()
        ->and($rapprochement->hasPieceJointe())->toBeTrue()
        ->and($rapprochement->piece_jointe_nom)->toBe('releve.pdf')
        ->and($rapprochement->piece_jointe_mime)->toBe('application/pdf');
});

it('upload avec IA pre-remplit les champs', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    // solde_ouverture volontairement absent : ce test porte sur le
    // pré-remplissage, pas sur la détection de divergence (couverte plus bas).
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'solde_ouverture' => null,
                    'solde_cloture' => 1542.30,
                    'date_cloture' => '2025-10-31',
                    'banque' => 'Banque Test',
                    'numero_compte' => null,
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->set('showCreateForm', true)
        ->set('extraitCompte', $file)
        ->assertSet('solde_fin', '1542.3')
        ->assertSet('date_fin', '2025-10-31')
        ->assertSet('extraitBloquant', false)
        ->assertSet('extraitErreur', null);
});

it('divergence solde bloque la creation', function (): void {
    // Rapprochement verrouillé antérieur : calculerSoldeOuverture() doit
    // retourner son solde_fin (1000.0), et non le solde_initial du compte.
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'solde_fin' => 1000.00,
        'statut' => StatutRapprochement::Verrouille,
        'date_fin' => '2025-09-30',
        'saisi_par' => $this->user->id,
    ]);

    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    // Le relevé lu par l'IA annonce un solde d'ouverture différent (950 au
    // lieu de 1000 calculé) : la chronologie ne colle pas.
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'solde_ouverture' => 950.00,
                    'solde_cloture' => 1200.00,
                    'date_cloture' => '2025-10-31',
                    'banque' => null,
                    'numero_compte' => null,
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');

    $avant = RapprochementBancaire::where('compte_id', $this->compte->id)->count();

    $component = Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->set('showCreateForm', true)
        ->set('extraitCompte', $file)
        ->assertSet('extraitBloquant', true);

    $component->set('date_fin', '2025-10-31')
        ->set('solde_fin', '1200')
        ->call('create')
        ->assertHasErrors('extraitCompte');

    // La création a bien été bloquée : aucun nouveau rapprochement en base.
    expect(RapprochementBancaire::where('compte_id', $this->compte->id)->count())->toBe($avant);
});

it('echec API ne bloque pas', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response('Internal Server Error', 500),
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');

    $component = Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->set('showCreateForm', true)
        ->set('extraitCompte', $file)
        ->assertSet('extraitBloquant', false);

    // Un échec d'API est un incident réseau, pas une divergence comptable :
    // il informe l'utilisateur (extraitErreur) sans jamais bloquer la saisie.
    expect($component->get('extraitErreur'))->not->toBeNull()
        ->and($component->get('extraitErreur'))->toBeString()
        ->and($component->get('extraitErreur'))->not->toBe('');
});
