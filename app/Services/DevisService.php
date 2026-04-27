<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Support\CurrentAssociation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DevisService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Create a new brouillon devis for the given tiers.
     *
     * Resolves date_emission (defaults to today), reads devis_validite_jours
     * from the current association (defaults to 30), computes date_validite,
     * and determines the exercice from the emission date via ExerciceService.
     */
    public function creer(int $tiersId, ?Carbon $date = null): Devis
    {
        $dateEmission = $date ?? Carbon::today();

        return DB::transaction(function () use ($tiersId, $dateEmission): Devis {
            $association = CurrentAssociation::get();

            $validiteJours = $association->devis_validite_jours ?? 30;
            $dateValidite = $dateEmission->copy()->addDays($validiteJours);

            $exercice = $this->exerciceService->anneeForDate($dateEmission);

            return Devis::create([
                'tiers_id' => $tiersId,
                'date_emission' => $dateEmission->toDateString(),
                'date_validite' => $dateValidite->toDateString(),
                'statut' => StatutDevis::Brouillon,
                'montant_total' => 0,
                'exercice' => $exercice,
                'numero' => null,
                'saisi_par_user_id' => auth()->id(),
            ]);
        });
    }

    /**
     * Ajoute une ligne au devis et recalcule le montant_total.
     *
     * Clés acceptées dans $data : libelle (requis), prix_unitaire (requis),
     * quantite (défaut 1), sous_categorie_id (nullable).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException si le devis est verrouillé (Accepte, Refuse, Annule)
     */
    public function ajouterLigne(Devis $devis, array $data): DevisLigne
    {
        return DB::transaction(function () use ($devis, $data): DevisLigne {
            $this->guardStatutVerrouille($devis);

            $prixUnitaire = (float) $data['prix_unitaire'];
            $quantite = isset($data['quantite']) ? (float) $data['quantite'] : 1.0;
            $montant = round($prixUnitaire * $quantite, 2);

            $maxOrdre = $devis->lignes()->max('ordre') ?? 0;

            $ligne = DevisLigne::create([
                'devis_id' => $devis->id,
                'ordre' => $maxOrdre + 1,
                'libelle' => $data['libelle'],
                'prix_unitaire' => $prixUnitaire,
                'quantite' => $quantite,
                'montant' => $montant,
                'sous_categorie_id' => $data['sous_categorie_id'] ?? null,
            ]);

            $this->recalculerMontantTotal($devis);

            return $ligne;
        });
    }

    /**
     * Modifie une ligne existante et recalcule le montant_total du devis parent.
     *
     * Seuls les champs fournis dans $data sont mis à jour.
     * Clés acceptées : libelle, prix_unitaire, quantite, sous_categorie_id.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException si le devis est verrouillé (Accepte, Refuse, Annule)
     */
    public function modifierLigne(DevisLigne $ligne, array $data): void
    {
        DB::transaction(function () use ($ligne, $data): void {
            $devis = $ligne->devis;
            $this->guardStatutVerrouille($devis);

            $updates = [];

            if (array_key_exists('libelle', $data)) {
                $updates['libelle'] = $data['libelle'];
            }

            if (array_key_exists('sous_categorie_id', $data)) {
                $updates['sous_categorie_id'] = $data['sous_categorie_id'];
            }

            if (array_key_exists('prix_unitaire', $data)) {
                $updates['prix_unitaire'] = (float) $data['prix_unitaire'];
            }

            if (array_key_exists('quantite', $data)) {
                $updates['quantite'] = (float) $data['quantite'];
            }

            // Recompute montant if either price or quantity changed
            $prixUnitaire = (float) ($updates['prix_unitaire'] ?? $ligne->prix_unitaire);
            $quantite = (float) ($updates['quantite'] ?? $ligne->quantite);
            $updates['montant'] = round($prixUnitaire * $quantite, 2);

            $ligne->update($updates);

            $this->recalculerMontantTotal($devis);
        });
    }

    /**
     * Supprime une ligne et recalcule le montant_total du devis parent.
     *
     * @throws RuntimeException si le devis est verrouillé (Accepte, Refuse, Annule)
     */
    public function supprimerLigne(DevisLigne $ligne): void
    {
        DB::transaction(function () use ($ligne): void {
            $devis = $ligne->devis;
            $this->guardStatutVerrouille($devis);

            $ligne->delete();

            $this->recalculerMontantTotal($devis);
        });
    }

    /**
     * Marque le devis comme envoyé et lui attribue un numéro séquentiel.
     *
     * Guards :
     * - statut doit être Brouillon (sinon RuntimeException)
     * - au moins une ligne avec montant > 0 doit exister (sinon RuntimeException)
     *
     * Si le devis possède déjà un numéro (re-bascule depuis un état antérieur),
     * ce numéro est conservé — pas de réattribution.
     *
     * La numérotation est sérialisée via lockForUpdate() sur les lignes de la
     * même (association_id, exercice) pour éviter les doublons en cas de
     * concurrence InnoDB.
     *
     * @throws RuntimeException
     */
    public function marquerEnvoye(Devis $devis): void
    {
        if (! $devis->statut->peutPasserEnvoye()) {
            throw new RuntimeException(
                sprintf(
                    'Impossible d\'émettre un devis au statut « %s ».',
                    $devis->statut->label()
                )
            );
        }

        $aLigneMontantPositif = $devis->lignes()
            ->where('montant', '>', 0)
            ->exists();

        if (! $aLigneMontantPositif) {
            throw new RuntimeException(
                'Au moins une ligne avec un montant est requise pour émettre le devis.'
            );
        }

        DB::transaction(function () use ($devis): void {
            // Si le devis a déjà un numéro (re-bascule après Step 6), on le conserve.
            if ($devis->numero !== null) {
                $devis->update(['statut' => StatutDevis::Envoye]);

                return;
            }

            $numero = $this->attribuerNumero((int) $devis->association_id, (int) $devis->exercice);

            $devis->update([
                'statut' => StatutDevis::Envoye,
                'numero' => $numero,
            ]);
        });
    }

    /**
     * Calcule et attribue le prochain numéro séquentiel pour (association_id, exercice).
     *
     * Utilise lockForUpdate() pour sérialiser les transactions concurrentes sur InnoDB.
     * Format : D-{exercice}-{NNN} — 3 chiffres minimum, débordement autorisé.
     *
     * Décision d'implémentation : on cherche le dernier numéro via MAX(id) dans la
     * partition (association_id, exercice, numero NOT NULL). L'ordre par id décroissant
     * est équivalent à l'ordre par numéro car les numéros sont attribués strictement
     * en séquence dans une transaction sérialisée. On parse le suffixe entier après
     * le dernier '-' pour extraire le max.
     */
    private function attribuerNumero(int $associationId, int $exercice): string
    {
        $last = Devis::withoutGlobalScopes()
            ->where('association_id', $associationId)
            ->where('exercice', $exercice)
            ->whereNotNull('numero')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first(['id', 'numero']);

        $nextSeq = $last !== null
            ? ((int) substr((string) strrchr($last->numero, '-'), 1)) + 1
            : 1;

        return sprintf('D-%d-%03d', $exercice, $nextSeq);
    }

    /**
     * Recalcule et persiste le montant_total du devis comme somme des lignes.
     */
    private function recalculerMontantTotal(Devis $devis): void
    {
        $total = $devis->lignes()->sum('montant');
        $devis->update(['montant_total' => $total]);
    }

    /**
     * Refuse la mutation si le devis est dans un statut verrouillé :
     * Accepte, Refuse ou Annule.
     *
     * @throws RuntimeException
     */
    private function guardStatutVerrouille(Devis $devis): void
    {
        if (! $devis->statut->peutEtreModifie()) {
            throw new RuntimeException(
                sprintf(
                    'Impossible de modifier un devis au statut « %s ».',
                    $devis->statut->label()
                )
            );
        }
    }
}
