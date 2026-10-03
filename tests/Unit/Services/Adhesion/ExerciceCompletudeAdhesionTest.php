<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Carbon;

/*
 * Complétude de l'exercice d'une adhésion (spec 2026-10-02, D1/D2, AC-1/3/4).
 *
 * `adhesions.exercice` est la SAISON d'adhésion : dérivée de la date de début,
 * pour tous les modes. Elle est une information, jamais une clé de dédoublonnage
 * pour une formule en durée (D2).
 */

function completudeCompteCotisation(string $numero): Compte
{
    $compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero,
        'intitule' => 'Cotisations '.$numero,
        'classe' => 7,
        'actif' => true,
    ]);
    $compte->usages()->create(['usage' => UsageComptable::Cotisation->value]);

    return $compte;
}

/** Recette de cotisation ; la ligne est posée sans observer (le test appelle le service). */
function completudeTransaction(Tiers $tiers, Compte $compte, string $date, ?string $formSlug = null, ?int $tierId = null): Transaction
{
    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'date' => $date,
        'helloasso_form_slug' => $formSlug,
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->delete();
    TransactionLigne::withoutEvents(fn (): TransactionLigne => TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'helloasso_tier_id' => $tierId,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]));

    return $tx->fresh();
}

function completudeDto(Tiers $tiers, FormuleAdhesion $formule, ?string $dateDebut = null, ?int $exercice = null): NouvelleAdhesionDTO
{
    return new NouvelleAdhesionDTO(
        tiersId: (int) $tiers->id,
        formuleId: (int) $formule->id,
        exercice: $exercice,
        dateDebut: $dateDebut !== null ? Carbon::parse($dateDebut) : null,
        montant: 0,
        notes: null,
        datePaiement: null,
        modePaiement: null,
        compteId: null,
        reference: null,
    );
}

/** La formule telle que la synchro HelloAsso la crée pour la saison : durée, dates fixes, aucune unité. */
function completudeFormuleSaisonHelloAsso(Compte $compte, string $debut = '2026-09-01', string $fin = '2027-08-31'): FormuleAdhesion
{
    return FormuleAdhesion::factory()->helloasso('cotisation-saison', 7)->create([
        'compte_id' => $compte->id,
        'nom' => 'Adhésion saison',
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => null,
        'helloasso_start_date' => $debut,
        'helloasso_end_date' => $fin,
    ]);
}

beforeEach(function (): void {
    $this->compte = completudeCompteCotisation('756C1');
    $this->tiers = Tiers::factory()->create();
    $this->user = User::factory()->create();
    $this->service = app(AdhesionService::class);
});

// ─── AC-3 : chaque mode produit un exercice, par la transaction ──────────────

it('AC-3 · transaction, mode exercice : l\'exercice est celui de la date du règlement', function (): void {
    FormuleAdhesion::factory()->create(['compte_id' => $this->compte->id, 'mode' => 'exercice']);
    $tx = completudeTransaction($this->tiers, $this->compte, '2026-10-02');

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion->exercice)->toBe(2026);
});

it('AC-3 · transaction, mode durée en mois : l\'exercice est dérivé de la date de début', function (): void {
    FormuleAdhesion::factory()->modeDuree(3)->create(['compte_id' => $this->compte->id]);
    // 10 mars 2027 : exercice 2026-2027 (et non l'année civile 2027).
    $tx = completudeTransaction($this->tiers, $this->compte, '2027-03-10');

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_debut->toDateString())->toBe('2027-03-10')
        ->and($adhesion->date_fin->toDateString())->toBe('2027-06-09');
});

it('AC-3 · transaction, mode durée en jours : l\'exercice est dérivé de la date de début', function (): void {
    FormuleAdhesion::factory()->create([
        'compte_id' => $this->compte->id,
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => 30,
    ]);
    $tx = completudeTransaction($this->tiers, $this->compte, '2026-12-20');

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_fin->toDateString())->toBe('2027-01-18');
});

it('AC-3 · transaction, mode dates HelloAsso : l\'exercice vient du début de la saison, pas du règlement', function (): void {
    completudeFormuleSaisonHelloAsso($this->compte);
    // Règlement du 25/08/2026 pour la saison 2026-2027 : l'écriture tombe sur
    // l'exercice comptable 2025, l'adhésion sur la saison 2026.
    $tx = completudeTransaction($this->tiers, $this->compte, '2026-08-25', 'cotisation-saison', 7);

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_debut->toDateString())->toBe('2026-09-01')
        ->and($adhesion->date_fin->toDateString())->toBe('2027-08-31');
});

