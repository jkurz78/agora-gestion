<?php

declare(strict_types=1);

/**
 * Correctif OneShot compta:corriger-cheques-reportes.
 *
 * Bascule 5112 → 512X les chèques de reprise déjà encaissés avant AgoraGestion :
 * ligne 5112 en débit, NON lettrée, transaction pointée sur un rappro VERROUILLÉ.
 *
 * Test [A] : chèque de reprise éligible → ligne basculée 5112 → 512X.
 * Test [B] : --dry-run → aucune écriture, mais détection rapportée.
 * Test [C] : chèque NON pointé (rapprochement_id null) → reste en 5112.
 * Test [D] : ligne 5112 lettrée (remise réelle) → reste en 5112.
 * Test [E] : idempotence → 2ᵉ run ne touche plus rien.
 */

use App\Enums\StatutRapprochement;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $this->asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->userId = (int) $user->id;

    TenantContext::clear();
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);

    SystemeSeeder::seed(); // comptes système dont 5112

    $this->compte5112 = Compte::ofNumero('5112');

    // CompteBancaire + Compte 512X rattaché (clé stable compte_bancaire_id)
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->asso->id,
    ]);

    $this->compte512X = Compte::create([
        'association_id' => $this->asso->id,
        'numero_pcg' => '5121',
        'intitule' => 'Compte Courant',
        'classe' => 5,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'compte_bancaire_id' => $this->compteBancaire->id,
    ]);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Crée un chèque reçu avec une ligne 5112 en débit.
 *
 * @param  int|null  $rapprochementId  rappro pointé (null = non pointé)
 * @param  string|null  $lettrageCode  lettrage de la ligne 5112 (null = non lettrée)
 * @return int l'id de la ligne 5112
 */
function creerChequeAvecLigne5112(int $assoId, int $compteBancaireId, int $compte5112Id, ?int $rapprochementId, ?string $lettrageCode = null, float $montant = 345.00): int
{
    $txId = DB::table('transactions')->insertGetId([
        'association_id' => $assoId,
        'date' => now()->format('Y-m-d'),
        'type' => 'recette',
        'montant_total' => $montant,
        'equilibree' => 1,
        'libelle' => 'Chèque de reprise',
        'mode_paiement' => 'cheque',
        'type_ecriture' => 'normale',
        'compte_id' => $compteBancaireId,
        'rapprochement_id' => $rapprochementId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $txId,
        'compte_id' => $compte5112Id,
        'montant' => $montant,
        'debit' => $montant,
        'credit' => 0.00,
        'lettrage_code' => $lettrageCode,
        'deleted_at' => null,
    ]);
}

function rapproVerrouille(int $assoId, int $compteBancaireId, int $saisiPar): int
{
    return DB::table('rapprochements_bancaires')->insertGetId([
        'association_id' => $assoId,
        'compte_id' => $compteBancaireId,
        'date_fin' => now()->format('Y-m-d'),
        'solde_ouverture' => 0,
        'solde_fin' => 0,
        'statut' => StatutRapprochement::Verrouille->value,
        'type' => 'bancaire',
        'saisi_par' => $saisiPar,
        'verrouille_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('[A] chèque de reprise éligible → ligne basculée 5112 → 512X', function (): void {
    $rapproId = rapproVerrouille($this->asso->id, $this->compteBancaire->id, $this->userId);
    $ligneId = creerChequeAvecLigne5112(
        $this->asso->id, $this->compteBancaire->id, $this->compte5112->id, $rapproId,
    );

    $this->artisan('compta:corriger-cheques-reportes', ['--asso' => $this->asso->id])
        ->assertExitCode(0);

    expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))
        ->toBe((int) $this->compte512X->id);
});

test('[B] --dry-run → aucune écriture', function (): void {
    $rapproId = rapproVerrouille($this->asso->id, $this->compteBancaire->id, $this->userId);
    $ligneId = creerChequeAvecLigne5112(
        $this->asso->id, $this->compteBancaire->id, $this->compte5112->id, $rapproId,
    );

    $this->artisan('compta:corriger-cheques-reportes', ['--asso' => $this->asso->id, '--dry-run' => true])
        ->assertExitCode(0);

    // La ligne reste sur 5112 en dry-run.
    expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))
        ->toBe((int) $this->compte5112->id);
});

test('[C] chèque NON pointé (rapprochement_id null) → reste en 5112', function (): void {
    $ligneId = creerChequeAvecLigne5112(
        $this->asso->id, $this->compteBancaire->id, $this->compte5112->id, null,
    );

    $this->artisan('compta:corriger-cheques-reportes', ['--asso' => $this->asso->id])
        ->assertExitCode(0);

    expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))
        ->toBe((int) $this->compte5112->id);
});

test('[D] ligne 5112 lettrée (remise réelle) → reste en 5112', function (): void {
    $rapproId = rapproVerrouille($this->asso->id, $this->compteBancaire->id, $this->userId);
    $ligneId = creerChequeAvecLigne5112(
        $this->asso->id, $this->compteBancaire->id, $this->compte5112->id, $rapproId, 'LETTRE-ABC',
    );

    $this->artisan('compta:corriger-cheques-reportes', ['--asso' => $this->asso->id])
        ->assertExitCode(0);

    expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))
        ->toBe((int) $this->compte5112->id);
});

test('[E] idempotence → 2ᵉ run ne touche plus rien', function (): void {
    $rapproId = rapproVerrouille($this->asso->id, $this->compteBancaire->id, $this->userId);
    $ligneId = creerChequeAvecLigne5112(
        $this->asso->id, $this->compteBancaire->id, $this->compte5112->id, $rapproId,
    );

    $this->artisan('compta:corriger-cheques-reportes', ['--asso' => $this->asso->id])->assertExitCode(0);
    // 2ᵉ run : plus aucune ligne 5112 éligible.
    $this->artisan('compta:corriger-cheques-reportes', ['--asso' => $this->asso->id])
        ->expectsOutputToContain('aucun chèque de reprise à corriger')
        ->assertExitCode(0);

    expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))
        ->toBe((int) $this->compte512X->id);
});
