<?php

/**
 * Réparation ponctuelle du 2026-10-20 — encaissement #465 de la créance #190 (Mircea NOTZ).
 *
 * Contexte : l'encaissement espèces #465 (2026-2027) avait été lettré (code AAEH) avec
 * la créance reportée en à-nouveau (ligne 1105). La réouverture de 2025-2026 a supprimé
 * cet à-nouveau sans délettrer : #465 reste lettré avec une ligne supprimée, et la
 * créance d'origine (ligne 1018) n'est plus soldée.
 *
 * PRÉREQUIS : 2025-2026 a été RE-CLÔTURÉ par l'assistant de clôture, ce qui a recréé
 * une ligne d'à-nouveau 411 pour la ligne 1018.
 *
 * Effet :
 *   1. délettre le groupe orphelin AAEH (ligne 1109, et la ligne 1105 supprimée) ;
 *   2. lettre 1109 avec la nouvelle ligne d'à-nouveau issue de 1018 ;
 *   3. resynchronise le statut de #190 (→ « en main », prête pour une remise espèces).
 *
 * Tout ou rien (transaction). S'arrête sans rien écrire si l'état n'est pas celui attendu.
 *
 * Usage (prod comme local) :
 *   php artisan tinker --execute="require 'scripts/ops/2026-10-20-relettrer-encaissement-tx190.php';"
 */

declare(strict_types=1);

use App\Enums\StatutANouveau;
use App\Models\ANouveauLigneOrigine;
use App\Models\Association;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\LettrageService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

const ASSOCIATION_ID = 1;
const USER_ID = 2;
const LIGNE_RACINE = 1018;   // 411 de la créance #190
const LIGNE_AN_SUPPRIMEE = 1105;
const LIGNE_ENCAISSEMENT = 1109; // 411 crédit de #465
const CODE_ORPHELIN = 'AAEH';

TenantContext::boot(Association::findOrFail(ASSOCIATION_ID));
Auth::loginUsingId(USER_ID);

DB::transaction(function (): void {
    $encaissement = TransactionLigne::findOrFail(LIGNE_ENCAISSEMENT);
    $anSupprimee = TransactionLigne::withTrashed()->findOrFail(LIGNE_AN_SUPPRIMEE);

    if ($encaissement->lettrage_code !== CODE_ORPHELIN || $anSupprimee->deleted_at === null) {
        throw new RuntimeException('État inattendu : la réparation a-t-elle déjà été faite ?');
    }

    $origine = ANouveauLigneOrigine::query()
        ->where('ligne_racine_id', LIGNE_RACINE)
        ->whereHas('generation', fn ($q) => $q
            ->where('exercice_cible', 2026)
            ->where('statut', StatutANouveau::Active))
        ->latest('generation_id')
        ->first();

    if ($origine === null) {
        throw new RuntimeException('Aucun à-nouveau actif pour la ligne 1018 : re-clôturer 2025-2026 d’abord.');
    }

    $nouvelleAN = TransactionLigne::findOrFail($origine->ligne_an_id);
    if ($nouvelleAN->lettrage_code !== null) {
        throw new RuntimeException("La ligne d'à-nouveau #{$nouvelleAN->id} est déjà lettrée.");
    }

    $lettrage = app(LettrageService::class);
    $lettrage->delettrer(
        CODE_ORPHELIN,
        'Réparation 2026-10-20 : à-nouveau supprimé par la réouverture de 2025-2026',
        (int) $encaissement->compte_id,
    );
    // La ligne supprimée n'est pas vue par delettrer() (SoftDeletes) : on l'aligne aussi.
    TransactionLigne::withTrashed()->whereKey(LIGNE_AN_SUPPRIMEE)->update(['lettrage_code' => null]);

    $code = $lettrage->lettrer(
        collect([$nouvelleAN, $encaissement->fresh()]),
        null,
        "Réparation 2026-10-20 : encaissement T2#465 relettré sur l'à-nouveau #{$nouvelleAN->id}",
    );

    app(EtatReglementResolver::class)->syncer(Transaction::findOrFail(190));

    echo "OK — lettrage {$code} : lignes {$nouvelleAN->id} ↔ ".LIGNE_ENCAISSEMENT
        .' ; statut #190 = '.Transaction::findOrFail(190)->statut_reglement->value.PHP_EOL;
});
