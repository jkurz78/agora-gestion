<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Support\CurrentAssociation;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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
     * Si le devis est au statut Envoye, le repasse en Brouillon en conservant
     * son numéro (rebascule). Le statut résultant est Brouillon dans les deux cas.
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
            $locked = Devis::withoutGlobalScopes()
                ->whereKey($devis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardStatutVerrouille($locked);

            $prixUnitaire = (float) $data['prix_unitaire'];
            $quantite = isset($data['quantite']) ? (float) $data['quantite'] : 1.0;
            $montant = round($prixUnitaire * $quantite, 2);

            $maxOrdre = $locked->lignes()->max('ordre') ?? 0;

            $ligne = DevisLigne::create([
                'devis_id' => $locked->id,
                'ordre' => $maxOrdre + 1,
                'libelle' => $data['libelle'],
                'prix_unitaire' => $prixUnitaire,
                'quantite' => $quantite,
                'montant' => $montant,
                'sous_categorie_id' => $data['sous_categorie_id'] ?? null,
            ]);

            $this->rebasculerSiEnvoye($locked);
            $this->recalculerMontantTotal($locked);

            $devis->setRawAttributes($locked->fresh()->getAttributes(), true);

            return $ligne;
        });
    }

    /**
     * Modifie une ligne existante et recalcule le montant_total du devis parent.
     *
     * Si le devis est au statut Envoye, le repasse en Brouillon en conservant
     * son numéro (rebascule). Le statut résultant est Brouillon dans les deux cas.
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

            $locked = Devis::withoutGlobalScopes()
                ->whereKey($devis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardStatutVerrouille($locked);

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

            $this->rebasculerSiEnvoye($locked);
            $this->recalculerMontantTotal($locked);

            $devis->setRawAttributes($locked->fresh()->getAttributes(), true);
        });
    }

    /**
     * Supprime une ligne et recalcule le montant_total du devis parent.
     *
     * Si le devis est au statut Envoye, le repasse en Brouillon en conservant
     * son numéro (rebascule). Le statut résultant est Brouillon dans les deux cas.
     *
     * @throws RuntimeException si le devis est verrouillé (Accepte, Refuse, Annule)
     */
    public function supprimerLigne(DevisLigne $ligne): void
    {
        DB::transaction(function () use ($ligne): void {
            $devis = $ligne->devis;

            $locked = Devis::withoutGlobalScopes()
                ->whereKey($devis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardStatutVerrouille($locked);

            $ligne->delete();

            $this->rebasculerSiEnvoye($locked);
            $this->recalculerMontantTotal($locked);

            $devis->setRawAttributes($locked->fresh()->getAttributes(), true);
        });
    }

    /**
     * Marque le devis comme envoyé et lui attribue un numéro séquentiel.
     *
     * Guards (évalués à l'intérieur de la transaction avec lockForUpdate) :
     * - statut doit être Brouillon (sinon RuntimeException)
     * - au moins une ligne avec montant > 0 doit exister (sinon RuntimeException)
     *
     * Si le devis possède déjà un numéro (re-bascule depuis un état antérieur),
     * ce numéro est conservé — pas de réattribution.
     *
     * Les deux guards sont évalués après avoir verrouillé la ligne avec
     * lockForUpdate() afin d'éliminer la fenêtre de concurrence entre la
     * vérification et la mise à jour (TOCTOU).
     *
     * En cas de violation de contrainte unique sur le numéro (course au premier
     * numéro d'un exercice vierge), la transaction est rejouée une fois — le
     * second passage verra le numéro existant et sérialisera correctement.
     *
     * @throws RuntimeException
     */
    public function marquerEnvoye(Devis $devis): void
    {
        try {
            $this->marquerEnvoyeTransaction($devis);
        } catch (QueryException $e) {
            // Stratégie de retry pour la fenêtre "premier numéro d'un exercice vierge" :
            // Deux transactions concurrentes peuvent simultanément trouver un résultat
            // NULL dans attribuerNumero() (aucun numéro existant) et tenter d'écrire
            // D-{exo}-001. La première commit ; la seconde lève une QueryException sur
            // la contrainte unique (association_id, exercice, numero). On rejoue une
            // fois — le second passage verra le numéro existant et obtiendra D-{exo}-002.
            // Un troisième conflit simultané est théoriquement impossible en pratique.
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            $this->marquerEnvoyeTransaction($devis);
        }
    }

    /**
     * Corps transactionnel de marquerEnvoye() — extrait pour permettre le retry.
     */
    private function marquerEnvoyeTransaction(Devis $devis): void
    {
        DB::transaction(function () use ($devis): void {
            // Verrouille la ligne du devis pour toute la durée de la transaction.
            // Cela élimine la fenêtre TOCTOU entre les guards et l'UPDATE.
            $locked = Devis::withoutGlobalScopes()
                ->whereKey($devis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Guard statut — évalué sur la ligne fraîchement verrouillée.
            if (! $locked->statut->peutPasserEnvoye()) {
                throw new RuntimeException(
                    sprintf(
                        'Impossible d\'émettre un devis au statut « %s ».',
                        $locked->statut->label()
                    )
                );
            }

            // Guard lignes — rechargé dans le contexte de la transaction verrouillée.
            $lignes = $locked->lignes()->get();
            $hasMontant = $lignes->contains(fn (DevisLigne $l) => (float) $l->montant > 0.0);

            if (! $hasMontant) {
                throw new RuntimeException(
                    'Au moins une ligne avec un montant est requise pour émettre le devis.'
                );
            }

            // Si le devis a déjà un numéro (re-bascule après Step 6), on le conserve.
            if ($locked->numero !== null) {
                $locked->update(['statut' => StatutDevis::Envoye]);
                $devis->setRawAttributes($locked->fresh()->getAttributes(), true);

                return;
            }

            $numero = $this->attribuerNumero((int) $locked->association_id, (int) $locked->exercice);

            $locked->update([
                'statut' => StatutDevis::Envoye,
                'numero' => $numero,
            ]);

            // Synchronise l'instance originale pour que l'appelant voie le nouvel état.
            $devis->setRawAttributes($locked->fresh()->getAttributes(), true);
        });
    }

    /**
     * Détermine si une QueryException est une violation de contrainte unique.
     * MySQL : code 23000 (SQLSTATE) / errno 1062.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'Duplicate entry');
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
     * Marque le devis comme accepté.
     *
     * Guard (évalué sur la ligne verrouillée) :
     * - statut doit être Envoye, sinon RuntimeException
     *
     * Trace : accepte_par_user_id + accepte_le
     *
     * @throws RuntimeException
     */
    public function marquerAccepte(Devis $devis): void
    {
        $this->muterAvecLock($devis, function (Devis $locked): void {
            if ($locked->statut !== StatutDevis::Envoye) {
                throw new RuntimeException('Seul un devis envoyé peut être marqué accepté.');
            }

            $locked->statut = StatutDevis::Accepte;
            $locked->accepte_par_user_id = auth()->id();
            $locked->accepte_le = now();
        });
    }

    /**
     * Marque le devis comme refusé.
     *
     * Guard (évalué sur la ligne verrouillée) :
     * - statut doit être Envoye, sinon RuntimeException
     *
     * Trace : refuse_par_user_id + refuse_le
     *
     * @throws RuntimeException
     */
    public function marquerRefuse(Devis $devis): void
    {
        $this->muterAvecLock($devis, function (Devis $locked): void {
            if ($locked->statut !== StatutDevis::Envoye) {
                throw new RuntimeException('Seul un devis envoyé peut être marqué refusé.');
            }

            $locked->statut = StatutDevis::Refuse;
            $locked->refuse_par_user_id = auth()->id();
            $locked->refuse_le = now();
        });
    }

    /**
     * Annule le devis depuis tout statut sauf Annule.
     *
     * Guard (évalué sur la ligne verrouillée) :
     * - statut doit passer peutEtreAnnule(), sinon RuntimeException
     *
     * Trace : annule_par_user_id + annule_le
     *
     * @throws RuntimeException
     */
    public function annuler(Devis $devis): void
    {
        $this->muterAvecLock($devis, function (Devis $locked): void {
            if (! $locked->statut->peutEtreAnnule()) {
                throw new RuntimeException('Le devis est déjà annulé.');
            }

            $locked->statut = StatutDevis::Annule;
            $locked->annule_par_user_id = auth()->id();
            $locked->annule_le = now();
        });
    }

    /**
     * Duplique un devis depuis tout statut et retourne un nouveau Devis au statut Brouillon.
     *
     * Comportement :
     * - Tout statut est accepté (peutEtreDuplique() est toujours true)
     * - Nouveau devis : statut Brouillon, pas de numéro, date_emission = today(),
     *   date_validite = today() + association.devis_validite_jours, exercice recalculé,
     *   tiers_id et libelle copiés depuis la source, association_id hérité de TenantModel,
     *   saisi_par_user_id = auth()->id(), aucune trace accepte/refuse/annule
     * - Lignes recopiées à l'identique (libelle, prix_unitaire, quantite, montant,
     *   sous_categorie_id, ordre)
     * - montant_total = somme des montants des lignes copiées
     * - Aucun lien retour vers le devis source (pas de parent_id)
     */
    public function dupliquer(Devis $source): Devis
    {
        return DB::transaction(function () use ($source): Devis {
            $association = CurrentAssociation::get();

            $dateEmission = Carbon::today();
            $validiteJours = $association->devis_validite_jours ?? 30;
            $dateValidite = $dateEmission->copy()->addDays($validiteJours);
            $exercice = $this->exerciceService->anneeForDate($dateEmission);

            $lignesSource = $source->lignes()->orderBy('ordre')->get();

            $montantTotal = $lignesSource->sum(fn (DevisLigne $l) => (float) $l->montant);

            $nouveau = Devis::create([
                'tiers_id' => $source->tiers_id,
                'libelle' => $source->libelle,
                'date_emission' => $dateEmission->toDateString(),
                'date_validite' => $dateValidite->toDateString(),
                'statut' => StatutDevis::Brouillon,
                'montant_total' => $montantTotal,
                'exercice' => $exercice,
                'numero' => null,
                'saisi_par_user_id' => auth()->id(),
                'accepte_par_user_id' => null,
                'accepte_le' => null,
                'refuse_par_user_id' => null,
                'refuse_le' => null,
                'annule_par_user_id' => null,
                'annule_le' => null,
            ]);

            foreach ($lignesSource as $ligne) {
                DevisLigne::create([
                    'devis_id' => $nouveau->id,
                    'ordre' => $ligne->ordre,
                    'libelle' => $ligne->libelle,
                    'prix_unitaire' => $ligne->prix_unitaire,
                    'quantite' => $ligne->quantite,
                    'montant' => $ligne->montant,
                    'sous_categorie_id' => $ligne->sous_categorie_id,
                ]);
            }

            return $nouveau;
        });
    }

    /**
     * Exécute une mutation dans une transaction avec lockForUpdate sur la ligne du devis.
     *
     * Pattern commun aux transitions marquerAccepte / marquerRefuse / annuler :
     * 1. Démarre une transaction
     * 2. Re-lit la ligne du devis avec lockForUpdate() (élimine TOCTOU)
     * 3. Appelle $mutation($locked) — peut lever une RuntimeException pour un guard
     * 4. Persiste via $locked->save()
     * 5. Synchronise l'instance appelante ($devis) via setRawAttributes
     *
     * @param  callable(Devis): void  $mutation
     *
     * @throws RuntimeException propagé depuis $mutation
     */
    private function muterAvecLock(Devis $devis, callable $mutation): void
    {
        DB::transaction(function () use ($devis, $mutation): void {
            $locked = Devis::withoutGlobalScopes()
                ->whereKey($devis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $mutation($locked);

            $locked->save();

            $devis->setRawAttributes($locked->fresh()->getAttributes(), true);
        });
    }

    /**
     * Repasse le devis en Brouillon si son statut courant est Envoye.
     *
     * Le numéro est conservé intact : il sera réutilisé lors du prochain
     * marquerEnvoye() (qui détecte numero !== null et saute la réattribution).
     *
     * Cette méthode est appelée uniquement depuis les mutations de lignes
     * (ajouterLigne, modifierLigne, supprimerLigne) sur l'instance verrouillée.
     */
    private function rebasculerSiEnvoye(Devis $locked): void
    {
        if ($locked->statut === StatutDevis::Envoye) {
            $locked->statut = StatutDevis::Brouillon;
            $locked->save();
        }
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
