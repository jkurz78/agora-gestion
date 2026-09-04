<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Exceptions\OcrAnalysisException;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use App\Services\RapprochementMatchingService;
use App\Services\ReglementOperationService;
use App\Services\ReleveOcrService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Component;

final class RapprochementDetail extends Component
{
    use RespectsExerciceCloture;

    public RapprochementBancaire $rapprochement;

    public bool $masquerPointees = false;

    /** @var array<int, bool> */
    public array $expandedRemises = [];

    /** @var array<int, array{date: ?string, libelle: ?string, montant: float}>|null */
    public ?array $mouvementsReleve = null;

    /** @var array<int, array{id: int, type: string, date: string, libelle: ?string, montant_signe: float}>|null */
    public ?array $candidatsMatching = null;

    /** @var array<int, array{transaction_id: int, transaction_type: string}> */
    public array $associationsPointage = [];

    public ?int $mouvementSelectionne = null;

    public bool $matchingEnCours = false;

    public ?string $matchingErreur = null;

    public function mount(RapprochementBancaire $rapprochement): void
    {
        $this->rapprochement = $rapprochement;
    }

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function toggleRemiseExpand(int $remiseId): void
    {
        if (isset($this->expandedRemises[$remiseId])) {
            unset($this->expandedRemises[$remiseId]);
        } else {
            $this->expandedRemises[$remiseId] = true;
        }
    }

