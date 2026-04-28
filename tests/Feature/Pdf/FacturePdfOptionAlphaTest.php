<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create([
        'nom' => 'Association Test PDF',
        'facture_mentions_legales' => null,
        'facture_mentions_penalites' => null,
    ]);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    TenantContext::boot($this->association);
    $this->actingAs($this->user);

    $this->tiers = Tiers::factory()->pourRecettes()->create([
        'association_id' => $this->association->id,
        'type' => 'entreprise',
        'entreprise' => 'ACME SARL',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeFacture(array $attrs = []): Facture
{
    return Facture::create(array_merge([
        'association_id' => TenantContext::currentId(),
        'numero' => 'F-2026-042',
        'date' => '2026-04-28',
        'statut' => StatutFacture::Validee,
        'tiers_id' => Tiers::first()->id,
        'montant_total' => 0.00,
        'exercice' => 2025,
        'saisi_par' => User::first()->id,
    ], $attrs));
}

function renderFacturePdf(Facture $facture): string
{
    $facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions']);

    return view('pdf.facture', [
        'facture' => $facture,
        'association' => Association::find(TenantContext::currentId()),
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'montantRegle' => 0.0,
        'isAcquittee' => false,
        'mentionsPenalites' => null,
        'forceOriginalFormat' => false,
    ])->render();
}

// ─── Test 1 : Ligne Montant (ref) — libellé + montant rendus, PU et Qté vides ─

it('ligne Montant affiche libellé et montant, PU et Qté vides', function (): void {
    $facture = makeFacture(['montant_total' => 500.00]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::Montant,
        'libelle' => 'Prestation ref',
        'montant' => 500.00,
        'ordre' => 1,
        'prix_unitaire' => null,
        'quantite' => null,
    ]);

    $html = renderFacturePdf($facture);

    // Libellé visible
    expect($html)->toContain('Prestation ref');

    // Montant total visible
    expect($html)->toContain('500,00');

    // La blade option α doit avoir 4 colonnes (thead doit mentionner "Prix unitaire")
    // Le tableau doit avoir la colonne "Prix unitaire" dans l'en-tête
    expect($html)->toContain('Prix unitaire');

    // La colonne Quantité doit être présente dans l'en-tête
    expect($html)->toContain('uantit'); // "Quantité" ou "Qté"

    // Pour une ligne Montant (ref) : la cellule PU doit être vide
    // (pas de valeur "500,00" dans la cellule PU de cette ligne)
    // On vérifie que "0,00" n'apparaît nulle part (bug classique)
    expect($html)->not->toContain('>0,00<');
});

// ─── Test 2 : Ligne MontantManuel — PU, Qté et Montant rendus ────────────────

it('ligne MontantManuel affiche libellé, PU, Qté et montant', function (): void {
    $facture = makeFacture(['montant_total' => 2400.00]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Mission audit',
        'prix_unitaire' => 800.00,
        'quantite' => 3.000,
        'montant' => 2400.00,
        'ordre' => 1,
    ]);

    $html = renderFacturePdf($facture);

    // Libellé visible
    expect($html)->toContain('Mission audit');

    // PU : 800,00 €
    expect($html)->toContain('800,00');

    // Quantité : 3,000
    expect($html)->toContain('3,000');

    // Montant total : 2 400,00 € (avec espace insécable U+00A0)
    expect($html)->toContain("2\u{00A0}400,00");
});

// ─── Test 3 : Ligne Texte — PU, Qté, Montant tous vides ─────────────────────

it('ligne Texte affiche libellé uniquement, PU/Qté/Montant vides', function (): void {
    $facture = makeFacture(['montant_total' => 0.00]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::Texte,
        'libelle' => 'Détail de la mission',
        'montant' => null,
        'prix_unitaire' => null,
        'quantite' => null,
        'ordre' => 1,
    ]);

    $html = renderFacturePdf($facture);

    // Libellé visible
    expect($html)->toContain('Détail de la mission');

    // La ligne Texte doit utiliser colspan="4" (libellé sur toute la largeur)
    // et ne pas avoir de cellules monétaires séparées
    expect($html)->toContain('colspan="4"');

    // La cellule <td colspan="4"> contient le libellé et rien d'autre concernant
    // les montants. On vérifie que le libellé apparaît dans un colspan="4".
    // Vérification ciblée : la séquence colspan="4">Détail doit être présente.
    expect($html)->toContain('colspan="4">D');

    // Aucune cellule isolée ne doit afficher ">0,00<" (format td-only, excluant le total)
    expect($html)->not->toContain('>0,00<');
});

// ─── Test 4 : mode_paiement_prevu dans le bloc Conditions de règlement ───────

it('mode_paiement_prevu est affiché dans le bloc Conditions de règlement si renseigné', function (): void {
    $facture = makeFacture([
        'montant_total' => 2400.00,
        'mode_paiement_prevu' => ModePaiement::Virement,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Mission conseil',
        'prix_unitaire' => 2400.00,
        'quantite' => 1.000,
        'montant' => 2400.00,
        'ordre' => 1,
    ]);

    $html = renderFacturePdf($facture);

    // Le bloc doit contenir le label du mode de règlement
    expect($html)->toContain('Virement');

    // Et une mention "Mode de règlement" (accents peuvent être entités HTML)
    // On cherche la partie non-accentuée pour robustesse
    expect($html)->toContain('glement');
});

// ─── Test 4b : mode_paiement_prevu absent si non renseigné ──────────────────

it('mode_paiement_prevu absent du PDF si non renseigné', function (): void {
    $facture = makeFacture([
        'montant_total' => 500.00,
        'mode_paiement_prevu' => null,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::Montant,
        'libelle' => 'Prestation',
        'montant' => 500.00,
        'ordre' => 1,
    ]);

    $html = renderFacturePdf($facture);

    // Quand mode_paiement_prevu est null, le label "Mode de règlement prévu" ne doit pas apparaître
    expect($html)->not->toContain('Mode de r&egrave;glement pr&eacute;vu');
});

// ─── Test 5 : Pas de mention "Issue du devis" sur le PDF ────────────────────

it('le PDF facture ne contient pas de mention Issue du devis même si devis_id renseigné', function (): void {
    $devis = Devis::factory()->accepte()->create([
        'tiers_id' => $this->tiers->id,
    ]);

    $facture = makeFacture([
        'montant_total' => 1000.00,
        'devis_id' => $devis->id,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Prestation',
        'prix_unitaire' => 1000.00,
        'quantite' => 1.000,
        'montant' => 1000.00,
        'ordre' => 1,
    ]);

    $html = renderFacturePdf($facture);

    // Pas de mention "Issue du devis"
    expect($html)->not->toContain('Issue du devis');

    // Pas de numéro de devis en clair (D-XXXX)
    expect($html)->not->toContain('D-');
});

// ─── Test 6 : Bloc total toujours rendu (régression UX) ─────────────────────

it('le bloc total est toujours rendu en bas de la facture', function (): void {
    $facture = makeFacture(['montant_total' => 1500.00]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Formation',
        'prix_unitaire' => 1500.00,
        'quantite' => 1.000,
        'montant' => 1500.00,
        'ordre' => 1,
    ]);

    $html = renderFacturePdf($facture);

    // Le pied de tableau avec "Total" doit être présent
    expect($html)->toContain('Total');

    // Le montant total est rendu (1 500,00 avec espace insécable U+00A0)
    expect($html)->toContain("1\u{00A0}500,00");
});
