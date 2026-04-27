<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategorieEmail;
use App\Enums\StatutDevis;
use App\Enums\TypeLigneDevis;
use App\Mail\DevisLibreMail;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\EmailLog;
use App\Support\CurrentAssociation;
use App\Tenant\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

            $devis = new Devis([
                'tiers_id' => $tiersId,
                'date_emission' => $dateEmission->toDateString(),
                'date_validite' => $dateValidite->toDateString(),
                'statut' => StatutDevis::Brouillon,
                'montant_total' => 0,
                'saisi_par_user_id' => auth()->id(),
            ]);

            // Champs non fillable assignés directement avant le premier save()
            $devis->exercice = $exercice;
            $devis->numero = null;
            // association_id injecté par TenantModel à l'instanciation
            $devis->save();

            return $devis;
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

            $this->guardAssociation($locked);
            $this->guardStatutVerrouille($locked);

            $prixUnitaire = (float) $data['prix_unitaire'];
            $quantite = isset($data['quantite']) ? (float) $data['quantite'] : 1.0;
            $montant = round($prixUnitaire * $quantite, 2);

            $maxOrdre = $locked->lignes()->max('ordre') ?? 0;

            $ligne = DevisLigne::create([
                'devis_id' => $locked->id,
                'ordre' => $maxOrdre + 1,
                'type' => TypeLigneDevis::Montant,
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
     * Ajoute une ligne de type texte (commentaire / titre de section) au devis.
     *
     * Une ligne texte porte uniquement un libellé ; prix_unitaire, quantite, montant
     * et sous_categorie_id sont nuls. Elle n'impacte pas le montant_total.
     *
     * Mêmes guards que ajouterLigne : refuse si statut verrouillé (Accepte/Refuse/Annule).
     * Si le devis est au statut Envoye, le repasse en Brouillon (rebascule).
     *
     * @throws RuntimeException si le devis est verrouillé
     */
    public function ajouterLigneTexte(Devis $devis, string $texte): DevisLigne
    {
        return DB::transaction(function () use ($devis, $texte): DevisLigne {
            $locked = Devis::withoutGlobalScopes()
                ->whereKey($devis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardAssociation($locked);
            $this->guardStatutVerrouille($locked);

            $maxOrdre = $locked->lignes()->max('ordre') ?? 0;

            $ligne = DevisLigne::create([
                'devis_id' => $locked->id,
                'ordre' => $maxOrdre + 1,
                'type' => TypeLigneDevis::Texte,
                'libelle' => $texte,
                'prix_unitaire' => null,
                'quantite' => null,
                'montant' => null,
                'sous_categorie_id' => null,
            ]);

            $this->rebasculerSiEnvoye($locked);
            $this->recalculerMontantTotal($locked);

            $devis->setRawAttributes($locked->fresh()->getAttributes(), true);

            return $ligne;
        });
    }

    /**
     * Échange l'ordre d'une ligne avec son voisin immédiat (haut ou bas).
     *
     * Si la ligne est déjà en première ou dernière position dans la direction
     * demandée, l'opération est silencieusement ignorée (no-op).
     *
     * Mêmes guards que ajouterLigne : refuse si statut verrouillé.
     * Si le devis est au statut Envoye, le repasse en Brouillon (rebascule).
     *
     * @param  string  $direction  'up' | 'down'
     *
     * @throws RuntimeException si le devis est verrouillé
     */
    public function majOrdre(Devis $devis, int $ligneId, string $direction): void
    {
        DB::transaction(function () use ($devis, $ligneId, $direction): void {
            $locked = Devis::withoutGlobalScopes()
                ->whereKey($devis->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardAssociation($locked);
            $this->guardStatutVerrouille($locked);

            // Charge toutes les lignes triées par ordre pour identifier les voisins
            $lignes = $locked->lignes()->orderBy('ordre')->get();

            $index = $lignes->search(fn (DevisLigne $l): bool => (int) $l->id === $ligneId);

            if ($index === false) {
                return; // Ligne introuvable — no-op
            }

            $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

            if ($targetIndex < 0 || $targetIndex >= $lignes->count()) {
                return; // Déjà en bord — no-op
            }

            $ligne = $lignes->get($index);
            $voisin = $lignes->get($targetIndex);

            $ordreActuel = (int) $ligne->ordre;
            $ordreVoisin = (int) $voisin->ordre;

            $ligne->update(['ordre' => $ordreVoisin]);
            $voisin->update(['ordre' => $ordreActuel]);

            $this->rebasculerSiEnvoye($locked);

            $devis->setRawAttributes($locked->fresh()->getAttributes(), true);
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

            $this->guardAssociation($locked);
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

            $this->guardAssociation($locked);
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

            $this->guardAssociation($locked);

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

            $locked->statut = StatutDevis::Envoye;
            $locked->numero = $numero;
            $locked->save();

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
        // withoutGlobalScopes est sûr ici : association_id et exercice proviennent
        // de la ligne verrouillée dans marquerEnvoyeTransaction(), jamais d'une entrée
        // utilisateur directe. Le guard d'association a déjà été appliqué sur la ligne
        // parente avant l'appel de cette méthode.
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
        // Defense-in-depth: vérifie que le devis source appartient à l'association courante.
        $this->guardAssociation($source);

        return DB::transaction(function () use ($source): Devis {
            $association = CurrentAssociation::get();

            $dateEmission = Carbon::today();
            $validiteJours = $association->devis_validite_jours ?? 30;
            $dateValidite = $dateEmission->copy()->addDays($validiteJours);
            $exercice = $this->exerciceService->anneeForDate($dateEmission);

            $lignesSource = $source->lignes()->orderBy('ordre')->get();

            $montantTotal = $lignesSource->sum(fn (DevisLigne $l) => (float) $l->montant);

            $nouveau = new Devis([
                'tiers_id' => $source->tiers_id,
                'libelle' => $source->libelle,
                'date_emission' => $dateEmission->toDateString(),
                'date_validite' => $dateValidite->toDateString(),
                'statut' => StatutDevis::Brouillon,
                'montant_total' => $montantTotal,
                'saisi_par_user_id' => auth()->id(),
            ]);

            // Champs non fillable assignés directement avant le premier save()
            $nouveau->exercice = $exercice;
            $nouveau->numero = null;
            $nouveau->save();

            foreach ($lignesSource as $ligne) {
                DevisLigne::create([
                    'devis_id' => $nouveau->id,
                    'ordre' => $ligne->ordre,
                    'type' => $ligne->type ?? TypeLigneDevis::Montant,
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
     * Génère un PDF pour le devis et le persiste sur le disque local.
     *
     * Guard : le devis doit avoir au moins une ligne avec montant > 0, sinon
     * RuntimeException.
     *
     * Le filigrane "BROUILLON" est affiché par défaut quand le statut est Brouillon.
     * Le paramètre $brouillonWatermark permet de forcer ou supprimer le filigrane
     * indépendamment du statut (utile pour les tests ou les exports manuels).
     *
     * Path de stockage : associations/{associationId}/devis-libres/{devisId}/devis-{filename}.pdf
     * Retourne le chemin relatif au disque 'local'.
     *
     * @throws RuntimeException si aucune ligne avec montant > 0
     */
    public function genererPdf(Devis $devis, ?bool $brouillonWatermark = null): string
    {
        // Defense-in-depth: vérifie que le devis appartient à l'association courante
        // avant toute génération de contenu ou accès au stockage.
        $this->guardAssociation($devis);

        $devis->load(['lignes', 'tiers']);

        $hasMontant = $devis->lignes->contains(fn (DevisLigne $l) => (float) $l->montant > 0.0);

        if (! $hasMontant) {
            throw new RuntimeException(
                'Le devis doit avoir au moins une ligne avec un montant pour générer un PDF.'
            );
        }

        $watermark = $brouillonWatermark ?? ($devis->statut === StatutDevis::Brouillon);

        $association = CurrentAssociation::get();

        // Charge le logo en base64 pour l'en-tête
        $headerLogoBase64 = null;
        $headerLogoMime = null;
        $logoFullPath = $association?->brandingLogoFullPath();
        if ($logoFullPath && Storage::disk('local')->exists($logoFullPath)) {
            $logoContent = Storage::disk('local')->get($logoFullPath);
            if ($logoContent) {
                $ext = strtolower(pathinfo($logoFullPath, PATHINFO_EXTENSION));
                $headerLogoMime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
                $headerLogoBase64 = base64_encode($logoContent);
            }
        }

        $pdf = Pdf::loadView('pdf.devis-libre', [
            'devis' => $devis,
            'lignes' => $devis->lignes,
            'association' => $association,
            'brouillonWatermark' => $watermark,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
        ])->setPaper('a4', 'portrait');

        // Nom de fichier : numéro ou brouillon-{id}
        $filename = $devis->numero !== null
            ? str_replace('/', '-', $devis->numero)
            : 'brouillon-'.$devis->id;

        $associationId = (int) $devis->association_id;
        $path = 'associations/'.$associationId.'/devis-libres/'.$devis->id.'/devis-'.$filename.'.pdf';

        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    /**
     * Envoie le devis par email au tiers avec le PDF en pièce jointe.
     *
     * Guards :
     * - statut doit être ≠ Brouillon, sinon RuntimeException
     * - au moins une ligne avec montant > 0 doit exister, sinon RuntimeException
     * - le tiers doit avoir une adresse email, sinon RuntimeException
     *
     * Génère le PDF via genererPdf(), envoie le mail via Mail::to()->send(),
     * puis logue l'événement dans email_logs.
     *
     * @throws RuntimeException
     */
    public function envoyerEmail(Devis $devis, string $sujet, string $corps): void
    {
        // Defense-in-depth: vérifie que le devis appartient à l'association courante.
        $this->guardAssociation($devis);

        if ($devis->statut === StatutDevis::Brouillon) {
            throw new RuntimeException('Un devis brouillon ne peut pas être envoyé par email.');
        }

        $devis->load(['lignes', 'tiers']);

        $hasMontant = $devis->lignes->contains(fn (DevisLigne $l) => (float) $l->montant > 0.0);
        if (! $hasMontant) {
            throw new RuntimeException('Le devis doit avoir au moins une ligne avec un montant.');
        }

        $email = $devis->tiers?->email;
        if (empty($email)) {
            throw new RuntimeException('Le tiers n\'a pas d\'adresse email.');
        }

        $pdfPath = $this->genererPdf($devis);

        $mailable = new DevisLibreMail($devis, $sujet, $corps, $pdfPath);

        Mail::to($email)->send($mailable);

        EmailLog::create([
            'tiers_id' => (int) $devis->tiers_id,
            'categorie' => CategorieEmail::Document->value,
            'destinataire_email' => $email,
            'destinataire_nom' => $devis->tiers?->displayName(),
            'objet' => $sujet,
            'corps_html' => $corps,
            'attachment_path' => $pdfPath,
            'statut' => 'envoye',
            'envoye_par' => Auth::id(),
        ]);
    }

    /**
     * Sauvegarde les champs d'en-tête d'un devis (libelle, date_emission, date_validite, tiers_id).
     *
     * Guards (évalués sur la ligne verrouillée) :
     * - statut doit être modifiable (peutEtreModifie()), sinon RuntimeException
     *
     * Seuls les champs libelle, date_emission, date_validite et tiers_id sont modifiables
     * via cette méthode. Les autres champs d'en-tête (numero, exercice, association_id,
     * traces) sont gérés exclusivement par les méthodes dédiées.
     *
     * @param  array<string, mixed>  $data  Clés acceptées : libelle, date_emission, date_validite, tiers_id
     *
     * @throws RuntimeException si le devis est verrouillé
     */
    public function sauvegarderEntete(Devis $devis, array $data): void
    {
        $this->muterAvecLock($devis, function (Devis $locked) use ($data): void {
            $this->guardStatutVerrouille($locked);

            if (array_key_exists('libelle', $data)) {
                $locked->libelle = $data['libelle'] !== '' ? $data['libelle'] : null;
            }

            if (array_key_exists('date_emission', $data)) {
                $locked->date_emission = $data['date_emission'];
            }

            if (array_key_exists('date_validite', $data)) {
                $locked->date_validite = $data['date_validite'];
            }

            if (array_key_exists('tiers_id', $data)) {
                $locked->tiers_id = (int) $data['tiers_id'];
            }
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

            $this->guardAssociation($locked);

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

    /**
     * Vérifie que le devis verrouillé appartient bien à l'association courante (defense-in-depth).
     *
     * Appelé systématiquement après chaque withoutGlobalScopes()->lockForUpdate()->firstOrFail()
     * pour garantir qu'aucune mutation cross-tenant n'est possible, même si le scope global
     * venait à être contourné.
     *
     * Voir docs/multi-tenancy.md — pattern fail-closed.
     *
     * @throws RuntimeException si l'association_id du devis ne correspond pas au contexte courant
     */
    private function guardAssociation(Devis $locked): void
    {
        if ((int) $locked->association_id !== (int) TenantContext::currentId()) {
            throw new RuntimeException('Accès interdit : ce devis n\'appartient pas à votre association.');
        }
    }
}
