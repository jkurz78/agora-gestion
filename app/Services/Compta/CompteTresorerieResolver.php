<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Models\Compte;
use App\Models\CompteBancaire;
use Illuminate\Support\Facades\Log;

/**
 * Résout le Compte de trésorerie (512X / 5112 / 530) à partir d'un CompteBancaire
 * et d'un mode de paiement.
 *
 * Helper extrait à partir du 3ème caller (Step 24, rule-of-three) :
 * — TransactionService::enrichirPartieDouble
 * — FactureService::marquerReglementRecu
 * — RemiseBancaireService::comptabiliser
 *
 * Pattern de résolution :
 * - Virement / CB / Prélèvement (+ Chèque côté dépense) : cherche le Compte 512X via
 *   compte_bancaire_id (clé stable — fix code review 2026-05-30, l'IBAN est nullable et
 *   non unique). Retourne null si introuvable (caller doit skip la double écriture).
 * - Chèque reçu (recette) / Espèces : retourne le placeholder 5112 système.
 *   EcritureGenerator mappe automatiquement vers 5112 (chèques reçus) ou 530 (espèces)
 *   via resoudreComptePortage() — le placeholder n'est jamais écrit en DB.
 *
 * Asymétrie chèque (documentée en Step 21 §Décision 8) :
 * - Côté dépense : Chèque émis → 512X explicite requis (pas de 5112 miroir).
 * - Côté recette : Chèque reçu → placeholder 5112 acceptable (EcritureGenerator résout seul).
 * Pour encaissement créance, on est côté recette → Cheque → placeholder OK.
 */
final class CompteTresorerieResolver
{
    /**
     * Résout le compte de trésorerie depuis un CompteBancaire (identifié par son ID sur la Transaction).
     *
     * @param  int|null  $compteBancaireId  La colonne compte_id de la Transaction (FK vers comptes_bancaires).
     * @param  ModePaiement  $mode  Le mode de paiement.
     * @param  string  $contextLog  Préfixe pour les messages Log::warning (ex. 'FactureService').
     * @param  Sens  $sens  Sens::Depense pour une dépense (chèque → 512X requis) ; Sens::Recette pour une recette.
     * @return Compte|null null = skip la double écriture ; non-null = utiliser ce compte.
     */
    public static function resoudre(
        ?int $compteBancaireId,
        ModePaiement $mode,
        string $contextLog = 'CompteTresorerieResolver',
        Sens $sens = Sens::Recette,
    ): ?Compte {
        // Modes nécessitant un 512X explicite selon le sens
        $modesNecessitant512X = $sens === Sens::Depense
            ? [ModePaiement::Cheque, ModePaiement::Virement, ModePaiement::Cb, ModePaiement::Prelevement]
            : [ModePaiement::Virement, ModePaiement::Cb, ModePaiement::Prelevement];

        if ($compteBancaireId !== null) {
            // Résolution par compte_bancaire_id (clé stable — l'IBAN est nullable et non unique).
            $compte512X = Compte::where('compte_bancaire_id', $compteBancaireId)
                ->bancaires()
                ->first();

            if ($compte512X !== null) {
                return $compte512X;
            }

            // Aucun Compte 512X trouvé pour ce CompteBancaire
            if (in_array($mode, $modesNecessitant512X, strict: true)) {
                Log::warning("[PartieDouble] {$contextLog} — skip : compte 512X introuvable pour CompteBancaire #{$compteBancaireId}", [
                    'compte_bancaire_id' => $compteBancaireId,
                    'mode_paiement' => $mode->value,
                ]);

                return null;
            }

            // Chèque reçu (recette) ou Espèces avec compte_id → placeholder 5112
            // ofNumero() (nullable) pour robustesse : si pas de schéma PD → skip
            return Compte::ofNumero('5112');
        }

        // compte_id null
        if (in_array($mode, $modesNecessitant512X, strict: true)) {
            Log::warning("[PartieDouble] {$contextLog} — skip : compte_id null pour mode nécessitant 512X", [
                'mode_paiement' => $mode->value,
            ]);

            return null;
        }

        // Chèque reçu (recette) ou Espèces sans compte_id → placeholder 5112
        // ofNumero() (nullable) pour robustesse : si pas de schéma PD → skip
        return Compte::ofNumero('5112');
    }
}
