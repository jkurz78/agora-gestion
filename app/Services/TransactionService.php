<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\CompteTresorerieResolver;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\LettrageService;
use App\Services\Compta\PartieDoubleGuard;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class TransactionService
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly LettrageService $lettrageService,
        private readonly EtatReglementResolver $etatReglementResolver,
    ) {}

    public function create(array $data, array $lignes): Transaction
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        $this->validateInscriptionRequiresOperation($lignes);

        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
            $transaction = Transaction::create($data);

            // DC-10a — les lignes arrivent avec compte_id (contrat) : debit/credit sont
            // calculés ici même, à la création, plutôt que d'être enrichis a posteriori
            // par enrichirPartieDouble (voir docblock de cette méthode).
            $lignesCreees = [];
            foreach ($lignes as $ligne) {
                $ligneCreee = $transaction->lignes()->create(
                    $this->avecDebitCredit($ligne, $transaction->type)
                );
                $lignesCreees[] = $ligneCreee;
            }

            // Enrichissement partie double : double écriture vers EcritureGenerator
            // Step 21 — branchement systématique (pas de feature flag)
            $this->enrichirPartieDouble($transaction, $lignesCreees);

            // Chantier 4 — recalcul du statut miroir depuis le ledger (couvre les
            // transactions comptant créées live : cheque→EnMain, virement→Recu).
            // Doit s'exécuter APRÈS enrichirPartieDouble (lignes PD et lettrage présents).
            $this->etatReglementResolver->syncer($transaction->fresh());

            return $transaction->fresh();
        });
    }

    /**
     * Enrichit une transaction en générant les écritures partie double (école 411
     * systématique, spec §4.3 amendée 2026-05-22).
     *
     * DC-10a — contrat compte_id : les lignes passées par $lignesCreees arrivent
     * déjà avec compte_id + debit/credit posés à la création (voir create()/update()
     * et avecDebitCredit()). Cette méthode :
     * - valide que compte_id est non-null et résout vers un Compte de la classe
     *   attendue (6 dépense / 7 recette) — garde défensive contre un mauvais caller ;
     * - est idempotente : si la ligne porte déjà debit/credit > 0, elle n'est PAS
     *   ré-enregistrée (pas de fill/save inutile) mais est tout de même collectée
     *   dans $ventilations pour EcritureGenerator ;
     * - ne recalcule/ré-enregistre debit/credit qu'en repli défensif, si un caller
     *   n'a pas encore posé ces valeurs (compte_id présent mais debit=credit=0).
     *
     * Skip silencieux si :
     * - tiers_id est null sur la Tx (saisie libre sans tiers)
     * - une ligne n'a pas de compte_id
     * - le compte résolu n'a pas la bonne classe (6 ou 7 selon le type de Tx)
     *
     * Cas hors périmètre (DONE_WITH_CONCERNS historique, toujours valable) :
     * - Transactions liées à une facture validée → traitées par FactureService
     * - Transactions liées à une remise bancaire → traitées par RemiseBancaireService
     *
     * @param  Transaction  $transaction  Transaction créée (header + lignes déjà en base)
     * @param  TransactionLigne[]  $lignesCreees  Lignes de ventilation fraîchement créées
     */
    private function enrichirPartieDouble(Transaction $transaction, array $lignesCreees): void
    {
        // --- Skip si tiers_id absent ---
        if ($transaction->tiers_id === null) {
            Log::info('[PartieDouble][TransactionService] — skip : tiers_id null', [
                'transaction_id' => $transaction->id,
                'association_id' => TenantContext::currentId(),
            ]);

            return;
        }

        /** @var Tiers $tiers */
        // Fix #3 — tiers_id est une FK validée par contrainte DB : si null check précède et
        // si on arrive ici c'est que tiers_id est non-null. Un findOrFail garantit que la
        // stack trace est utile (corruption tenant ou bug logique) et laisse le DB::transaction
        // englobant rollback proprement.
        $tiers = Tiers::findOrFail($transaction->tiers_id);

        // --- Validation des ventilations (compte_id déjà posé sur la ligne) ---
        $classeAttendue = $transaction->type === TypeTransaction::Recette ? 7 : 6;
        $ventilations = [];
        $skipDoubleEcriture = false;

        foreach ($lignesCreees as $ligne) {
            if ($ligne->compte_id === null) {
                // Ligne sans compte_id (ex. ajout manuel, caller non encore migré) — skip total
                Log::info('[PartieDouble][TransactionService] — skip : ligne sans compte_id', [
                    'transaction_id' => $transaction->id,
                    'transaction_ligne_id' => $ligne->id,
                ]);
                $skipDoubleEcriture = true;
                break;
            }

            $compte = Compte::find((int) $ligne->compte_id);

            if ($compte === null || (int) $compte->classe !== $classeAttendue) {
                Log::warning('[PartieDouble][TransactionService] — skip : compte_id porté par la ligne invalide', [
                    'transaction_id' => $transaction->id,
                    'transaction_ligne_id' => $ligne->id,
                    'compte_id' => $ligne->compte_id,
                    'classe_attendue' => $classeAttendue,
                ]);
                $skipDoubleEcriture = true;
                break;
            }

            // Idempotence : si la ligne porte déjà debit/credit > 0 (posé à la création
            // par create()/update()), ne pas la ré-enregistrer — juste la collecter.
            $debitActuel = (float) $ligne->debit;
            $creditActuel = (float) $ligne->credit;

            if ($debitActuel <= 0.0 && $creditActuel <= 0.0) {
                // Repli défensif — caller n'ayant pas encore posé debit/credit.
                $montant = (float) $ligne->montant;
                $debit = $transaction->type === TypeTransaction::Depense ? $montant : 0.0;
                $credit = $transaction->type === TypeTransaction::Recette ? $montant : 0.0;

                // Fix #1 — passer par le chemin Eloquent (fill + save) pour déclencher
                // l'observer 'saving' et activer l'invariant XOR de TransactionLigneObserver.
                // update() Query Builder bypasse silencieusement les observers Eloquent.
                $ligne->fill([
                    'compte_id' => $compte->id,
                    'debit' => $debit,
                    'credit' => $credit,
                ])->save();
            }

            $ventilations[] = [
                'compte' => $compte,
                'montant' => (float) $ligne->montant,
                'operation_id' => $ligne->operation_id,
                'seance' => $ligne->seance,
                'notes' => $ligne->notes,
            ];
        }

        if ($skipDoubleEcriture || empty($ventilations)) {
            return;
        }

        // --- Résolution du compte de trésorerie (CompteBancaire → Compte 512X via IBAN) ---
        // Délégué à CompteTresorerieResolver (Step 24, rule-of-three — 3ème caller).
        // Voir CompteTresorerieResolver::resoudre() pour le détail des gardes et l'asymétrie chèque.
        $modePaiement = $transaction->mode_paiement;  // ModePaiement|null (castée)

        // Mode comptant (paiement présent) → on a besoin d'un compteTresorerie
        $compteTresorerie = null;
        if ($modePaiement !== null) {
            $sens = $transaction->type === TypeTransaction::Depense ? Sens::Depense : Sens::Recette;

            $compteTresorerie = CompteTresorerieResolver::resoudre(
                compteBancaireId: $transaction->compte_id !== null ? (int) $transaction->compte_id : null,
                mode: $modePaiement,
                contextLog: 'TransactionService',
                sens: $sens,
            );

            if ($compteTresorerie === null) {
                // Skip loggué par CompteTresorerieResolver
                return;
            }
        }

        // --- Date de la transaction ---
        $date = $transaction->date instanceof \DateTimeInterface
            ? $transaction->date
            : new \DateTimeImmutable((string) $transaction->date);

        // --- Appel EcritureGenerator avec la Transaction existante ---
        if ($transaction->type === TypeTransaction::Recette) {
            if ($modePaiement !== null) {
                // Recette comptant — chantier 2a (2026-06-04) :
                // On ne passe plus par pourRecetteComptant() (écriture lumpée 1 Tx, 4 lignes).
                // On produit à la place le chemin "créance puis encaissement" :
                //   1. pourRecetteACredit()       → T1 enrichie (411 D / 7xx C, journal=Vente)
                //   2. pourEncaissementCreance()   → T2 séparée créée (portage D / 411 C, journal=Banque)
                //                                    + auto-lettrage 411 T1 ↔ T2
                // Résultat : même structure qu'une créance + Marquer reçu. Backfill (TransactionConverter)
                // conserve intentionnellement pourRecetteComptant() — chantier 2b différé.
                $this->ecritureGenerator->pourRecetteACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $transaction->libelle,
                    existingTransaction: $transaction,
                );

                // T1 est désormais enrichie (411 D / 7xx C). Générer T2 d'encaissement.
                $libelleEncaissement = 'Encaissement '.$transaction->libelle;
                $this->ecritureGenerator->pourEncaissementCreance(
                    transactionCreance: $transaction,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    datePaiement: $date,
                    libelle: $libelleEncaissement,
                );
            } else {
                // Recette à crédit
                $this->ecritureGenerator->pourRecetteACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $transaction->libelle,
                    existingTransaction: $transaction,
                );
            }
        } else {
            // TypeTransaction::Depense
            if ($modePaiement !== null) {
                // Dépense comptant — chantier 3a-i (2026-06-04) :
                // On ne passe plus par pourDepenseComptant() (écriture lumpée 1 Tx, 4 lignes).
                // On produit à la place le chemin "dette puis règlement" :
                //   1. pourDepenseACredit()         → T1 enrichie (60x D / 401 C, journal=Achat)
                //   2. pourReglementFournisseur()    → T2 séparée créée (401 D / 512X C, journal=Banque)
                //                                     + auto-lettrage 401 T1 ↔ T2
                // Résultat : même structure qu'une dette + Marquer payé. Backfill (TransactionConverter)
                // conserve intentionnellement pourDepenseComptant() — chantier 3b différé.
                $this->ecritureGenerator->pourDepenseACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $transaction->libelle,
                    existingTransaction: $transaction,
                );

                // T1 est désormais enrichie (60x D / 401 C). Générer T2 de règlement.
                $libelleReglement = 'Règlement '.$transaction->libelle;
                $this->ecritureGenerator->pourReglementFournisseur(
                    transactionDette: $transaction,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    datePaiement: $date,
                    libelle: $libelleReglement,
                );
            } else {
                // Dépense à crédit
                $this->ecritureGenerator->pourDepenseACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $transaction->libelle,
                    existingTransaction: $transaction,
                );
            }
        }

        // L'enrichissement partie double a réussi (les générateurs assertent l'équilibre
        // avant de retourner). On marque donc la transaction comme équilibrée — sinon elle
        // reste à `equilibree=false` (défaut colonne) et apparaît à tort « déséquilibrée »
        // dans SmokeTestV5Command / BackfillAuditor pour toute saisie au formulaire.
        $transaction->forceFill(['equilibree' => true])->save();
        // G.2 — garde de sécurité : vérifie que les lignes PD sont cohérentes après
        // marquage equilibree=true. Placé ici (et non après enrichirPartieDouble dans les
        // callers) pour ne pas déclencher sur les skip gracieux (tiers_id null, code_cerfa
        // manquant, compte_id null) où PD n'est tout simplement pas applicable.
        PartieDoubleGuard::assertComplete($transaction->fresh());
    }

    /**
     * Complète un tableau de ligne (contrat compte_id, DC-10a) avec debit/credit
     * calculés à la création — recette → credit=montant/debit=0, dépense →
     * debit=montant/credit=0 — de sorte que l'invariant XOR de
     * TransactionLigneObserver soit satisfait dès l'insertion, sans dépendre d'un
     * enrichissement a posteriori.
     *
     * Si compte_id est absent (ligne sans mapping — cas légitime : ventilation
     * non encore résolue, ajout manuel), debit/credit restent à 0 : l'observer
     * n'applique pas l'invariant XOR sur les lignes sans compte_id.
     *
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private function avecDebitCredit(array $ligne, TypeTransaction $type): array
    {
        if (! isset($ligne['compte_id']) || $ligne['compte_id'] === null || $ligne['compte_id'] === '') {
            return $ligne;
        }

        $montant = (float) ($ligne['montant'] ?? 0);
        $ligne['debit'] = $type === TypeTransaction::Depense ? $montant : 0.0;
        $ligne['credit'] = $type === TypeTransaction::Recette ? $montant : 0.0;

        return $ligne;
    }

    public function update(Transaction $transaction, array $data, array $lignes): Transaction
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        $this->validateInscriptionRequiresOperation($lignes);

        return DB::transaction(function () use ($transaction, $data, $lignes) {
            $transaction->load(['rapprochement' => fn ($q) => $q->lockForUpdate()]);

            if ($transaction->isLockedByRemise()) {
                throw new \RuntimeException('Cette transaction est liée à une remise bancaire et ne peut pas être modifiée.');
            }

            if ($transaction->isLockedByFacture()) {
                $this->assertLockedByFactureInvariants($transaction, $data, $lignes);
            }

            if ($transaction->isLockedByRapprochement()) {
                $this->assertLockedInvariants($transaction, $data, $lignes);
            }

            // Réversion encaissement : recette « reçue » (mode présent) repassée « non reçue »
            // (mode null) → supprimer la T2 d'encaissement séparée, sinon elle reste orpheline
            // (chèque fantôme en 5112). Fait AVANT update + délettrage (le 411 est encore lettré).
            $this->annulerEncaissementSiReversion($transaction, $data);

            // Réversion règlement : dépense « réglée » (mode présent) repassée « non payée »
            // (mode null) → supprimer la T2 de règlement séparée, sinon elle reste orpheline
            // (décaissement fantôme en 512X). Fait AVANT update + délettrage (le 401 est encore lettré).
            $this->annulerReglementSiReversion($transaction, $data);

            $transaction->update($data);

            if ($transaction->isLockedByFacture()) {
                foreach ($lignes as $ligneData) {
                    $transaction->lignes()->where('id', $ligneData['id'])->update([
                        'notes' => $ligneData['notes'],
                    ]);
                }
            } elseif ($transaction->isLockedByRapprochement()) {
                // DC-10a — compte_id arrive pré-résolu dans $ligneData (contrat) : plus besoin
                // de résoudre compte_id → compte via un resolver dédié (l'ancienne
                // méthode patcherComptesVentilationRapproLocked est supprimée). Si compte_id
                // change, on recalcule debit/credit au passage — le montant reste gelé (pièce
                // rapprochée), seule la ventilation (compte) peut changer.
                foreach ($lignes as $ligneData) {
                    $ligneExistante = $transaction->lignes()->where('id', $ligneData['id'])->first();

                    $update = [
                        'compte_id' => $ligneData['compte_id'],
                        'operation_id' => $ligneData['operation_id'],
                        'seance' => $ligneData['seance'],
                        'notes' => $ligneData['notes'],
                    ];

                    $compteIdInchange = $ligneExistante !== null
                        && (string) $ligneExistante->compte_id === (string) $ligneData['compte_id'];

                    if (! $compteIdInchange && $ligneData['compte_id'] !== null && $ligneData['compte_id'] !== '') {
                        $montant = (float) ($ligneExistante->montant ?? $ligneData['montant'] ?? 0);
                        $update['debit'] = $transaction->type === TypeTransaction::Depense ? $montant : 0.0;
                        $update['credit'] = $transaction->type === TypeTransaction::Recette ? $montant : 0.0;
                    }

                    $transaction->lignes()->where('id', $ligneData['id'])->update($update);
                }
            } else {
                $affectationsSnapshot = [];
                $helloAssoItemIds = [];
                foreach ($lignes as $ligneData) {
                    $oldId = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null) {
                        $existingLigne = $transaction->lignes()->where('id', $oldId)->first();
                        if ($existingLigne === null) {
                            continue;
                        }
                        if ($existingLigne->helloasso_item_id !== null) {
                            $helloAssoItemIds[$oldId] = $existingLigne->helloasso_item_id;
                        }
                        $oldCents = (int) round((float) $existingLigne->montant * 100);
                        $newCents = (int) round((float) $ligneData['montant'] * 100);
                        if ($oldCents !== $newCents) {
                            continue;
                        }
                        $aff = $existingLigne->affectations()->get();
                        if ($aff->isNotEmpty()) {
                            $affectationsSnapshot[$oldId] = $aff->map(fn ($a) => [
                                'operation_id' => $a->operation_id,
                                'seance' => $a->seance,
                                'montant' => $a->montant,
                                'notes' => $a->notes,
                            ])->toArray();
                        }
                    }
                }
                // Suppression T2 AVANT délettrage : trouverEncaissementT2/trouverReglementT2
                // s'appuient sur le lettrage_code présent pour identifier la T2 associée.
                // Si on délettre d'abord, la T2 devient introuvable et survive comme zombie.
                // Fait ici pour les cas où mode_paiement reste non-null (montant ou mode change)
                // — les cas mode→null sont gérés par annulerEncaissementSiReversion (ci-dessus).
                $this->supprimerT2SiExiste($transaction);

                // Auto-délettrage des lignes lettrées AVANT forceDelete (pattern Step 31 extourne).
                // forceDelete() détruirait silencieusement toutes les lignes — y compris les 411
                // lettrées — laissant le code de lettrage orphelin sur la ligne paire d'une autre
                // transaction (cross-tx) ou corrompant l'audit (paire interne).
                // delettrerParLigne() délettre le GROUPE ENTIER portant le même code.
                $this->autoDelettrerLignesAvantUpdate($transaction);

                $transaction->lignes()->forceDelete();
                $lignesCreees = [];
                foreach ($lignes as $ligneData) {
                    $oldId = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null && isset($helloAssoItemIds[$oldId])) {
                        $ligneData['helloasso_item_id'] = $helloAssoItemIds[$oldId];
                    }
                    // DC-10a — compte_id connu à la création : debit/credit calculés ici même
                    // (voir avecDebitCredit / design decision), plutôt qu'enrichis a posteriori.
                    $newLigne = $transaction->lignes()->create(
                        $this->avecDebitCredit($ligneData, $transaction->type)
                    );
                    $lignesCreees[] = $newLigne;
                    if ($oldId !== null && isset($affectationsSnapshot[$oldId])) {
                        foreach ($affectationsSnapshot[$oldId] as $affData) {
                            $newLigne->affectations()->create($affData);
                        }
                    }
                }

                // Step 31 — Re-enrichissement partie double après recréation des lignes legacy.
                // forceDelete() ci-dessus détruit TOUTES les lignes (legacy + PD-only).
                // On doit re-générer les écritures PD sur la transaction fraîche.
                // enrichirPartieDouble() est idempotent sur la transaction si les lignes legacy
                // viennent d'être créées et que les PD-only n'existent pas encore.
                $transaction->refresh();
                $this->enrichirPartieDouble($transaction, $lignesCreees);
            }

            // Chantier 4 — recalcul du statut miroir depuis le ledger (couvre la
            // réversion reçu→non-reçu : le 411/401 redevient non lettré → EnAttente).
            $this->etatReglementResolver->syncer($transaction->fresh());

            return $transaction->fresh();
        });
    }

    /**
     * Si une recette « reçue » (mode_paiement présent) est repassée « non reçue » (mode null)
     * à l'édition, supprime la T2 d'encaissement SÉPARÉE — sinon elle reste orpheline (chèque
     * fantôme en 5112). Le cas lumpé (encaissement sur la même transaction) est nettoyé par le
     * forceDelete des lignes dans update(). À appeler AVANT update()/délettrage (411 lettré).
     */
    private function annulerEncaissementSiReversion(Transaction $transaction, array $data): void
    {
        if ($transaction->type !== TypeTransaction::Recette) {
            return;
        }
        // Réversion = avait un mode (reçue) → passe à null (non reçue)
        if ($transaction->mode_paiement === null || ($data['mode_paiement'] ?? null) !== null) {
            return;
        }

        $t2 = app(ReglementOperationService::class)->trouverEncaissementT2($transaction);
        if ($t2 === null) {
            return; // cas lumpé : nettoyé par le forceDelete des lignes dans update()
        }

        // Délettre les lignes lettrées de la T2 (411 ↔ T1) puis supprimer la T2.
        foreach (TransactionLigne::where('transaction_id', $t2->id)->whereNotNull('lettrage_code')->get() as $ligne) {
            $this->lettrageService->delettrerParLigne(
                $ligne->fresh(),
                "Annulation encaissement (recette repassée non reçue) — T2 #{$t2->id}"
            );
        }
        TransactionLigne::where('transaction_id', $t2->id)->forceDelete();
        $t2->forceDelete();
    }

    /**
     * Si une dépense « réglée » (mode_paiement présent) est repassée « non payée » (mode null)
     * à l'édition, supprime la T2 de règlement SÉPARÉE — sinon elle reste orpheline (décaissement
     * fantôme en 512X). Le cas lumpé (règlement sur la même transaction) est nettoyé par le
     * forceDelete des lignes dans update(). À appeler AVANT update()/délettrage (401 lettré).
     *
     * Miroir de annulerEncaissementSiReversion() pour les dépenses (compte 401 au lieu de 411).
     */
    private function annulerReglementSiReversion(Transaction $transaction, array $data): void
    {
        if ($transaction->type !== TypeTransaction::Depense) {
            return;
        }
        // Réversion = avait un mode (réglée) → passe à null (non payée)
        if ($transaction->mode_paiement === null || ($data['mode_paiement'] ?? null) !== null) {
            return;
        }

        $t2 = app(ReglementOperationService::class)->trouverReglementT2($transaction);
        if ($t2 === null) {
            return; // cas lumpé : nettoyé par le forceDelete des lignes dans update()
        }

        // Délettre les lignes lettrées de la T2 (401 ↔ T1) puis supprimer la T2.
        foreach (TransactionLigne::where('transaction_id', $t2->id)->whereNotNull('lettrage_code')->get() as $ligne) {
            $this->lettrageService->delettrerParLigne(
                $ligne->fresh(),
                "Annulation règlement (dépense repassée non payée) — T2 #{$t2->id}"
            );
        }
        TransactionLigne::where('transaction_id', $t2->id)->forceDelete();
        $t2->forceDelete();
    }

    /**
     * Met à jour les champs bancaires/opérationnels d'un miroir extourne.
     *
     * Les lignes comptables (écritures PD) sont figées — pas de forceDelete/recreate.
     * Seuls date, mode_paiement, compte_id, notes, reference, libelle sont modifiables.
     * Si le mode/compte change et qu'un T2 existe, il est recréé avec les nouveaux paramètres.
     */
    public function updateExtourneMiroir(Transaction $transaction, array $data): Transaction
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        return DB::transaction(function () use ($transaction, $data): Transaction {
            if ($transaction->isLockedByRemise()) {
                throw new \RuntimeException('Cette transaction est liée à une remise bancaire et ne peut pas être modifiée.');
            }

            if ($transaction->isLockedByRapprochement()) {
                throw new \RuntimeException('Cette transaction est pointée dans un rapprochement et ne peut pas être modifiée.');
            }

            $ancienMode = $transaction->mode_paiement;
            $nouveauMode = $data['mode_paiement'] ?? null;
            $ancienCompteId = $transaction->compte_id;
            $nouveauCompteId = $data['compte_id'] ?? null;

            $modeChange = ($ancienMode?->value ?? null) !== ($nouveauMode instanceof ModePaiement ? $nouveauMode->value : $nouveauMode);
            $compteChange = (string) $ancienCompteId !== (string) $nouveauCompteId;

            $avaitT2 = $ancienMode !== null;
            $doitAvoirT2 = $nouveauMode !== null;

            // Suppression T2 existant si le mode/compte change ou si passage à non-payé.
            // Délettrage explicite des lignes T2 AVANT suppression (supprimerT2SiExiste
            // ne délettre pas — dans le chemin normal, autoDelettrerLignesAvantUpdate s'en charge).
            if ($avaitT2 && ($modeChange || $compteChange || ! $doitAvoirT2)) {
                $reglementService = app(ReglementOperationService::class);
                $t2 = $reglementService->trouverT2($transaction);

                if ($t2 !== null) {
                    foreach (TransactionLigne::where('transaction_id', (int) $t2->id)->whereNotNull('lettrage_code')->get() as $ligne) {
                        $this->lettrageService->delettrerParLigne(
                            $ligne->fresh(),
                            "Suppression T2 miroir extourne — mode/compte changé sur T1 #{$transaction->id}"
                        );
                    }
                    TransactionLigne::where('transaction_id', (int) $t2->id)->forceDelete();
                    $t2->forceDelete();
                }
            }

            $transaction->update([
                'date' => $data['date'],
                'libelle' => $data['libelle'] ?? $transaction->libelle,
                'mode_paiement' => $nouveauMode,
                'compte_id' => $nouveauCompteId,
                'notes' => $data['notes'] ?? null,
                'reference' => $data['reference'] ?? null,
            ]);

            // Recréation T2 si nécessaire (nouveau mode ou mode/compte changé)
            if ($doitAvoirT2 && ($modeChange || $compteChange || ! $avaitT2)) {
                app(ReglementOperationService::class)->reglerOuEncaisser($transaction->fresh());
            }

            $this->etatReglementResolver->syncer($transaction->fresh());

            return $transaction->fresh();
        });
    }

    public function delete(Transaction $transaction): void
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
        );

        if ($transaction->rapprochement_id !== null) {
            throw new \RuntimeException('Cette transaction est pointée dans un rapprochement et ne peut pas être supprimée.');
        }
        if ($transaction->isLockedByRemise()) {
            throw new \RuntimeException('Cette transaction est liée à une remise bancaire et ne peut pas être supprimée.');
        }
        if ($transaction->isLockedByFacture()) {
            throw new \RuntimeException('Cette transaction est liée à une facture validée et ne peut pas être supprimée.');
        }
        DB::transaction(function () use ($transaction) {
            // Nettoyage T2 (encaissement/règlement séparé) si elle existe — symétrique
            // avec annuler() : sans ça, supprimer une transaction réglée laisserait une
            // T2 orpheline et un lettrage pendant dans le grand livre.
            $this->supprimerT2SiExiste($transaction);

            // Supprimer la pièce jointe si présente
            if ($transaction->hasPieceJointe()) {
                $this->deletePieceJointe($transaction);
            }

            $transaction->lignes()->each(function (TransactionLigne $ligne) {
                $ligne->affectations()->delete();
                $ligne->delete();
            });
            $transaction->delete();
        });
    }

    /**
     * Annule une transaction Dû ou En main (soft-delete avec motif).
     *
     * Délettrage PD des lignes de la T1, nettoyage de la T2 si elle existe,
     * puis soft-delete avec traçabilité (motif + user). La transaction disparaît
     * de tous les rapports et listings via le scope global SoftDeletes.
     *
     * Pour les transactions Remis/Pointé → utiliser TransactionExtourneService::extourner().
     */
    public function annuler(Transaction $transaction, string $motif): void
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
        );

        if ($transaction->rapprochement_id !== null) {
            throw new \RuntimeException('Cette transaction est pointée dans un rapprochement et ne peut pas être annulée.');
        }
        if ($transaction->isLockedByRemise()) {
            throw new \RuntimeException('Cette transaction est liée à une remise bancaire et ne peut pas être annulée.');
        }
        if ($transaction->isLockedByFacture()) {
            throw new \RuntimeException('Cette transaction est liée à une facture validée et ne peut pas être annulée.');
        }
        if ($transaction->extournee_at !== null) {
            throw new \RuntimeException('Cette transaction a déjà été extournée.');
        }

        DB::transaction(function () use ($transaction, $motif) {
            // 1. Nettoyage T2 (encaissement/règlement séparé) si elle existe
            $this->supprimerT2SiExiste($transaction);

            // 2. Supprimer la pièce jointe si présente
            if ($transaction->hasPieceJointe()) {
                $this->deletePieceJointe($transaction);
            }

            // 3. Soft-delete lignes
            $transaction->lignes()->each(function (TransactionLigne $ligne) {
                $ligne->affectations()->delete();
                $ligne->delete();
            });

            // 4. Soft-delete TX avec traçabilité
            $transaction->forceFill([
                'motif_suppression' => $motif,
                'supprime_par' => auth()->id(),
            ])->save();
            $transaction->delete();

            Log::info('Transaction annulée (soft-delete)', [
                'association_id' => TenantContext::currentId(),
                'user_id' => (int) auth()->id(),
                'transaction_id' => $transaction->id,
                'motif' => $motif,
            ]);
        });
    }

    /**
     * Supprime la T2 (encaissement ou règlement séparé) liée à cette T1.
     * No-op si pas de T2 ou encaissement lumpé.
     *
     * Pas de délettrage : la T1 est soft-deleted et la T2 force-deleted,
     * les deux disparaissent des rapports. Les lettrage_code orphelins
     * sur les lignes supprimées sont inoffensifs.
     */
    private function supprimerT2SiExiste(Transaction $transaction): void
    {
        $reglementService = app(ReglementOperationService::class);

        $t2 = $reglementService->trouverT2($transaction);

        if ($t2 === null) {
            return;
        }

        TransactionLigne::where('transaction_id', (int) $t2->id)->forceDelete();
        $t2->forceDelete();
    }

    public function affecterLigne(TransactionLigne $ligne, array $affectations): void
    {
        $transaction = $ligne->transaction;
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
        );

        if ($transaction->isLockedByFacture()) {
            throw new \RuntimeException('Cette transaction est liée à une facture validée. La ventilation ne peut pas être modifiée.');
        }

        DB::transaction(function () use ($ligne, $affectations) {
            if (count($affectations) === 0) {
                throw new \RuntimeException('La liste des affectations ne peut pas être vide.');
            }
            $total = 0;
            foreach ($affectations as $a) {
                if ((int) round((float) ($a['montant'] ?? 0) * 100) <= 0) {
                    throw new \RuntimeException('Chaque affectation doit avoir un montant positif.');
                }
                $total += (int) round((float) $a['montant'] * 100);
            }
            $attendu = (int) round((float) $ligne->montant * 100);
            if ($total !== $attendu) {
                throw new \RuntimeException(
                    "La somme des affectations ({$total} centimes) ne correspond pas au montant de la ligne ({$attendu} centimes)."
                );
            }
            $ligne->affectations()->delete();
            foreach ($affectations as $a) {
                $ligne->affectations()->create([
                    'operation_id' => $a['operation_id'] ?: null,
                    'seance' => $a['seance'] ?: null,
                    'montant' => $a['montant'],
                    'notes' => $a['notes'] ?: null,
                ]);
            }
        });
    }

    public function supprimerAffectations(TransactionLigne $ligne): void
    {
        $transaction = $ligne->transaction;
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
        );

        if ($transaction->isLockedByFacture()) {
            throw new \RuntimeException('Cette transaction est liée à une facture validée. La ventilation ne peut pas être modifiée.');
        }

        DB::transaction(fn () => $ligne->affectations()->delete());
    }

    public function storePieceJointe(Transaction $transaction, UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé : '.$mime);
        }

        if ($transaction->piece_jointe_path !== null) {
            Storage::disk('local')->deleteDirectory(
                $transaction->storagePath('transactions/'.$transaction->id)
            );
        }

        $extension = $file->guessExtension() ?? 'bin';
        $shortName = "justificatif.{$extension}";
        $fullPath = $transaction->storagePath('transactions/'.$transaction->id.'/'.$shortName);
        $file->storeAs(
            $transaction->storagePath('transactions/'.$transaction->id),
            $shortName,
            'local'
        );

        $transaction->update([
            'piece_jointe_path' => $shortName,
            'piece_jointe_nom' => $file->getClientOriginalName(),
            'piece_jointe_mime' => $mime,
        ]);
    }

    public function storePieceJointeFromPath(
        Transaction $transaction,
        string $sourcePath,
        string $originalFilename,
        string $mime,
    ): void {
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé : '.$mime);
        }

        if (! file_exists($sourcePath)) {
            throw new \InvalidArgumentException('Fichier source introuvable : '.$sourcePath);
        }

        if ($transaction->piece_jointe_path !== null) {
            Storage::disk('local')->deleteDirectory(
                $transaction->storagePath('transactions/'.$transaction->id)
            );
        }

        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'bin';
        $shortName = "justificatif.{$extension}";
        $fullPath = $transaction->storagePath('transactions/'.$transaction->id.'/'.$shortName);

        Storage::disk('local')->put($fullPath, file_get_contents($sourcePath));

        $transaction->update([
            'piece_jointe_path' => $shortName,
            'piece_jointe_nom' => $originalFilename,
            'piece_jointe_mime' => $mime,
        ]);
    }

    public function deletePieceJointe(Transaction $transaction): void
    {
        if ($transaction->piece_jointe_path === null) {
            return;
        }

        Storage::disk('local')->deleteDirectory(
            $transaction->storagePath('transactions/'.$transaction->id)
        );

        $transaction->update([
            'piece_jointe_path' => null,
            'piece_jointe_nom' => null,
            'piece_jointe_mime' => null,
        ]);
    }

    private function validateInscriptionRequiresOperation(array $lignes): void
    {
        $inscriptionCompteIds = Compte::forUsage(UsageComptable::Inscription)
            ->pluck('id')
            ->toArray();

        foreach ($lignes as $index => $ligne) {
            // Ligne sans compte_id (ventilation absente) : jamais une inscription.
            if (! isset($ligne['compte_id']) || $ligne['compte_id'] === null || $ligne['compte_id'] === '') {
                continue;
            }

            if (in_array((int) $ligne['compte_id'], $inscriptionCompteIds, true)
                && empty($ligne['operation_id'])) {
                throw new \InvalidArgumentException(
                    "La ligne {$index} utilise un compte d'inscription : operation_id est obligatoire."
                );
            }
        }
    }

    private function assertLockedByFactureInvariants(Transaction $transaction, array $data, array $lignes): void
    {
        if ((int) round((float) $transaction->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une transaction facturée.');
        }
        // ventilation() : exclut les lignes PD-only (411/401/512X) ajoutées par EcritureGenerator.
        // Le form n'envoie que les lignes métier (classe 6/7).
        $existingLignes = $transaction->lignes()->ventilation()->get()->keyBy('id');
        if (count($lignes) !== $existingLignes->count()) {
            throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une transaction facturée.');
        }
        foreach ($lignes as $ligneData) {
            $id = $ligneData['id'] ?? null;
            if ($id === null || ! $existingLignes->has($id)) {
                throw new \RuntimeException('Ligne inconnue sur une transaction facturée.');
            }
            $existing = $existingLignes->get($id);
            if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
                throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une transaction facturée.');
            }
            if ((int) $existing->compte_id !== (int) $ligneData['compte_id']) {
                throw new \RuntimeException('La compte ne peut pas être modifiée sur une transaction facturée.');
            }
            $existingOpId = $existing->operation_id;
            $newOpId = $ligneData['operation_id'] !== '' && $ligneData['operation_id'] !== null ? (int) $ligneData['operation_id'] : null;
            if ($existingOpId !== $newOpId) {
                throw new \RuntimeException('L\'opération ne peut pas être modifiée sur une transaction facturée.');
            }
            $existingSeance = $existing->seance;
            $newSeance = isset($ligneData['seance']) && $ligneData['seance'] !== '' && $ligneData['seance'] !== null ? (int) $ligneData['seance'] : null;
            if ($existingSeance !== $newSeance) {
                throw new \RuntimeException('La séance ne peut pas être modifiée sur une transaction facturée.');
            }
        }
    }

    private function assertLockedInvariants(Transaction $transaction, array $data, array $lignes): void
    {
        if ($transaction->date->format('Y-m-d') !== $data['date']) {
            throw new \RuntimeException('La date ne peut pas être modifiée sur une transaction rapprochée.');
        }
        if ((int) $transaction->compte_id !== (int) $data['compte_id']) {
            throw new \RuntimeException('Le compte bancaire ne peut pas être modifié sur une transaction rapprochée.');
        }
        if ((int) round((float) $transaction->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une transaction rapprochée.');
        }
        // ventilation() : exclut les lignes PD-only (411/401/512X) ajoutées par EcritureGenerator.
        // Le form n'envoie que les lignes métier (classe 6/7).
        $existingLignes = $transaction->lignes()->ventilation()->get()->keyBy('id');
        if (count($lignes) !== $existingLignes->count()) {
            throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une transaction rapprochée.');
        }
        foreach ($lignes as $ligneData) {
            $id = $ligneData['id'] ?? null;
            if ($id === null || ! $existingLignes->has($id)) {
                throw new \RuntimeException('Ligne inconnue ou sans identifiant sur une transaction rapprochée.');
            }
            $existing = $existingLignes->get($id);
            if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
                throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une transaction rapprochée.');
            }
        }
    }

    /**
     * Auto-délettrage des lignes lettrées AVANT forceDelete() dans la branche libre de update().
     *
     * Délègue à LettrageService::autoDelettrerLignesDe (rule-of-three — Vague 3b).
     * Cette méthode NE concerne PAS les branches Rappro-locked ni Facture-locked :
     * celles-ci font un patch ciblé (montants gelés) sans jamais toucher au lettrage.
     */
    private function autoDelettrerLignesAvantUpdate(Transaction $transaction): void
    {
        $motif = "Auto-délettrage suite à update de TX#{$transaction->id}";
        $this->lettrageService->autoDelettrerLignesDe($transaction, $motif);
    }
}