it('AC-3 · transaction, mode illimité : l\'exercice est dérivé de la date de début', function (): void {
    FormuleAdhesion::factory()->modeIllimite()->create(['compte_id' => $this->compte->id]);
    $tx = completudeTransaction($this->tiers, $this->compte, '2026-11-02');

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_fin)->toBeNull();
});

it('D1 · l\'exercice respecte le mois de début d\'exercice de l\'association', function (): void {
    $association = TenantContext::current();
    $association->update(['exercice_mois_debut' => 1]);
    TenantContext::boot($association->fresh());

    FormuleAdhesion::factory()->modeDuree(3)->create(['compte_id' => $this->compte->id]);
    // Exercice calendaire : mars 2026 est dans l'exercice 2026 (il serait 2025 avec septembre).
    $tx = completudeTransaction($this->tiers, $this->compte, '2026-03-15');

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion->exercice)->toBe(2026);
});

// ─── AC-3 / AC-1 : chaque mode produit un exercice, par le wizard ────────────

it('AC-1 · wizard sur la formule HelloAsso de la saison : exercice 2026, dates imposées (cas Moniotte)', function (): void {
    $formule = completudeFormuleSaisonHelloAsso($this->compte);

    $adhesion = $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule), $this->user);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_debut->toDateString())->toBe('2026-09-01')
        ->and($adhesion->date_fin->toDateString())->toBe('2027-08-31');
});

it('AC-3 · wizard, mode durée en mois : l\'exercice est dérivé de la date de début', function (): void {
    $formule = FormuleAdhesion::factory()->modeDuree(3)->create(['compte_id' => $this->compte->id]);

    $adhesion = $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule, '2027-02-10'), $this->user);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_fin->toDateString())->toBe('2027-05-09');
});

it('AC-3 · wizard, mode durée en jours : l\'exercice est dérivé de la date de début', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'compte_id' => $this->compte->id,
        'mode' => 'duree',
        'duree_mois' => null,
        'duree_jours' => 45,
    ]);

    $adhesion = $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule, '2027-02-10'), $this->user);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_fin->toDateString())->toBe('2027-03-26');
});

it('AC-3 · wizard, mode illimité : l\'exercice est dérivé de la date de début', function (): void {
    $formule = FormuleAdhesion::factory()->modeIllimite()->create(['compte_id' => $this->compte->id]);

    $adhesion = $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule, '2027-02-10'), $this->user);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_fin)->toBeNull();
});

it('AC-3 · wizard, mode exercice : l\'exercice demandé est conservé', function (): void {
    $formule = FormuleAdhesion::factory()->create(['compte_id' => $this->compte->id, 'mode' => 'exercice']);

    $adhesion = $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule, null, 2026), $this->user);

    expect($adhesion->exercice)->toBe(2026)
        ->and($adhesion->date_debut->toDateString())->toBe('2026-09-01');
});

// ─── AC-4 : l'exercice est une information, pas une clé (D2) ─────────────────

it('AC-4 · deux règlements successifs sur une même saison, formule en durée, restent deux adhésions', function (): void {
    FormuleAdhesion::factory()->modeDuree(3)->create(['compte_id' => $this->compte->id]);

    // Formule trimestrielle renouvelée : même tiers, même exercice 2026.
    $tx1 = completudeTransaction($this->tiers, $this->compte, '2026-09-15');
    $tx2 = completudeTransaction($this->tiers, $this->compte, '2026-12-15');

    $a1 = $this->service->creerDepuisTransaction($tx1);
    $a2 = $this->service->creerDepuisTransaction($tx2);

    expect(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(2)
        ->and($a2->id)->not->toBe($a1->id)
        ->and($a1->exercice)->toBe(2026)
        ->and($a2->exercice)->toBe(2026)
        ->and($a1->date_fin->toDateString())->toBe('2026-12-14')
        ->and($a2->date_debut->toDateString())->toBe('2026-12-15');
});

it('AC-4 · deux saisies successives sur une même saison, formule en durée, ne sont pas refusées par la garde', function (): void {
    $formule = FormuleAdhesion::factory()->modeDuree(3)->create(['compte_id' => $this->compte->id]);

    $a1 = $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule, '2026-09-15'), $this->user);
    $a2 = $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule, '2026-12-15'), $this->user);

    expect(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(2)
        ->and($a1->exercice)->toBe(2026)
        ->and($a2->exercice)->toBe(2026);

    // La garde protège toujours ce qu'elle doit : une période qui se chevauche reste refusée.
    expect(fn () => $this->service->creerDepuisWizard(completudeDto($this->tiers, $formule, '2026-12-01'), $this->user))
        ->toThrow(DomainException::class, 'chevauche');
});