    public function toggle(string $type, int $id): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(RapprochementBancaireService::class)
                ->toggleTransaction($this->rapprochement, $type, $id);
            $this->rapprochement = $this->rapprochement->fresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function supprimer(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(RapprochementBancaireService::class)->supprimer($this->rapprochement);
            $this->redirect(route('banques.rapprochement.index'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function verrouiller(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(RapprochementBancaireService::class)
                ->verrouiller($this->rapprochement);
            session()->flash('success', 'Rapprochement verrouillé avec succès.');
            $this->redirect(route('banques.rapprochement.index'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function updateSoldeFin(string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->rapprochement->isVerrouille()) {
            $this->addError('solde_fin', 'Impossible de modifier un rapprochement verrouillé.');

            return;
        }

        $validator = Validator::make(
            ['solde_fin' => $value],
            ['solde_fin' => 'required|numeric'],
            ['solde_fin.required' => 'Le solde de fin est obligatoire.', 'solde_fin.numeric' => 'Le solde de fin doit être un nombre.']
        );
        if ($validator->fails()) {
            $this->addError('solde_fin', $validator->errors()->first('solde_fin'));

            return;
        }

        $this->rapprochement->solde_fin = $value;
        $this->rapprochement->save();
        $this->rapprochement = $this->rapprochement->fresh();
    }

    public function updateDateFin(string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->rapprochement->isVerrouille()) {
            $this->addError('date_fin', 'Impossible de modifier un rapprochement verrouillé.');

            return;
        }

        // Valider le format avant la règle métier
        $validator = Validator::make(
            ['date_fin' => $value],
            ['date_fin' => 'required|date'],
            ['date_fin.required' => 'La date de fin est obligatoire.', 'date_fin.date' => 'La date de fin est invalide.']
        );
        if ($validator->fails()) {
            $this->addError('date_fin', $validator->errors()->first('date_fin'));

            return;
        }

        $dernierVerrouille = RapprochementBancaire::where('compte_id', $this->rapprochement->compte_id)
            ->where('statut', StatutRapprochement::Verrouille)
            ->whereNotNull('verrouille_at')
            ->where('id', '!=', $this->rapprochement->id)
            ->orderByDesc('date_fin')
            ->orderByDesc('id')
            ->first();

        if ($dernierVerrouille && $value < $dernierVerrouille->date_fin->format('Y-m-d')) {
            $this->addError('date_fin', 'La date ne peut pas être antérieure à celle du rapprochement précédent ('.$dernierVerrouille->date_fin->format('d/m/Y').').');

            return;
        }

        $this->rapprochement->date_fin = $value;
        $this->rapprochement->save();
        $this->rapprochement = $this->rapprochement->fresh();
    }

    public function lancerMatchingAutomatique(): void
    {
        if (! $this->canEdit || $this->rapprochement->isVerrouille()) {
            return;
        }

        if (! $this->rapprochement->hasPieceJointe()) {
            $this->matchingErreur = 'Aucune pièce jointe sur ce rapprochement.';

            return;
        }

        $this->matchingEnCours = true;
        $this->resetModeAssiste();

        try {
            $storagePath = $this->rapprochement->pieceJointeFullPath();
            $mime = $this->rapprochement->piece_jointe_mime;
            $resultat = app(ReleveOcrService::class)->analyzeFromStorage($storagePath, $mime);

            if (empty($resultat->mouvements)) {
                $this->matchingErreur = 'Aucun mouvement extrait du relevé.';
                $this->matchingEnCours = false;

                return;
            }

            $this->mouvementsReleve = array_map(fn ($m) => [
                'date' => $m->date,
                'libelle' => $m->libelle,
                'montant' => $m->montant,
            ], $resultat->mouvements);

            $candidates = $this->collecterCandidatsMatching();
            $this->candidatsMatching = $candidates->values()->all();

            $matchingResult = app(RapprochementMatchingService::class)
                ->matcher($resultat->mouvements, $candidates);

            foreach ($matchingResult->propositions as $prop) {
                foreach ($this->mouvementsReleve as $i => $mv) {
                    if (isset($this->associationsPointage[$i])) {
                        continue;
                    }
                    if (abs($mv['montant'] - $prop->mouvement_montant) < 0.001
                        && $mv['date'] === $prop->mouvement_date
                        && $mv['libelle'] === $prop->mouvement_libelle) {
                        $this->associationsPointage[$i] = [
                            'transaction_id' => $prop->transaction_id,
                            'transaction_type' => $prop->transaction_type,
                        ];
                        break;
                    }
                }
            }
        } catch (OcrAnalysisException $e) {
            $this->matchingErreur = $e->getMessage();
        } catch (\Throwable $e) {
            $this->matchingErreur = 'Erreur lors de l\'analyse : '.$e->getMessage();
        } finally {
            $this->matchingEnCours = false;
        }
    }

    public function selectionnerMouvement(int $index): void
    {
        $this->mouvementSelectionne = $this->mouvementSelectionne === $index ? null : $index;
    }

    public function associer(int $transactionId, string $transactionType): void
    {
        if ($this->mouvementSelectionne === null) {
            return;
        }

        foreach ($this->associationsPointage as $assoc) {
            if ((int) $assoc['transaction_id'] === $transactionId && $assoc['transaction_type'] === $transactionType) {
                return;
            }
        }

        $this->associationsPointage[$this->mouvementSelectionne] = [
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
        ];
        $this->mouvementSelectionne = null;
    }

    public function dissocier(int $index): void
    {
        unset($this->associationsPointage[$index]);
        if ($this->mouvementSelectionne === $index) {
            $this->mouvementSelectionne = null;
        }
    }

    public function validerAssociations(): void
    {
        if (! $this->canEdit || empty($this->associationsPointage)) {
            return;
        }

        $count = 0;
        foreach ($this->associationsPointage as $assoc) {
            $this->toggle($assoc['transaction_type'], (int) $assoc['transaction_id']);
            $count++;
        }

        $this->resetModeAssiste();

        if ($count > 0) {
            session()->flash('success', "{$count} écriture(s) pointée(s).");
        }
    }

    public function annulerPointage(): void
    {
        $this->resetModeAssiste();
    }

    private function resetModeAssiste(): void
    {
        $this->mouvementsReleve = null;
        $this->candidatsMatching = null;
        $this->associationsPointage = [];
        $this->mouvementSelectionne = null;
        $this->matchingErreur = null;
    }

    private function collecterCandidatsMatching(): Collection
    {
        return $this->buildTransactions()
            ->where('pointe', false)
            ->map(fn (array $tx) => [
                'id' => (int) $tx['id'],
                'type' => $tx['type'],
                'date' => $tx['date']->format('Y-m-d'),
                'libelle' => $tx['tiers'] ?? $tx['label'],
                'montant_signe' => (float) $tx['montant_signe'],
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, type: string, date: Carbon, label: string, tiers: ?string, reference: ?string, mode_paiement: ?string, montant_signe: float, pointe: bool, sub_transactions: array}>
     */
    private function buildTransactions(): Collection
    {
        $service = app(RapprochementBancaireService::class);
        $compte = $this->rapprochement->compte;
        $rid = $this->rapprochement->id;
        $dateFin = $this->rapprochement->date_fin;
        $verrouille = $this->rapprochement->isVerrouille();

        $transactions = collect();

        $compte512X = $service->resoudreCompte512X($compte);
        $usePdFilter = $compte512X !== null;

        $txRows = Transaction::query()
            ->where('journal', '!=', JournalComptable::AN->value)
            ->where('type', '!=', TypeTransaction::Virement->value)
            ->when(
                $usePdFilter,
                fn ($q) => $q->where(function ($w) use ($compte512X, $compte) {
                    $w->whereNotNull('remise_id')
                        ->orWhereHas('lignes', fn ($l) => $l->where('compte_id', $compte512X->id))
                        ->orWhere(function ($en) use ($compte) {
                            $en->where('compte_id', $compte->id)
                                ->where('statut_reglement', StatutReglement::EnAttente)
                                ->whereNotNull('mode_paiement')
                                ->whereNotIn('mode_paiement', [
                                    ModePaiement::Cheque->value,
                                    ModePaiement::Especes->value,
                                ])
                                ->whereDoesntHave('lignes', fn ($l) => $l
                                    ->whereHas('compte', fn ($c) => $c->bancaires()));
                        });
                }),
                fn ($q) => $q->where('compte_id', $compte->id)
            )
            ->when(
                $usePdFilter,
                fn ($q) => $q->whereNot(function ($w) {
                    $w->where('journal', JournalComptable::Banque->value)
                        ->whereNotNull('remise_id');
                })
            )
            ->where(function ($q) use ($rid, $dateFin, $verrouille) {
                if ($verrouille) {
                    $q->where('rapprochement_id', $rid);
                } else {
                    $q->where(function ($inner) use ($dateFin) {
                        $inner->whereNull('rapprochement_id')
                            ->where('date', '<=', $dateFin);
                    })->orWhere('rapprochement_id', $rid);
                }
            })
            ->with('tiers', 'remise')
            ->get();

        $remiseGroups = $txRows->whereNotNull('remise_id')->groupBy('remise_id');
        $standalone = $txRows->whereNull('remise_id');

        foreach ($remiseGroups as $remiseId => $remiseTxs) {
            $remise = $remiseTxs->first()->remise;
            $allPointed = $remiseTxs->every(fn (Transaction $tx) => (int) $tx->rapprochement_id === $rid);
            $montantTotal = $remiseTxs->sum(fn (Transaction $tx) => $tx->montantSigne());
            $transactions->push([
                'id' => (int) $remiseId,
                'type' => 'remise',
                'date' => $remise?->date ?? $remiseTxs->first()->date,
                'label' => $remise?->libelle ?? "Remise n°{$remiseId}",
                'tiers' => "Remise {$remiseTxs->first()->mode_paiement?->label()} ({$remiseTxs->count()} transactions)",
                'reference' => $remise?->numero ? "n°{$remise->numero}" : null,
                'mode_paiement' => $remiseTxs->first()->mode_paiement?->trigramme(),
                'montant_signe' => $montantTotal,
                'pointe' => $allPointed,
                'sub_transactions' => $remiseTxs->map(fn (Transaction $tx) => [
                    'id' => $tx->id,
                    'date' => $tx->date,
                    'label' => $tx->libelle,
                    'tiers' => $tx->tiers?->displayName() ?? $tx->libelle,
                    'reference' => $tx->reference,
                    'montant_signe' => $tx->montantSigne(),
                ])->values()->all(),
            ]);
        }

        $reglementSvc = $usePdFilter ? app(ReglementOperationService::class) : null;

        $standalone->each(function (Transaction $tx) use (&$transactions, $rid, $usePdFilter, $reglementSvc) {
            $displayTx = $tx;

            if ($usePdFilter && $tx->journal === JournalComptable::Banque) {
                $t1 = $reglementSvc->trouverT2($tx);
                if ($t1 !== null) {
                    $t1->load('tiers');
                    $displayTx = $t1;
                }
            }

            $transactions->push([
                'id' => $displayTx->id,
                'type' => $displayTx->type->value,
                'date' => $displayTx->date,
                'label' => $displayTx->libelle,
                'tiers' => $displayTx->tiers?->displayName() ?? $displayTx->libelle,
                'reference' => $displayTx->reference,
                'mode_paiement' => $displayTx->mode_paiement?->trigramme(),
                'montant_signe' => $displayTx->montantSigne(),
                'pointe' => (int) $displayTx->rapprochement_id === $rid,
                'sub_transactions' => [],
            ]);
        });

        VirementInterne::where('compte_source_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin, $verrouille) {
                if ($verrouille) {
                    $q->where('rapprochement_source_id', $rid);
                } else {
                    $q->where(function ($inner) use ($dateFin) {
                        $inner->whereNull('rapprochement_source_id')
                            ->where('date', '<=', $dateFin);
                    })->orWhere('rapprochement_source_id', $rid);
                }
            })
            ->with('compteDestination')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'virement_source',
                    'date' => $v->date,
                    'label' => 'Virement vers '.$v->compteDestination->nom,
                    'tiers' => $v->compteDestination->nom,
                    'reference' => $v->reference,
                    'mode_paiement' => 'VMT',
                    'montant_signe' => -(float) $v->montant,
                    'pointe' => (int) $v->rapprochement_source_id === $rid,
                    'sub_transactions' => [],
                ]);
            });

        VirementInterne::where('compte_destination_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin, $verrouille) {
                if ($verrouille) {
                    $q->where('rapprochement_destination_id', $rid);
                } else {
                    $q->where(function ($inner) use ($dateFin) {
                        $inner->whereNull('rapprochement_destination_id')
                            ->where('date', '<=', $dateFin);
                    })->orWhere('rapprochement_destination_id', $rid);
                }
            })
            ->with('compteSource')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'virement_destination',
                    'date' => $v->date,
                    'label' => 'Virement depuis '.$v->compteSource->nom,
                    'tiers' => $v->compteSource->nom,
                    'reference' => $v->reference,
                    'mode_paiement' => 'VMT',
                    'montant_signe' => (float) $v->montant,
                    'pointe' => (int) $v->rapprochement_destination_id === $rid,
                    'sub_transactions' => [],
                ]);
            });

        return $transactions->sortBy('date')->values();
    }

    public function render(): View
    {
        $transactions = $this->buildTransactions();

        $totalDebitPointe = abs($transactions->where('pointe', true)->where('montant_signe', '<', 0)->sum('montant_signe'));
        $totalCreditPointe = $transactions->where('pointe', true)->where('montant_signe', '>', 0)->sum('montant_signe');

        if ($this->masquerPointees) {
            $transactions = $transactions->filter(fn (array $tx) => ! $tx['pointe'])->values();
        }

        $service = app(RapprochementBancaireService::class);
        $soldePointage = $service->calculerSoldePointage($this->rapprochement);
        $ecart = $service->calculerEcart($this->rapprochement);

        $projectedDebit = null;
        $projectedCredit = null;
        $projectedSolde = null;
        $projectedEcart = null;

        if ($this->candidatsMatching !== null && ! empty($this->associationsPointage)) {
            $lookup = [];
            foreach ($this->candidatsMatching as $tx) {
                $lookup[$tx['type'].'-'.$tx['id']] = $tx;
            }

            $addDebit = 0.0;
            $addCredit = 0.0;

            foreach ($this->associationsPointage as $assoc) {
                $tx = $lookup[$assoc['transaction_type'].'-'.$assoc['transaction_id']] ?? null;
                if ($tx !== null) {
                    if ($tx['montant_signe'] < 0) {
                        $addDebit += abs($tx['montant_signe']);
                    } else {
                        $addCredit += $tx['montant_signe'];
                    }
                }
            }

            $projectedDebit = $totalDebitPointe + $addDebit;
            $projectedCredit = $totalCreditPointe + $addCredit;
            $projectedSolde = $soldePointage + ($addCredit - $addDebit);
            $projectedEcart = (float) $this->rapprochement->solde_fin - $projectedSolde;
        }

        return view('livewire.rapprochement-detail', [
            'transactions' => $transactions,
            'soldePointage' => $soldePointage,
            'ecart' => $ecart,
            'totalDebitPointe' => $totalDebitPointe,
            'totalCreditPointe' => $totalCreditPointe,
            'iaConfiguree' => ReleveOcrService::isConfigured(),
            'projectedDebit' => $projectedDebit,
            'projectedCredit' => $projectedCredit,
            'projectedSolde' => $projectedSolde,
            'projectedEcart' => $projectedEcart,
        ]);
    }
}
