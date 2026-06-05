<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalComptable;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;
use App\Services\Compta\EtatReglementResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RapprochementBancaireService
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly ReglementOperationService $reglementService,
        private readonly EtatReglementResolver $etatReglementResolver,
    ) {}

    /**
     * Calcule le solde d'ouverture : solde_fin du dernier rapprochement verrouillé,
     * ou solde_initial du compte si aucun n'existe.
     */
    public function calculerSoldeOuverture(CompteBancaire $compte): float
    {
        $dernier = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::Verrouille)
            ->orderByDesc('date_fin')
            ->orderByDesc('id')
            ->first();

        return $dernier ? (float) $dernier->solde_fin : (float) $compte->solde_initial;
    }

    /**
     * Crée un nouveau rapprochement pour un compte.
     * Lève RuntimeException si un rapprochement "en cours" existe déjà sur ce compte.
     */
    public function create(CompteBancaire $compte, string $dateFin, float $soldeFin): RapprochementBancaire
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($dateFin))
        );

        $enCours = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::EnCours)
            ->exists();

        if ($enCours) {
            throw new RuntimeException('Un rapprochement est déjà en cours pour ce compte.');
        }

        return DB::transaction(function () use ($compte, $dateFin, $soldeFin) {
            return RapprochementBancaire::create([
                'compte_id' => $compte->id,
                'date_fin' => $dateFin,
                'solde_ouverture' => $this->calculerSoldeOuverture($compte),
                'solde_fin' => $soldeFin,
                'statut' => StatutRapprochement::EnCours,
                'saisi_par' => auth()->id(),
            ]);
        });
    }

    /**
     * Crée un rapprochement directement verrouillé (auto-généré par la sync HelloAsso).
     * Ne vérifie pas s'il existe un rapprochement en cours — indépendant du workflow manuel.
     *
     * @param  list<int>  $transactionIds  IDs des transactions à pointer
     */
    public function createVerrouilleAuto(
        CompteBancaire $compte,
        string $dateFin,
        float $soldeFin,
        array $transactionIds,
        int $virementId,
    ): RapprochementBancaire {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($dateFin))
        );

        return DB::transaction(function () use ($compte, $dateFin, $soldeFin, $transactionIds, $virementId) {
            $rapprochement = RapprochementBancaire::create([
                'compte_id' => $compte->id,
                'date_fin' => $dateFin,
                'solde_ouverture' => $this->calculerSoldeOuverture($compte),
                'solde_fin' => $soldeFin,
                'statut' => StatutRapprochement::Verrouille,
                'verrouille_at' => now(),
                'saisi_par' => auth()->id() ?? 1,
            ]);

            if (! empty($transactionIds)) {
                Transaction::whereIn('id', $transactionIds)
                    ->update([
                        'rapprochement_id' => $rapprochement->id,
                        'statut_reglement' => StatutReglement::Pointe->value,
                    ]);
            }

            VirementInterne::where('id', $virementId)
                ->update(['rapprochement_source_id' => $rapprochement->id]);

            return $rapprochement;
        });
    }

    /**
     * Calcule le solde pointé courant :
     * solde_ouverture + entrées pointées − sorties pointées.
     *
     * Mode legacy : Transaction.rapprochement_id → SUM(CASE type THEN ±montant_total).
     * Mode PD     : transaction_lignes.compte_id IN (512X du compte) jointure sur
     *               transactions.rapprochement_id → SUM(debit) - SUM(credit).
     *
     * Dans les deux modes, les virements internes restent calculés via
     * VirementInterne.rapprochement_source_id / rapprochement_destination_id
     * (ils n'ont pas de transaction_lignes PD dans slice 1).
     *
     * Comportement mixte (mode PD, transactions non enrichies) : les transactions
     * sans ligne 512X enrichie sont invisibles au calcul PD. C'est documenté et
     * attendu — le backfill (slice 1d) les rendra visibles.
     */
    public function calculerSoldePointage(RapprochementBancaire $rapprochement): float
    {
        $solde = (float) $rapprochement->solde_ouverture;

        if (config('compta.use_partie_double')) {
            // Mode PD : lire les lignes 512X du compte bancaire de ce rapprochement,
            // liées aux transactions pointées (rapprochement_id = ce rapprochement).
            $compte512X = $this->resoudreCompte512X($rapprochement->compte);

            if ($compte512X !== null) {
                $mouvement = (float) TransactionLigne::where('transaction_lignes.compte_id', $compte512X->id)
                    ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
                    ->where('transactions.rapprochement_id', $rapprochement->id)
                    ->selectRaw('SUM(transaction_lignes.debit) - SUM(transaction_lignes.credit) as net')
                    ->value('net');

                $solde += $mouvement;
            } else {
                Log::warning('[PartieDouble][RapprochementBancaireService] — skip solde : compte 512X introuvable pour CompteBancaire', [
                    'compte_bancaire_id' => (int) $rapprochement->compte_id,
                    'rapprochement_id' => (int) $rapprochement->id,
                ]);
            }
            // Si compte 512X introuvable (tenant sans schéma PD), solde = ouverture seul.
        } else {
            // Mode legacy : type + montant_total à l'entête Transaction.
            //
            // Step 31 — exclure les T1 sources de remise du SUM.
            // Depuis Step 25 PD, toggleRemise() pointe à la fois les T1 sources ET la T4 consolidée.
            // Les T1 sources sont des chèques individuels ; la T4 est le dépôt bancaire qui les
            // représente tous. En compter les deux = double-comptage. Décision : compter la T4 seule
            // (identique au comportement PD via la ligne 512X de la T4).
            //
            // Critère structurel : un T1 source de remise ne porte PAS de ligne 512X (son portage
            // est sur 5112/530) ; seule la T4 porte une ligne 512X. On exclut donc les transactions
            // de remise sans ligne 512X. Volontairement indépendant de `reference` : des chèques
            // remisés réels (prod) ont reference = NULL, ce qui faisait rater l'exclusion par l'ancien
            // critère `reference IS NOT NULL` → double-comptage T1 + T4 (Finding 2, cutover 2026-05-31).
            $solde += (float) Transaction::where('rapprochement_id', $rapprochement->id)
                ->whereNot(function (Builder $q): void {
                    $q->whereNotNull('remise_id')
                        ->whereDoesntHave('lignes', fn (Builder $l): Builder => $l
                            ->whereHas('compte', fn (Builder $c): Builder => $c->bancaires()));
                })
                // Chantier 2a — exclure les T2 d'encaissement séparées (journal=Banque, remise_id null).
                // Depuis le chantier 2a, TransactionService produit une T2 (journal=Banque) distincte
                // pour les recettes comptant. Ces T2 ont rapprochement_id propagé depuis toggleTransaction,
                // mais leur montant_total ne doit PAS être compté en mode legacy (doublon avec T1).
                // Les T4 de remise (journal=Banque ET remise_id NOT NULL) sont conservées dans le SUM.
                ->whereNot(function (Builder $q): void {
                    $q->where('journal', JournalComptable::Banque->value)
                        ->whereNull('remise_id');
                })
                ->selectRaw("SUM(CASE WHEN type = 'depense' THEN -montant_total ELSE montant_total END) as total")
                ->value('total');
        }

        $solde += (float) VirementInterne::where('rapprochement_destination_id', $rapprochement->id)->sum('montant');
        $solde -= (float) VirementInterne::where('rapprochement_source_id', $rapprochement->id)->sum('montant');

        return round($solde, 2);
    }

    /**
     * Calcule l'écart : solde_fin - solde_pointage.
     */
    public function calculerEcart(RapprochementBancaire $rapprochement): float
    {
        return round((float) $rapprochement->solde_fin - $this->calculerSoldePointage($rapprochement), 2);
    }

    /**
     * Pointe ou dé-pointe une transaction pour ce rapprochement.
     * Types: 'depense', 'recette', 'virement_source', 'virement_destination'
     */
    public function toggleTransaction(RapprochementBancaire $rapprochement, string $type, int $id): void
    {
        if ($rapprochement->isVerrouille()) {
            throw new RuntimeException('Impossible de modifier un rapprochement verrouillé.');
        }

        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        // Verify account ownership before modifying
        if ($type === 'remise') {
            $remiseTx = Transaction::where('remise_id', $id)->where('compte_id', $rapprochement->compte_id)->first();
            if ($remiseTx === null) {
                throw new \InvalidArgumentException('Remise introuvable sur ce compte.');
            }
        } elseif (str_starts_with($type, 'virement')) {
            $virement = VirementInterne::findOrFail($id);
            $expectedField = $type === 'virement_source' ? 'compte_source_id' : 'compte_destination_id';
            if ((int) $virement->{$expectedField} !== (int) $rapprochement->compte_id) {
                throw new \InvalidArgumentException("La transaction n'appartient pas au compte de ce rapprochement.");
            }
        } else {
            $model = match ($type) {
                'depense', 'recette' => Transaction::findOrFail($id),
                default => throw new \InvalidArgumentException("Type de transaction inconnu : {$type}"),
            };
            if ((int) $model->compte_id !== (int) $rapprochement->compte_id) {
                throw new \InvalidArgumentException("La transaction n'appartient pas au compte de ce rapprochement.");
            }
        }

        DB::transaction(function () use ($rapprochement, $type, $id) {
            if ($type === 'remise') {
                $this->toggleRemise($rapprochement, $id);

                return;
            }

            if (str_starts_with($type, 'virement')) {
                $this->toggleVirement($rapprochement, $type, $id);

                return;
            }

            $model = match ($type) {
                'depense', 'recette' => Transaction::findOrFail($id),
                default => throw new \InvalidArgumentException("Type de transaction inconnu : {$type}"),
            };

            if ((int) $model->rapprochement_id === (int) $rapprochement->id) {
                // Dé-pointage : effacer rapprochement_id sur T1 et sur T2 séparée si présente.
                // Chantier 2a : recette comptant → T2 via trouverEncaissementT2 (411).
                // Chantier 3a-i : dépense comptant → T2 via trouverReglementT2 (401).
                $t2 = $type === 'depense'
                    ? $this->reglementService->trouverReglementT2($model)
                    : $this->reglementService->trouverEncaissementT2($model);

                $model->rapprochement_id = null;
                // Legacy fallback — sert aussi d'état de base pour le syncer PD ci-dessous.
                $model->statut_reglement = $model->remise_id !== null
                    ? StatutReglement::Recu
                    : StatutReglement::EnAttente;
                $model->save();

                if ($t2 !== null && (int) $t2->rapprochement_id === (int) $rapprochement->id) {
                    $t2->rapprochement_id = null;
                    $t2->save();
                }

                // Chantier 4 — statut dérivé du ledger (après effacement du rapprochement_id sur T1 et T2).
                // En mode PD, overrides le fallback legacy ci-dessus avec la valeur dérivée.
                $this->etatReglementResolver->syncer($model);
            } else {
                // Pointage : générer T2 si en_attente (Fix D — idempotent, recettes seulement)
                if (config('compta.use_partie_double') && $type !== 'depense') {
                    $this->reglementService->encaisserSiNonEncaisse($model);
                }

                $model->rapprochement_id = $rapprochement->id;
                // Legacy fallback — sert aussi d'état de base pour le syncer PD ci-dessous.
                $model->statut_reglement = StatutReglement::Pointe;
                $model->save();

                // Propager rapprochement_id sur T2 séparée si elle existe.
                // Chantier 2a : recettes comptant → T2 via trouverEncaissementT2 (411).
                // Chantier 3a-i : dépenses comptant → T2 via trouverReglementT2 (401).
                // Sans garde use_partie_double — les T2 sont créées systématiquement depuis
                // les chantiers 2a/3a-i. Les méthodes retournent null pour les transactions
                // legacy (lumpées) ou sans T2 → no-op.
                $t2 = $type === 'depense'
                    ? $this->reglementService->trouverReglementT2($model)
                    : $this->reglementService->trouverEncaissementT2($model);

                if ($t2 !== null) {
                    $t2->rapprochement_id = $rapprochement->id;
                    $t2->save();
                }

                // Chantier 4 — statut dérivé du ledger (après propagation du rapprochement_id sur T2).
                $this->etatReglementResolver->syncer($model);
            }
        });
    }

    private function toggleRemise(RapprochementBancaire $rapprochement, int $remiseId): void
    {
        $transactions = Transaction::where('remise_id', $remiseId)->get();
        $allPointed = $transactions->every(fn (Transaction $tx) => (int) $tx->rapprochement_id === (int) $rapprochement->id);

        foreach ($transactions as $tx) {
            if ($allPointed) {
                $tx->rapprochement_id = null;
                // Legacy fallback — sert aussi d'état de base pour le syncer PD ci-dessous.
                $tx->statut_reglement = StatutReglement::Recu;
            } else {
                $tx->rapprochement_id = $rapprochement->id;
                // Legacy fallback — sert aussi d'état de base pour le syncer PD ci-dessous.
                $tx->statut_reglement = StatutReglement::Pointe;
            }
            $tx->save();
            // Chantier 4 — statut dérivé (les T4 remise portent le 512X rapproché/dé-rapproché).
            // En mode PD, overrides le fallback legacy ci-dessus avec la valeur dérivée.
            $this->etatReglementResolver->syncer($tx);
        }
    }

    // VirementInterne n'a pas de champ 'pointe' — le pointage est indiqué
    // par rapprochement_source_id / rapprochement_destination_id.
    private function toggleVirement(RapprochementBancaire $rapprochement, string $type, int $id): void
    {
        $virement = VirementInterne::findOrFail($id);
        $field = $type === 'virement_source' ? 'rapprochement_source_id' : 'rapprochement_destination_id';

        if ((int) $virement->{$field} === (int) $rapprochement->id) {
            $virement->{$field} = null;
        } else {
            $virement->{$field} = $rapprochement->id;
        }
        $virement->save();
    }

    /**
     * Supprime un rapprochement "en cours" et dépointe toutes ses opérations.
     * Lève RuntimeException si le rapprochement est verrouillé.
     */
    public function supprimer(RapprochementBancaire $rapprochement): void
    {
        if ($rapprochement->isVerrouille()) {
            throw new RuntimeException('Impossible de supprimer un rapprochement verrouillé.');
        }

        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        DB::transaction(function () use ($rapprochement) {
            $id = $rapprochement->id;

            // Supprimer la pièce jointe si présente
            $this->deletePieceJointe($rapprochement);

            Transaction::where('rapprochement_id', $id)->each(function (Transaction $tx): void {
                // Legacy fallback — sert aussi d'état de base pour le syncer PD ci-dessous.
                $tx->update([
                    'rapprochement_id' => null,
                    'statut_reglement' => $tx->remise_id !== null
                        ? StatutReglement::Recu->value
                        : StatutReglement::EnAttente->value,
                ]);
                // Chantier 4 — statut dérivé du ledger (overrides le fallback en mode PD).
                $this->etatReglementResolver->syncer($tx);
            });

            VirementInterne::where('rapprochement_source_id', $id)
                ->update(['rapprochement_source_id' => null]);

            VirementInterne::where('rapprochement_destination_id', $id)
                ->update(['rapprochement_destination_id' => null]);

            $rapprochement->delete();
        });
    }

    /**
     * Déverrouille le rapprochement s'il est le dernier verrouillé du compte
     * et qu'aucun rapprochement en cours n'existe sur ce compte.
     */
    public function deverrouiller(RapprochementBancaire $rapprochement): void
    {
        if (! $rapprochement->isVerrouille()) {
            throw new RuntimeException("Ce rapprochement n'est pas verrouillé.");
        }

        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        $enCours = RapprochementBancaire::where('compte_id', $rapprochement->compte_id)
            ->where('statut', StatutRapprochement::EnCours)
            ->exists();

        if ($enCours) {
            throw new RuntimeException('Impossible de déverrouiller : un rapprochement est en cours sur ce compte.');
        }

        $dernierVerrouille = RapprochementBancaire::where('compte_id', $rapprochement->compte_id)
            ->where('statut', StatutRapprochement::Verrouille)
            ->orderByDesc('date_fin')
            ->orderByDesc('id')
            ->value('id');

        if ($dernierVerrouille !== $rapprochement->id) {
            throw new RuntimeException('Seul le dernier rapprochement verrouillé peut être déverrouillé.');
        }

        DB::transaction(function () use ($rapprochement) {
            $rapprochement->statut = StatutRapprochement::EnCours;
            $rapprochement->verrouille_at = null;
            $rapprochement->save();
        });
    }

    /**
     * Verrouille le rapprochement. L'écart doit être 0.
     */
    public function verrouiller(RapprochementBancaire $rapprochement): void
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($rapprochement->date_fin))
        );

        if ((int) round($this->calculerEcart($rapprochement) * 100) !== 0) {
            throw new RuntimeException("Le rapprochement ne peut être verrouillé que si l'écart est nul.");
        }

        DB::transaction(function () use ($rapprochement) {
            $rapprochement->statut = StatutRapprochement::Verrouille;
            $rapprochement->verrouille_at = now();
            $rapprochement->save();
        });
    }

    public function storePieceJointe(RapprochementBancaire $rapprochement, UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé : '.$mime);
        }

        if ($rapprochement->piece_jointe_path !== null) {
            Storage::disk('local')->deleteDirectory(
                $rapprochement->storagePath('rapprochements/'.$rapprochement->id)
            );
        }

        $extension = $file->guessExtension() ?? 'bin';
        $shortName = "releve.{$extension}";
        $file->storeAs(
            $rapprochement->storagePath('rapprochements/'.$rapprochement->id),
            $shortName,
            'local'
        );

        $rapprochement->update([
            'piece_jointe_path' => $shortName,
            'piece_jointe_nom' => $file->getClientOriginalName(),
            'piece_jointe_mime' => $mime,
        ]);
    }

    public function deletePieceJointe(RapprochementBancaire $rapprochement): void
    {
        if ($rapprochement->piece_jointe_path === null) {
            return;
        }

        Storage::disk('local')->deleteDirectory(
            $rapprochement->storagePath('rapprochements/'.$rapprochement->id)
        );

        $rapprochement->update([
            'piece_jointe_path' => null,
            'piece_jointe_nom' => null,
            'piece_jointe_mime' => null,
        ]);
    }

    // =========================================================================
    // Helpers partie double (Step 29)
    // =========================================================================

    /**
     * Résout le compte PCG classe 5 (512X) correspondant au CompteBancaire du rapprochement.
     *
     * Le lien est établi via l'IBAN : BancairesSeeder crée un Compte avec le même IBAN
     * que le CompteBancaire (numéro PCG 5121, 5122, etc.). Ce compte est le « compte de
     * trésorerie » des écritures partie double.
     *
     * Retourne null si le tenant n'a pas encore de schéma PD (compte 512X manquant).
     * Dans ce cas, calculerSoldePointage retourne solde_ouverture seul (comportement
     * documenté mode mixte legacy/PD pendant la transition — Step 29).
     *
     * Exposé public pour être utilisé par RapprochementDetail::render() afin de
     * filtrer la liste des écritures pointables sur le 512X strict du compte.
     */
    public function resoudreCompte512X(CompteBancaire $compteBancaire): ?Compte
    {
        // Résolution par compte_bancaire_id (clé stable — l'IBAN est nullable et non unique).
        return Compte::where('compte_bancaire_id', $compteBancaire->id)
            ->bancaires()
            ->first();
    }
}
