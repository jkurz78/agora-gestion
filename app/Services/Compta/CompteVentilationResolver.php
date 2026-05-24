<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\Compte;
use App\Models\SousCategorie;
use Illuminate\Support\Facades\Log;

/**
 * Résout une sous-catégorie vers son Compte de ventilation (classe 6 dépense, classe 7 recette).
 *
 * Helper extrait au Step 27 (rule of three — 3 callers identiques) :
 * — Step 21 : TransactionService::enrichirPartieDouble (inline dans la boucle)
 * — Step 23 : FactureService::resoudreCompteVentilationRecette
 * — Step 26 : ReglementOperationService::resoudreCompteVentilationRecette
 *
 * Mapping : SousCategorie.code_cerfa = Compte.numero_pcg (posé en migration 2026_05_20_000001).
 *
 * Gardes (retour null + Log::warning si échec) :
 *   G1 — sousCategorieId null  → retourne null silencieusement (appelant gère le log si besoin)
 *   G2 — SousCategorie inexistante ou sans code_cerfa → Log::warning + null
 *   G3 — Compte introuvable pour code_cerfa → Log::warning + null
 *   G4 — Compte trouvé mais classe ≠ classeAttendue → Log::warning + null
 *
 * Dans tous les cas de null : le caller doit skip la double écriture PD ;
 * la création legacy est préservée.
 */
final class CompteVentilationResolver
{
    /**
     * Résout une SousCategorie (identifiée par son ID) vers le Compte de ventilation correspondant.
     *
     * @param  int|null  $sousCategorieId  L'ID de la sous-catégorie (peut être null — garde G1).
     * @param  int  $classeAttendue  6 (dépense) ou 7 (recette) — vérifié sur le Compte résolu.
     * @param  string  $contextLog  Préfixe pour les messages Log::warning (ex. 'Step 27').
     * @param  array<string, mixed>  $contextLogData  Données de contexte ajoutées aux warnings.
     * @return Compte|null  null = skip la double écriture ; non-null = Compte à utiliser.
     */
    public static function resoudre(
        ?int $sousCategorieId,
        int $classeAttendue,
        string $contextLog,
        array $contextLogData = [],
    ): ?Compte {
        // G1 — sousCategorieId null
        if ($sousCategorieId === null) {
            return null;
        }

        // G2 — SousCategorie inexistante ou sans code_cerfa
        /** @var SousCategorie|null $sousCat */
        $sousCat = SousCategorie::find($sousCategorieId);

        if ($sousCat === null || $sousCat->code_cerfa === null) {
            Log::warning(
                "[PartieDouble] {$contextLog} — skip : sous-catégorie sans code_cerfa",
                array_merge(['sous_categorie_id' => $sousCategorieId], $contextLogData),
            );

            return null;
        }

        // G3 — Compte introuvable pour code_cerfa
        /** @var Compte|null $compte */
        $compte = Compte::ofNumero($sousCat->code_cerfa);

        if ($compte === null) {
            Log::warning(
                "[PartieDouble] {$contextLog} — skip : compte introuvable pour code_cerfa",
                array_merge(['code_cerfa' => $sousCat->code_cerfa], $contextLogData),
            );

            return null;
        }

        // G4 — Classe compte ≠ classeAttendue
        if ((int) $compte->classe !== $classeAttendue) {
            Log::warning(
                "[PartieDouble] {$contextLog} — skip : classe compte ≠ {$classeAttendue}",
                array_merge([
                    'numero_pcg' => $compte->numero_pcg,
                    'classe' => $compte->classe,
                    'attendue' => $classeAttendue,
                ], $contextLogData),
            );

            return null;
        }

        return $compte;
    }
}
