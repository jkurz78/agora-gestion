<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\TransactionLigne;

/**
 * Premier temps de l'ordre canonique des verrous d'une pièce : ses lots 401/411.
 *
 * Un règlement tiers (PosteTiersReglementService::regler()) verrouille le lot canonique de la
 * ligne qu'il règle, PUIS la pièce source. Tout geste qui réécrit une pièce portant un poste
 * tiers doit donc prendre ses lots avant la pièce, dans le même ordre : sans quoi un règlement
 * qui tient le lot et attend la pièce s'inter-bloque avec un geste qui tient la pièce et attend
 * le lot. C'est l'ordre de TransactionService::update() depuis l'origine, repris à l'identique
 * ici pour que la garde du rattachement (GelRattachement::verrouillerPieceNonGelee()), qui
 * verrouille la pièce entière, ne l'inverse pas : update() passe par elle, et c'est elle qui
 * appelle cette classe.
 *
 * Classe sans dépendance, volontairement : le service de rattachement la reçoit sans pouvoir
 * boucler sur TransactionService, qui reçoit lui-même le service de rattachement.
 *
 * PosteTiersReglementService::verrouillerLotCanonique() ne lui est PAS fusionné. Il verrouille UN
 * lot, charge son compte et sa pièce (lectures sans verrou), rend les lignes dont le règlement se
 * sert, et lève si le lot est introuvable ; ici, tous les lots d'une pièce, rien n'est rendu, et un
 * lot introuvable n'est pas une erreur (la ligne a pu disparaître entre la lecture et le verrou :
 * il n'y a alors rien à verrouiller).
 */
final class VerrouLotsCanoniques
{
    /**
     * Verrouille les lots canoniques 401/411 de la pièce, dans l'ordre de leur identifiant : pour
     * chaque ligne de tiers de la pièce, la ligne canonique du poste (elle-même, ou son parent
     * `poste_tiers_parent_id`) et tous ses fils, supprimés logiquement compris.
     *
     * À appeler dans une transaction, après les verrous d'exercice, AVANT de verrouiller la pièce :
     * les verrous tiennent jusqu'au commit de l'appelant.
     *
     * Les lignes de tiers se lisent sous le scope tenant, comme TransactionService::update() l'a
     * toujours fait : hors contexte d'association, il n'y a aucun lot à verrouiller. Cet ordre ne
     * sert qu'à exclure, jamais à décider : la décision de la garde du rattachement n'en dépend pas.
     */
    public function verrouillerLotsDeLaPiece(int $pieceId): void
    {
        $lignesTiers = TransactionLigne::query()
            ->where('transaction_id', $pieceId)
            ->whereHas('compte', fn ($query) => $query->whereIn('numero_pcg', ['401', '411']))
            ->orderBy('id')
            ->get(['id', 'poste_tiers_parent_id']);

        $lignesCanoniquesIds = $lignesTiers
            ->map(
                static fn (TransactionLigne $ligne): int => (int) (
                    $ligne->poste_tiers_parent_id ?? $ligne->id
                )
            )
            ->unique()
            ->sort()
            ->values();

        foreach ($lignesCanoniquesIds as $ligneCanoniqueId) {
            TransactionLigne::query()
                ->withTrashed()
                ->where(function ($query) use ($ligneCanoniqueId): void {
                    $query
                        ->whereKey((int) $ligneCanoniqueId)
                        ->orWhere('poste_tiers_parent_id', (int) $ligneCanoniqueId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }
}