it('D2 · une adhésion en durée n\'est pas fusionnée avec un règlement « par exercice » de la même saison', function (): void {
    // Avant le correctif, l'adhésion en durée avait un exercice nul et ne participait
    // jamais à la clé (tiers, exercice). Renseigner l'exercice ne doit pas l'y faire
    // entrer : deux adhésions légitimes, deux lignes.
    $compteAnnuel = completudeCompteCotisation('756C2');
    $formuleDuree = FormuleAdhesion::factory()->modeDuree(12)->create(['compte_id' => $this->compte->id]);
    FormuleAdhesion::factory()->create(['compte_id' => $compteAnnuel->id, 'mode' => 'exercice']);

    $this->service->creerDepuisWizard(completudeDto($this->tiers, $formuleDuree, '2026-10-01'), $this->user);

    // Chemin transaction (observer, « marquer reçu », synchro) : pas de fusion.
    $tx = completudeTransaction($this->tiers, $compteAnnuel, '2026-11-05');
    $parTransaction = $this->service->creerDepuisTransaction($tx);

    expect($parTransaction->mode)->toBe('exercice')
        ->and((int) $parTransaction->transaction_id)->toBe((int) $tx->id)
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(2);
});

it('D2 · une adhésion en durée ne fait pas refuser une saisie « par exercice » de la même saison', function (): void {
    $compteAnnuel = completudeCompteCotisation('756C2');
    $formuleDuree = FormuleAdhesion::factory()->modeDuree(12)->create(['compte_id' => $this->compte->id]);
    $formuleAnnuelle = FormuleAdhesion::factory()->create(['compte_id' => $compteAnnuel->id, 'mode' => 'exercice']);

    $this->service->creerDepuisWizard(completudeDto($this->tiers, $formuleDuree, '2026-10-01'), $this->user);
    // Chemin wizard : la garde « exercice » ne voit pas l'adhésion en durée.
    $this->service->creerDepuisWizard(completudeDto($this->tiers, $formuleAnnuelle, null, 2026), $this->user);

    expect(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(2);
});

it('D2 · la clé « exercice » retrouve une adhésion offerte sans dates, que la clé des dates ne verrait pas', function (): void {
    FormuleAdhesion::factory()->create(['compte_id' => $this->compte->id, 'mode' => 'exercice']);
    $offerte = Adhesion::factory()->create([
        'tiers_id' => $this->tiers->id,
        'exercice' => 2026,
        'date_debut' => null,
        'date_fin' => null,
    ]);

    $adhesion = $this->service->creerDepuisTransaction(completudeTransaction($this->tiers, $this->compte, '2026-10-02'));

    expect($adhesion->id)->toBe($offerte->id)
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(1);
});

it('D2 · une adhésion « par exercice » bloque toujours un second règlement sur le même exercice', function (): void {
    FormuleAdhesion::factory()->create(['compte_id' => $this->compte->id, 'mode' => 'exercice']);

    $a1 = $this->service->creerDepuisTransaction(completudeTransaction($this->tiers, $this->compte, '2026-10-02'));
    $a2 = $this->service->creerDepuisTransaction(completudeTransaction($this->tiers, $this->compte, '2026-11-20'));

    expect($a2->id)->toBe($a1->id)
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(1);
});

it('D2 · la déduplication d\'une formule en durée reste fondée sur les dates (même période = même adhésion)', function (): void {
    $formule = completudeFormuleSaisonHelloAsso($this->compte);

    $a1 = $this->service->creerDepuisTransaction(completudeTransaction($this->tiers, $this->compte, '2026-09-05', 'cotisation-saison', 7));
    $a2 = $this->service->creerDepuisTransaction(completudeTransaction($this->tiers, $this->compte, '2026-09-20', 'cotisation-saison', 7));

    expect($a2->id)->toBe($a1->id)
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(1)
        ->and((int) $a1->formule_adhesion_id)->toBe((int) $formule->id);
});
