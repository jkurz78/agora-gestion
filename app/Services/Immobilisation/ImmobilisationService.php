<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Immobilisation\CompteImmobilisationInvalideException;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Exceptions\Immobilisation\SuppressionInterditeException;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\ExerciceService;
use App\Services\NumeroPieceService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Acquisition d'une immobilisation.
 *
 * La fiche est le maître : elle naît avec son écriture dans la même transaction
 * DB, ce qui interdit structurellement la fiche orpheline comme l'acquisition
 * sans fiche.
 *
 * L'écriture est déléguée à la mécanique dépense existante — c'est elle qui
 * apporte la dette 401, le lettrage, le règlement, la remise et le
 * rapprochement, sans une ligne de code de plus ici.
 */
final class ImmobilisationService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly ImmobilisationSequenceService $sequence,
        private readonly ExerciceService $exerciceService,
        private readonly TransactionService $transactionService,
        private readonly EtatReglementResolver $etatReglementResolver,
    ) {}

    public function acquerir(
        Tiers $tiers,
        string $libelle,
        int $quantite,
        Compte $compte,
        Compte $compteAmortissement,
        string $montant,
        \DateTimeInterface $dateAchat,
        \DateTimeInterface $dateMiseEnService,
        int $dureeMois,
        ?ModePaiement $modePaiement,
        ?Compte $compteTresorerie,
        ?string $notes = null,
        ?\DateTimeInterface $dateReglement = null,
    ): Immobilisation {
        $this->assertCoupleReglementCoherent($modePaiement, $compteTresorerie);
        $this->assertComptesValides($compte, $compteAmortissement);
        $this->assertMiseEnServiceCoherente($dateAchat, $dateMiseEnService);

        // Filet de sécurité hors verrou : échouer tôt sur un exercice
        // manifestement clôturé évite d'ouvrir une transaction pour rien. Le
        // contrôle qui fait foi est celui, verrouillé, à l'intérieur.
        $this->assertExerciceOuvert($dateAchat);

        $immobilisation = DB::transaction(function () use (
            $tiers, $libelle, $quantite, $compte, $compteAmortissement, $montant,
            $dateAchat, $dateMiseEnService, $dureeMois, $modePaiement,
            $compteTresorerie, $notes, $dateReglement
        ): Immobilisation {
            // Contre-audit P1-01 — le contrôle réel, verrouillé, a lieu ICI :
            // une clôture concurrente ne peut plus s'intercaler entre le
            // contrôle et l'écriture, sans quoi l'application se retrouverait
            // avec une acquisition postérieure à la clôture de son exercice.
            $this->assertExerciceOuvertVerrouille($dateAchat);

            // Le règlement peut tomber dans un exercice différent de l'achat
            // (acheté en août, payé en septembre) : son propre exercice doit
            // être ouvert lui aussi, sinon on écrirait dans des comptes clos.
            if ($dateReglement !== null) {
                $this->assertExerciceOuvertVerrouille($dateReglement);
            }

            // L'en-tête et la ligne de ventilation sont construits explicitement ici,
            // avec les champs métier (tiers_id, montant de ventilation) que
            // TransactionService pose normalement en amont dans son propre flux —
            // EcritureGenerator::createTransactionHeader() (appelée quand
            // existingTransaction est null) ne les connaît pas : elle produit un
            // en-tête "nu" et des lignes de ventilation à montant=0, corrects pour
            // ses autres appelants mais pas ici. On reproduit donc le flux
            // TransactionService::create() : en-tête + ligne créés à la main, puis
            // pourDepenseACredit(existingTransaction: ...) qui saute la création des
            // lignes de ventilation et se contente d'ajouter la ligne 401 (avec tiers).
            $transaction = Transaction::create([
                'association_id' => (int) TenantContext::currentId(),
                'type' => TypeTransaction::Depense,
                'date' => $dateAchat->format('Y-m-d'),
                'libelle' => $libelle,
                'montant_total' => $montant,
                'mode_paiement' => null,
                'tiers_id' => (int) $tiers->id,
                'saisi_par' => Auth::id(),
                'equilibree' => true,
                'type_ecriture' => 'normale',
                'numero_piece' => app(NumeroPieceService::class)->assign(
                    Carbon::parse($dateAchat->format('Y-m-d'))
                ),
            ]);

            TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => (int) $compte->id,
                'debit' => (float) $montant,
                'credit' => 0,
                'tiers_id' => null,
                'libelle' => $libelle,
                'montant' => (float) $montant,
            ]);

            $transaction = $this->ecritureGenerator->pourDepenseACredit(
                tiers: $tiers,
                ventilations: [['compte' => $compte, 'montant' => (float) $montant]],
                dateConstatation: $dateAchat,
                libelle: $libelle,
                existingTransaction: $transaction,
                autoriseImmobilisation: true,
            );

            if ($modePaiement !== null && $compteTresorerie !== null) {
                $this->ecritureGenerator->pourReglementFournisseur(
                    transactionDette: $transaction,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    datePaiement: $dateReglement ?? $dateAchat,
                    libelle: 'Règlement '.$libelle,
                );

                // `statut_reglement` est une colonne dérivée du grand livre : sans
                // cette synchronisation elle reste à sa valeur de création, et
                // l'écran de la transaction se contredit — le bloc règlement, lu
                // sur les écritures, montre le paiement pendant que « Paiement
                // effectué ? », lu sur la colonne, répond non. Tous les autres
                // chemins de création appellent ce resolver (TransactionService::
                // create(), ReglementOperationService) ; acquerir() était le seul
                // à ne pas le faire.
                $this->etatReglementResolver->syncer($transaction->fresh());
            }

            return Immobilisation::create([
                'numero' => $this->sequence->prochain(),
                'libelle' => $libelle,
                'quantite' => $quantite,
                'compte_id' => (int) $compte->id,
                'compte_amortissement_id' => (int) $compteAmortissement->id,
                'montant_acquisition' => $montant,
                'date_mise_en_service' => CarbonImmutable::instance(
                    CarbonImmutable::parse($dateMiseEnService->format('Y-m-d'))
                )->toDateString(),
                'duree_mois' => $dureeMois,
                'transaction_id' => (int) $transaction->id,
                'notes' => $notes,
            ]);
        });

        // Log après le commit (hors transaction pour éviter le rollback du log —
        // même pattern que FactureService::valider()/annuler()).
        Log::info('immobilisation.acquise', [
            'immobilisation_id' => (int) $immobilisation->id,
            'numero' => $immobilisation->numero,
            'transaction_id' => (int) $immobilisation->transaction_id,
        ]);

        return $immobilisation;
    }

    /**
     * Modifie les champs qui n'engagent pas l'écriture comptable d'acquisition.
     *
     * `montant_acquisition` et `compte_id` sont volontairement absents de la
     * signature : ils portent la transaction d'acquisition, potentiellement
     * déjà réglée, lettrée ou rapprochée. Les corriger impose de supprimer la
     * fiche et de la resaisir — voir supprimer().
     *
     * Les dotations déjà comptabilisées ne sont pas retouchées ici : c'est le
     * mécanisme de recalcul existant (DotationService::recalculer(), exposé par
     * l'écran des dotations) qui absorbe l'écart le moment venu.
     */
    public function modifier(
        Immobilisation $immobilisation,
        string $libelle,
        int $quantite,
        int $dureeMois,
        \DateTimeInterface $dateMiseEnService,
        ?string $notes,
    ): Immobilisation {
        $transaction = $immobilisation->transaction;

        if ($transaction !== null) {
            // Filet de sécurité hors verrou, comme dans acquerir() : le
            // contrôle qui fait foi est répété sous verrou dans la transaction.
            $this->assertExerciceOuvert($transaction->date);
            $this->assertMiseEnServiceCoherente($transaction->date, $dateMiseEnService);
        }

        // Contre-audit P1-01 — la modification n'ouvrait aucune transaction :
        // le contrôle de clôture et l'update couraient séparément, et la fiche
        // pouvait être modifiée après la clôture de l'exercice de son
        // acquisition. La modification est aussi en course avec une génération
        // de dotation, qui lit duree_mois et date_mise_en_service pour calculer
        // son montant : verrouiller la fiche les sérialise.
        $immobilisation = DB::transaction(function () use (
            $immobilisation, $libelle, $quantite, $dureeMois, $dateMiseEnService, $notes
        ): Immobilisation {
            $immobilisation = Immobilisation::query()
                ->whereKey((int) $immobilisation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = $immobilisation->transaction;

            if ($transaction !== null) {
                $this->assertExerciceOuvertVerrouille($transaction->date);
                $this->assertMiseEnServiceCoherente($transaction->date, $dateMiseEnService);
            }

            $immobilisation->update([
                'libelle' => $libelle,
                'quantite' => $quantite,
                'duree_mois' => $dureeMois,
                'date_mise_en_service' => CarbonImmutable::parse(
                    $dateMiseEnService->format('Y-m-d')
                )->toDateString(),
                'notes' => $notes,
            ]);

            return $immobilisation;
        });

        Log::info('immobilisation.modifiee', [
            'immobilisation_id' => (int) $immobilisation->id,
            'numero' => $immobilisation->numero,
        ]);

        return $immobilisation->fresh();
    }

    /**
     * Supprime la fiche et sa transaction d'acquisition (soft-delete des deux,
     * dans une même transaction DB) : une fiche saisie par erreur ne doit pas
     * rester au registre pour toujours.
     *
     * La fiche est supprimée avant la transaction : Transaction::isLockedByImmobilisation()
     * ne trouve alors plus rien (le scope SoftDeletes exclut la fiche fraîchement
     * supprimée) et TransactionService::delete() — qui porte par ailleurs le
     * contrôle de clôture d'exercice et les autres verrous (rapprochement, remise,
     * facture) — peut s'exécuter normalement. Le garde reste donc intact pour la
     * suppression depuis la liste des transactions ; il ne bloque plus ici parce
     * que la fiche n'existe déjà plus.
     */
    public function supprimer(Immobilisation $immobilisation): void
    {
        if ($immobilisation->dotations()->exists()) {
            throw SuppressionInterditeException::dotationsExistantes($immobilisation->numero);
        }

        $transaction = $immobilisation->transaction;

        if ($transaction === null) {
            throw new \RuntimeException(
                "La fiche {$immobilisation->numero} n'a pas de transaction d'acquisition : incohérence de données."
            );
        }

        $immobilisationId = (int) $immobilisation->id;
        $numero = $immobilisation->numero;
        $transactionId = (int) $transaction->id;

        DB::transaction(function () use ($immobilisation, $transaction): void {
            $immobilisation->delete();
            $this->transactionService->delete($transaction);
        });

        Log::info('immobilisation.supprimee', [
            'immobilisation_id' => $immobilisationId,
            'numero' => $numero,
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * Le règlement immédiat est un couple indivisible : mode de paiement ET
     * compte de trésorerie, ou aucun des deux (acquisition à crédit).
     *
     * Sans ce contrôle, la condition de génération du règlement plus bas
     * (`$modePaiement !== null && $compteTresorerie !== null`) traite tout
     * couple incomplet comme une acquisition à crédit : un mode sans compte
     * produit une dette 401 que l'appelant croit réglée, un compte sans mode
     * est ignoré en silence. L'écran ne peut pas produire ce couple — il
     * résout le compte via CompteTresorerieResolver et refuse si null — mais
     * le service est la frontière métier réutilisable (commande artisan,
     * import, job), et c'est elle qui doit tenir.
     */
    private function assertCoupleReglementCoherent(
        ?ModePaiement $modePaiement,
        ?Compte $compteTresorerie,
    ): void {
        if (($modePaiement === null) !== ($compteTresorerie === null)) {
            throw new \InvalidArgumentException(
                'Un règlement immédiat exige à la fois un mode de paiement et un compte de trésorerie.'
            );
        }
    }

    /**
     * Les seules barrières posées côté interface — PlanComptableSelecteur qui
     * ne propose que la classe 2 hors 28X, et une validation Livewire qui se
     * contente d'un exists:comptes,id — ne protègent rien côté serveur :
     * compte_amortissement_id est une propriété Livewire publique, hydratée
     * depuis le client à chaque requête, falsifiable malgré son champ HTML en
     * lecture seule. Le service est la frontière qui compte.
     *
     * Périmètre volontairement plus large que le « compte 21X » de la
     * spécification initiale du module : classe 2 hors 28X. Restreindre au
     * préfixe 21 bloquerait les immobilisations incorporelles (un logiciel en
     * 205, amorti en 2805) alors que la règle de dérivation PCG — insérer un
     * « 8 » après le premier chiffre — vaut pour toute la classe 2. Décision
     * assumée du propriétaire du produit.
     */
    private function assertComptesValides(Compte $compte, Compte $compteAmortissement): void
    {
        $tenantId = (int) TenantContext::currentId();

        if ((int) $compte->association_id !== $tenantId) {
            throw CompteImmobilisationInvalideException::horsTenant((string) $compte->numero_pcg);
        }

        if ((int) $compteAmortissement->association_id !== $tenantId) {
            throw CompteImmobilisationInvalideException::horsTenant((string) $compteAmortissement->numero_pcg);
        }

        if (str_starts_with((string) $compte->numero_pcg, '28')) {
            throw CompteImmobilisationInvalideException::estCompteAmortissement((string) $compte->numero_pcg);
        }

        if ((int) $compte->classe !== 2) {
            throw CompteImmobilisationInvalideException::classeInvalide(
                (string) $compte->numero_pcg,
                (int) $compte->classe,
            );
        }

        if (! $compte->actif) {
            throw CompteImmobilisationInvalideException::inactif((string) $compte->numero_pcg);
        }

        if (! $compteAmortissement->actif) {
            throw CompteImmobilisationInvalideException::inactif((string) $compteAmortissement->numero_pcg);
        }

        $derive = ImmobilisationComptesSeeder::compteAmortissementPour($compte);

        if ($derive === null || (int) $derive->id !== (int) $compteAmortissement->id) {
            throw CompteImmobilisationInvalideException::amortissementIncorrect(
                (string) $compte->numero_pcg,
                '2'.'8'.substr((string) $compte->numero_pcg, 1),
                (string) $compteAmortissement->numero_pcg,
            );
        }
    }

    /**
     * L'exercice de la date d'achat doit être ouvert — écrire dans un
     * exercice clôturé est interdit partout ailleurs dans l'application
     * (TransactionService::delete()/annuler() portent déjà ce contrôle,
     * voir leurs premières lignes) ; rien ne l'imposait ici.
     *
     * Réutilisée par modifier() avec la date de la transaction d'acquisition :
     * modifier une fiche dont l'acquisition est clôturée doit être refusé
     * aussi, pas seulement en créer une nouvelle.
     */
    private function assertExerciceOuvert(\DateTimeInterface $date): void
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($date->format('Y-m-d')))
        );
    }

    /**
     * Même contrôle, sous le protocole de verrou canonique (association puis
     * exercice) partagé avec ExerciceService::cloturer() et
     * DotationService::assertExerciceGenerable().
     *
     * N'a d'effet qu'appelée à l'intérieur du DB::transaction() qui porte
     * l'écriture : c'est ce qui empêche une clôture concurrente de s'intercaler
     * entre le contrôle et l'insertion.
     */
    private function assertExerciceOuvertVerrouille(\DateTimeInterface $date): void
    {
        $this->exerciceService->assertOuvertPourEcriture(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($date->format('Y-m-d')))
        );
    }

    /**
     * La mise en service ne peut pas précéder le début de l'exercice de
     * l'acquisition — sinon on doterait un exercice où le bien n'est pas encore
     * à l'actif. Aucune borne supérieure : la mise en service différée est
     * légitime, et le calculateur la gère par son plancher à 0.
     *
     * Réutilisée par modifier() : la modification de date_mise_en_service est
     * soumise au même contrôle de cohérence que la création.
     */
    private function assertMiseEnServiceCoherente(
        \DateTimeInterface $dateAchat,
        \DateTimeInterface $dateMiseEnService,
    ): void {
        $exerciceAchat = $this->exerciceService->anneeForDate(
            CarbonImmutable::parse($dateAchat->format('Y-m-d'))
        );
        $debutExercice = $this->exerciceService->dateRange($exerciceAchat)['start'];

        if (CarbonImmutable::parse($dateMiseEnService->format('Y-m-d'))->lt($debutExercice)) {
            throw MiseEnServiceAnterieureException::pourExercice(
                $dateMiseEnService->format('d/m/Y'),
                $debutExercice->format('d/m/Y'),
            );
        }
    }
}
