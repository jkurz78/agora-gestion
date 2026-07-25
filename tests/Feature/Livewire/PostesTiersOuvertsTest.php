<?php

declare(strict_types=1);

use App\Enums\OrigineANouveau;
use App\Enums\StatutExercice;
use App\Livewire\Compta\PostesTiersOuverts;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    SystemeSeeder::seed();
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

    $this->acteurPostesTiersOuverts = User::factory()->create();
    $this->acteurPostesTiersOuverts->associations()->attach(TenantContext::currentId(), [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
});

function comptePostesTiersOuverts(string $numero, int $classe): Compte
{
    return Compte::create([
        'numero_pcg' => $numero,
        'intitule' => 'Compte '.$numero,
        'classe' => $classe,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
}

function creerCreancePostesTiersOuverts(Tiers $tiers, string $reference): Transaction
{
    $transaction = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => comptePostesTiersOuverts('706-'.str_replace('-', '', $reference), 7),
            'montant' => 100.00,
        ]],
        dateConstatation: new DateTimeImmutable('2026-08-20'),
        libelle: 'Créance écran tiers',
    );
    $transaction->update(['reference' => $reference]);

    return $transaction;
}

function creerDettePostesTiersOuverts(Tiers $tiers): Transaction
{
    return app(EcritureGenerator::class)->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => comptePostesTiersOuverts('606-ECRAN', 6),
            'montant' => 45.50,
        ]],
        dateConstatation: new DateTimeImmutable('2026-08-21'),
        libelle: 'Dette écran tiers',
    );
}

it('protege la page et affiche les postes ouverts avec les filtres et le règlement', function (): void {
    $client = Tiers::factory()->create(['nom' => 'Client écran']);
    $fournisseur = Tiers::factory()->create(['nom' => 'Fournisseur écran']);
    $t1 = creerCreancePostesTiersOuverts($client, 'REF-CLIENT-42');
    $t2 = creerDettePostesTiersOuverts($fournisseur);
    $ligne401 = $t2->lignes->first(
        fn (TransactionLigne $ligne): bool => $ligne->compte?->numero_pcg === '401'
    );

    $this->get(route('comptabilite.postes-tiers-ouverts'))->assertRedirect(route('login'));

    $this->actingAs($this->acteurPostesTiersOuverts)
        ->get(route('comptabilite.postes-tiers-ouverts'))
        ->assertOk()
        ->assertSeeLivewire(PostesTiersOuverts::class);

    Livewire::test(PostesTiersOuverts::class)
        ->assertSee('Postes tiers ouverts')
        ->assertSee($t1->numero_piece)
        ->assertSee('REF-CLIENT-42')
        ->set('filtreCompte', '401')
        ->assertDontSee('REF-CLIENT-42')
        ->set('filtreTiersId', (int) $fournisseur->id)
        ->assertSee($t2->numero_piece)
        ->set('filtreExerciceOrigine', 2025)
        ->assertSee($t2->numero_piece)
        ->set('recherche', 'dette écran')
        ->assertSee($t2->numero_piece)
        ->call('regler', (int) $ligne401?->id)
        ->assertDispatched(
            'poste-tiers-reglement:ouvrir',
            ligneId: (int) $ligne401?->id,
            exercice: 2025,
        );
});

it('affiche les informations de la transaction d origine pour un report à nouveau', function (): void {
    $client = Tiers::factory()->create(['nom' => 'Client report écran']);
    $t1 = creerCreancePostesTiersOuverts($client, 'REF-REPORT-AN');

    app(ANouveauService::class)->persister(
        app(ANouveauPreviewBuilder::class)->build(2025),
        OrigineANouveau::Cloture,
        $this->acteurPostesTiersOuverts,
    );
    session(['exercice_actif' => 2026]);

    Livewire::test(PostesTiersOuverts::class)
        ->assertSee('Report AN')
        ->assertSee($t1->numero_piece)
        ->assertSee('REF-REPORT-AN');
});
