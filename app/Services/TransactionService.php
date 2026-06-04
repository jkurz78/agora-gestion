<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\CompteTresorerieResolver;
use App\Services\Compta\CompteVentilationResolver;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\LettrageService;
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

            // Créer les lignes legacy et les enrichir avec les colonnes partie double
            $lignesCreees = [];
            foreach ($lignes as $ligne) {
                $ligneCreee = $transaction->lignes()->create($ligne);
                $lignesCreees[] = $ligneCreee;
            }

            // Enrichissement partie double : double écriture vers EcritureGenerator
            // Step 21 — branchement systématique (pas de feature flag)
            $this->enrichirPartieDouble($transaction, $lignesCreees);

            return $transaction;
        });
    }

    /**
     * Enrichit une transaction créée via le formulaire legacy en générant les écritures
     * partie double (école 411 systématique, spec §4.3 amendée 2026-05-22).
     *
     * Stratégie de coexistence (Step 21) :
     * - Les lignes legacy (sous_categorie_id + montant) sont conservées et enrichies
     *   en place avec compte_id / debit / credit correspondants.
     * - Les lignes PD-only (411/401 D, portage, 411/401 C) sont ajoutées sur la même Tx.
     * - Les rapports existants (CompteResultatBuilder, RapprochementBancaireService) lisent
     *   toujours sous_categorie_id + montant — ils basculeront sur compte_id au Step 27.
     *
     * Skip silencieux si :
     * - tiers_id est null sur la Tx (saisie libre sans tiers)
     * - une ventilation n'a pas de code_cerfa (sous-catégorie mal configurée)
     * - le compte résolu n'a pas la bonne classe (6 ou 7 selon le type de Tx)
     *
     * Cas hors périmètre Step 21 (DONE_WITH_CONCERNS) :
     * - Transactions liées à une facture validée → Step 23
     * - Transactions liées à une remise bancaire → Step 25
     * - update() → Step ultérieur
     *
     * @param  Transaction  $transaction  Transaction créée (header + lignes legacy déjà en base)
     * @param  TransactionLigne[]  $lignesCreees  Lignes legacy fraîchement créées
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

        // --- Résolution des ventilations (sous_categorie → Compte) ---
        $classeAttendue = $transaction->type === TypeTransaction::Recette ? 7 : 6;
        $ventilations = [];
        $skipDoubleEcriture = false;

        foreach ($lignesCreees as $ligne) {
            $sousCatId = $ligne->sous_categorie_id;

            if ($sousCatId === null) {
                // Ligne sans sous-catégorie (ex. ajout manuel) — skip total
                Log::info('[PartieDouble][TransactionService] — skip : ligne sans sous_categorie_id', [
                    'transaction_id' => $transaction->id,
                    'transaction_ligne_id' => $ligne->id,
                ]);
                $skipDoubleEcriture = true;
                break;
            }

            // Résolution sous_categorie → Compte (classe 6 ou 7) via CompteVentilationResolver.
            $compte = CompteVentilationResolver::resoudre(
                sousCategorieId: (int) $sousCatId,
                classeAttendue: $classeAttendue,
                contextLog: 'TransactionService',
                contextLogData: ['transaction_id' => $transaction->id],
            );

            if ($compte === null) {
                $skipDoubleEcriture = true;
                break;
            }

            // Enrichir la ligne legacy avec compte_id + debit/credit partie double
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

            $ventilations[] = [
                'compte' => $compte,
                'montant' => $montant,
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

            $transaction->update($data);

            if ($transaction->isLockedByFacture()) {
                foreach ($lignes as $ligneData) {
                    $transaction->lignes()->where('id', $ligneData['id'])->update([
                        'notes' => $ligneData['notes'],
                    ]);
                }
            } elseif ($transaction->isLockedByRapprochement()) {
                foreach ($lignes as $ligneData) {
                    $transaction->lignes()->where('id', $ligneData['id'])->update([
                        'sous_categorie_id' => $ligneData['sous_categorie_id'],
                        'operation_id' => $ligneData['operation_id'],
                        'seance' => $ligneData['seance'],
                        'notes' => $ligneData['notes'],
                    ]);
                }

                // Step 31 — Patch ciblé : si sous_categorie_id a changé sur une ligne de ventilation
                // (identifiée par sous_categorie_id non null ET compte_id de classe 6 ou 7),
                // recalculer le compte_id PD correspondant.
                // Les lignes PD-only (411/401, 512X) sont intactes — montant gelé sur pièce rappro.
                $this->patcherComptesVentilationRapproLocked($transaction, $lignes);
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
                    $newLigne = $transaction->lignes()->create($ligneData);
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
        $inscriptionSousCategorieIds = SousCategorie::forUsage(UsageComptable::Inscription)
            ->pluck('id')
            ->toArray();

        foreach ($lignes as $index => $ligne) {
            if (in_array((int) $ligne['sous_categorie_id'], $inscriptionSousCategorieIds, true)
                && empty($ligne['operation_id'])) {
                throw new \InvalidArgumentException(
                    "La ligne {$index} utilise une sous-catégorie d'inscription : operation_id est obligatoire."
                );
            }
        }
    }

    private function assertLockedByFactureInvariants(Transaction $transaction, array $data, array $lignes): void
    {
        if ((int) round((float) $transaction->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une transaction facturée.');
        }
        $existingLignes = $transaction->lignes()->get()->keyBy('id');
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
            if ((int) $existing->sous_categorie_id !== (int) $ligneData['sous_categorie_id']) {
                throw new \RuntimeException('La sous-catégorie ne peut pas être modifiée sur une transaction facturée.');
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
        $existingLignes = $transaction->lignes()->get()->keyBy('id');
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

    /**
     * Step 31 — Patch ciblé compte_id sur les lignes de ventilation (classe 6 ou 7)
     * d'une transaction Rappro-locked.
     *
     * Après le foreach de mise à jour (sous_categorie_id, operation_id, seance, notes),
     * les lignes de ventilation ont leur sous_categorie_id à jour en base mais leur
     * compte_id PD n'a pas été recalculé. Ce patch résout le nouveau sous_categorie_id
     * vers le compte correspondant et met à jour compte_id.
     *
     * Les lignes PD-only (411/401 lettrées, 512X) sont intentionnellement laissées
     * intactes : montants gelés sur pièce rapprochée, dé-lettrage non souhaité ici.
     * Les lignes de ventilation sont identifiées par sous_categorie_id IS NOT NULL.
     *
     * Skip silencieux si le compte ne peut pas être résolu (sous-catégorie sans code_cerfa,
     * compte introuvable, classe inattendue) — cohérent avec enrichirPartieDouble.
     */
    private function patcherComptesVentilationRapproLocked(Transaction $transaction, array $lignes): void
    {
        $classeAttendue = $transaction->type === TypeTransaction::Recette ? 7 : 6;

        // Collecter les IDs de lignes valides (avec sous_categorie_id non null dans la requête)
        $ids = collect($lignes)
            ->filter(fn ($l) => isset($l['id']) && $l['id'] !== null && isset($l['sous_categorie_id']) && $l['sous_categorie_id'] !== null)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($ids)) {
            return;
        }

        // Précharger toutes les lignes en une seule requête (N+1 fix)
        $lignesDb = $transaction->lignes()
            ->whereIn('id', $ids)
            ->whereNotNull('sous_categorie_id')
            ->get()
            ->keyBy(fn ($l) => (int) $l->id);

        // Indexer les lignesData par id pour accès O(1)
        $lignesDataById = collect($lignes)
            ->filter(fn ($l) => isset($l['id']) && $l['id'] !== null)
            ->keyBy(fn ($l) => (int) $l['id']);

        foreach ($lignesDb as $id => $ligne) {
            $ligneData = $lignesDataById->get($id);
            if ($ligneData === null) {
                continue;
            }

            $sousCatId = isset($ligneData['sous_categorie_id']) && $ligneData['sous_categorie_id'] !== null
                ? (int) $ligneData['sous_categorie_id']
                : null;

            if ($sousCatId === null) {
                continue;
            }

            // Ligne de ventilation identifiée par sous_categorie_id non null.
            // Toujours recalculer compte_id (idempotent, même si sous-cat inchangée).
            $compte = CompteVentilationResolver::resoudre(
                sousCategorieId: $sousCatId,
                classeAttendue: $classeAttendue,
                contextLog: 'TransactionService::patcherComptesVentilationRapproLocked',
                contextLogData: ['transaction_id' => $transaction->id, 'ligne_id' => $id],
            );

            if ($compte !== null) {
                $ligne->fill(['compte_id' => $compte->id])->save();
            }
        }
    }
}
