<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\StatutRapprochement;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Component;

final class RapprochementDetail extends Component
{
    use RespectsExerciceCloture;

    public RapprochementBancaire $rapprochement;

    public bool $masquerPointees = false;

    public function mount(RapprochementBancaire $rapprochement): void
    {
        $this->rapprochement = $rapprochement;
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Compta);
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
            $this->redirect(route('compta.rapprochement.index'));
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

        // Transactions (dépenses + recettes)
        Transaction::where('compte_id', $compte->id)
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
            ->with('tiers')
            ->get()
            ->each(function (Transaction $tx) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $tx->id,
                    'type' => $tx->type->value,
                    'date' => $tx->date,
                    'label' => $tx->libelle,
                    'tiers' => $tx->tiers?->displayName() ?? $tx->libelle,
                    'reference' => $tx->reference,
                    'montant_signe' => $tx->montantSigne(),
                    'pointe' => (int) $tx->rapprochement_id === $rid,
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
                    'montant_signe' => -(float) $v->montant,
                    'pointe' => (int) $v->rapprochement_source_id === $rid,
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
                    'montant_signe' => (float) $v->montant,
                    'pointe' => (int) $v->rapprochement_destination_id === $rid,
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
