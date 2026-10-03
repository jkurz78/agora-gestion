<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use App\Services\HelloAssoSyncResult;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Spec 2026-10-02 — AC-2 et AC-5.
 *
 * La formule de la saison est auto-créée par la synchro en mode « durée » avec
 * des dates HelloAsso et AUCUNE unité de durée. La synchro et la saisie manuelle
 * (wizard) doivent produire la même adhésion, exercice compris.
 */

function syncExerciceReprise(): object
{
    return require database_path('migrations/2026_10_03_100001_reprendre_exercice_des_adhesions.php');
}

/**
 * La synchro AVALE les erreurs par commande (`ordersSkipped`, `errors`) : un test qui
 * ne regarde que le nombre d'adhésions passe à vide quand la commande échoue sur l'index
 * unique au lieu d'être dédupliquée. On exige donc une synchro sans erreur ni commande écartée.
 */
function syncExerciceSansErreur(HelloAssoSyncResult $resultat): void
{
    expect($resultat->hasErrors())->toBeFalse($resultat->hasErrors() ? implode(' | ', $resultat->errors) : '')
        ->and($resultat->ordersSkipped)->toBe(0);
}

/** @return array<string, mixed> */
function syncExerciceCommande(int $id, string $date, string $prenom, string $nom): array
{
    return [
        'id' => $id,
        'date' => $date,
        'formSlug' => 'cotisation-saison',
        'formType' => 'Membership',
        'payments' => [['id' => 7000 + $id, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => $prenom, 'lastName' => $nom],
        'items' => [['id' => 1000 + $id, 'amount' => 3000, 'type' => 'Membership', 'tierId' => 1, 'user' => ['firstName' => $prenom, 'lastName' => $nom]]],
    ];
}

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], ['nom' => 'Asso test', 'slug' => 'test-asso']);
    TenantContext::boot($association);

    $compteBancaire = CompteBancaire::factory()->create();
    $this->compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '756SE',
        'intitule' => 'Cotisations',
        'classe' => 7,
        'actif' => true,
    ]);
    $this->compte->usages()->create(['usage' => UsageComptable::Cotisation->value]);

    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => $association->id,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compteBancaire->id,
        'compte_versement_id' => $compteBancaire->id,
    ]);

    $this->kohl = Tiers::factory()->create(['helloasso_nom' => 'KOHL', 'helloasso_prenom' => 'Anne', 'est_helloasso' => true]);

    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-saison',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation saison',
        'compte_id' => $this->compte->id,
    ]);

    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-saison/public' => Http::response([
            'formSlug' => 'cotisation-saison',
            'validityType' => 'Custom',
            'startDate' => '2026-09-01',
            'endDate' => '2027-08-31',
            'tiers' => [['id' => 1, 'label' => 'Adulte', 'price' => 3000, 'isEligibleTaxReceipt' => false]],
        ]),
    ]);
});

it('AC-2 · la synchro et la saisie manuelle produisent la même adhésion sur la formule de la saison', function (): void {
    syncExerciceSansErreur((new HelloAssoSyncService($this->parametres))
        ->synchroniser([syncExerciceCommande(9101, '2026-09-05T10:00:00Z', 'Anne', 'KOHL')], 2026));

    $formule = FormuleAdhesion::firstOrFail();
    // La forme décrite par la spec : durée, dates fixes, aucune unité.
    expect($formule->mode)->toBe('duree')
        ->and($formule->duree_mois)->toBeNull()
        ->and($formule->duree_jours)->toBeNull();

    $parSynchro = Adhesion::where('tiers_id', $this->kohl->id)->firstOrFail();

    $moniotte = Tiers::factory()->create(['nom' => 'MONIOTTE']);
    $parWizard = app(AdhesionService::class)->creerDepuisWizard(
        new NouvelleAdhesionDTO(
            tiersId: (int) $moniotte->id,
            formuleId: (int) $formule->id,
            exercice: null,
            dateDebut: null,
            montant: 0,
            notes: null,
            datePaiement: null,
            modePaiement: null,
            compteId: null,
            reference: null,
        ),
        User::factory()->create(),
    );

    expect($parSynchro->exercice)->toBe(2026)
        ->and($parWizard->exercice)->toBe($parSynchro->exercice)
        ->and($parWizard->date_debut->toDateString())->toBe($parSynchro->date_debut->toDateString())
        ->and($parWizard->date_fin->toDateString())->toBe($parSynchro->date_fin->toDateString())
        ->and($parWizard->mode)->toBe($parSynchro->mode);
});

it('AC-5 · une resynchro d\'une commande déjà importée ne crée pas de doublon d\'adhésion', function (): void {
    $service = new HelloAssoSyncService($this->parametres);
    $commande = syncExerciceCommande(9101, '2026-09-05T10:00:00Z', 'Anne', 'KOHL');

    syncExerciceSansErreur($service->synchroniser([$commande], 2026));
    $premiere = Adhesion::where('tiers_id', $this->kohl->id)->firstOrFail();

    syncExerciceSansErreur($service->synchroniser([$commande], 2026));

    expect(Adhesion::where('tiers_id', $this->kohl->id)->count())->toBe(1)
        ->and(Adhesion::where('tiers_id', $this->kohl->id)->first()->id)->toBe($premiere->id);
});

it('AC-5 · après la reprise d\'une adhésion historique à exercice nul, la resynchro ne la double pas', function (): void {
    $service = new HelloAssoSyncService($this->parametres);
    $commande = syncExerciceCommande(9101, '2026-09-05T10:00:00Z', 'Anne', 'KOHL');

    syncExerciceSansErreur($service->synchroniser([$commande], 2026));
    $historique = Adhesion::where('tiers_id', $this->kohl->id)->firstOrFail();

    // État de production avant le correctif : exercice jamais renseigné.
    DB::table('adhesions')->where('id', $historique->id)->update(['exercice' => null]);

    $bilan = syncExerciceReprise()->reprendre();
    expect($bilan['reprises'])->toBe(1);

    syncExerciceSansErreur($service->synchroniser([$commande], 2026));
    // Une seconde commande du même tiers retrouve l'adhésion reprise par ses dates.
    syncExerciceSansErreur($service->synchroniser([syncExerciceCommande(9102, '2026-09-20T10:00:00Z', 'Anne', 'KOHL')], 2026));

    $adhesions = Adhesion::where('tiers_id', $this->kohl->id)->get();
    expect($adhesions)->toHaveCount(1)
        ->and($adhesions->first()->id)->toBe($historique->id)
        ->and($adhesions->first()->exercice)->toBe(2026);
});

it('AC-5 · une seconde commande du même tiers sur la même saison reste dédupliquée par les dates', function (): void {
    $service = new HelloAssoSyncService($this->parametres);

    syncExerciceSansErreur($service->synchroniser([syncExerciceCommande(9101, '2026-09-05T10:00:00Z', 'Anne', 'KOHL')], 2026));
    syncExerciceSansErreur($service->synchroniser([syncExerciceCommande(9102, '2026-09-20T10:00:00Z', 'Anne', 'KOHL')], 2026));

    expect(Adhesion::where('tiers_id', $this->kohl->id)->count())->toBe(1);
});
