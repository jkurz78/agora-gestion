<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use App\Services\ReglementOperationService;
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
            $this->rapprochement = $this->rapprochement->fresh();
            session()->flash('success', 'Rapprochement verrouillé avec succès.');
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

    public function render(): View
    {
        $service = app(RapprochementBancaireService::class);
        $compte = $this->rapprochement->compte;
        $rid = $this->rapprochement->id;
        $dateFin = $this->rapprochement->date_fin;

        $transactions = collect();
        $verrouille = $this->rapprochement->isVerrouille();

        // En mode partie double, résoudre le compte 512X strict du compte bancaire.
        // Utilisé pour filtrer la liste pointable : seules les écritures portant une
        // ligne sur CE compte 512X (ou appartenant à une remise) sont affichées.
        // Si le compte 512X est introuvable (tenant sans schéma PD), pas de filtre
        // (dégradation gracieuse identique au comportement legacy).
        $compte512X = config('compta.use_partie_double')
            ? $service->resoudreCompte512X($compte)
            : null;

        // Transactions (dépenses + recettes) — grouper les remises en une seule ligne
        //
        // Mode PD : le filtre par lignes 512X REMPLACE le filtre legacy header compte_id.
        // La T2 de règlement (créée par pourReglement) n'a pas de compte_id header
        // mais porte une ligne 512X — sans ce fallback elle serait invisible.
        $usePdFilter = config('compta.use_partie_double') && $compte512X !== null;

        $txRows = Transaction::query()
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

        // Séparer les transactions en remise et les transactions standalone
        $remiseGroups = $txRows->whereNotNull('remise_id')->groupBy('remise_id');
        $standalone = $txRows->whereNull('remise_id');

        // Lignes remises — une ligne par remise
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

        // Lignes standalone — en mode PD, si la transaction est une T2 (journal=Banque),
        // on affiche les infos de la T1 source (tiers, libellé, date) car l'utilisateur
        // raisonne sur la transaction métier, pas sur l'écriture technique de règlement.
        // Le toggle reçoit l'id T1 → pointage/dépointage symétriques via le code existant.
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

        // Virements sortants (source = ce compte)
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

        // Virements entrants (destination = ce compte)
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

        $transactions = $transactions->sortBy('date')->values();

        // Totals first — always over the full set of pointed transactions
        $totalDebitPointe = abs($transactions->where('pointe', true)->where('montant_signe', '<', 0)->sum('montant_signe'));
        $totalCreditPointe = $transactions->where('pointe', true)->where('montant_signe', '>', 0)->sum('montant_signe');

        // Display filter second — only affects the table, not the summary cards
        if ($this->masquerPointees) {
            $transactions = $transactions->filter(fn (array $tx) => ! $tx['pointe'])->values();
        }

        $soldePointage = $service->calculerSoldePointage($this->rapprochement);
        $ecart = $service->calculerEcart($this->rapprochement);

        return view('livewire.rapprochement-detail', [
            'transactions' => $transactions,
            'soldePointage' => $soldePointage,
            'ecart' => $ecart,
            'totalDebitPointe' => $totalDebitPointe,
            'totalCreditPointe' => $totalCreditPointe,
        ]);
    }
}
