<?php

declare(strict_types=1);

use App\Enums\OrigineANouveau;
use App\Enums\SensVentilation;
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

function creerCreancePostesTiersOuverts(
    Tiers $tiers,
    string $reference,
    string $dateConstatation = '2026-08-20',
): Transaction {
    $transaction = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['sens' => SensVentilation::Credit,
            'compte' => comptePostesTiersOuverts('706-'.str_replace('-', '', $reference), 7),
            'montant' => 100.00,
        ]],
        dateConstatation: new DateTimeImmutable($dateConstatation),
        libelle: 'Créance écran tiers',
    );
    $transaction->update(['reference' => $reference]);

    return $transaction;
}

function creerDettePostesTiersOuverts(
    Tiers $tiers,
    string $suffixeCompte = 'ECRAN',
    string $libelle = 'Dette écran tiers',
): Transaction {
    return app(EcritureGenerator::class)->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [[
            'compte' => comptePostesTiersOuverts('606-'.$suffixeCompte, 6),
            'montant' => 45.50,
        ]],
        dateConstatation: new DateTimeImmutable('2026-08-21'),
        libelle: $libelle,
    );
}

it('protege la page et affiche les postes ouverts avec les filtres et le règlement', function (): void {
    $client = Tiers::factory()->create(['nom' => 'Client écran']);
    $fournisseur = Tiers::factory()->create(['nom' => 'Fournisseur écran']);
    $autreFournisseur = Tiers::factory()->create(['nom' => 'Autre fournisseur écran']);
    $t1 = creerCreancePostesTiersOuverts($client, 'REF-CLIENT-42');
    $t2 = creerDettePostesTiersOuverts($fournisseur);
    $t3 = creerDettePostesTiersOuverts(
        $autreFournisseur,
        'AUTRE-TIERS',
        'Dette exclue par recherche',
    );
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
        ->assertSee($t2->numero_piece)
        ->assertSee($t3->numero_piece)
        ->set('filtreTiersId', (int) $fournisseur->id)
        ->assertSee($t2->numero_piece)
        ->assertDontSee($t3->numero_piece)
        ->set('filtreTiersId', null)
        ->set('recherche', 'dette écran')
        ->assertSee($t2->numero_piece)
        ->assertDontSee($t3->numero_piece)
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
    $t2 = creerCreancePostesTiersOuverts(
        $client,
        'REF-ORIGINE-2026',
        '2027-08-20',
    );
    session(['exercice_actif' => 2026]);

    Livewire::test(PostesTiersOuverts::class)
        ->assertSee('Report AN')
        ->assertSee($t1->numero_piece)
        ->assertSee('REF-REPORT-AN')
        ->assertSee($t2->numero_piece)
        ->assertSee('REF-ORIGINE-2026')
        ->set('filtreExerciceOrigine', 2025)
        ->assertSee('REF-REPORT-AN')
        ->assertDontSee('REF-ORIGINE-2026');
});
