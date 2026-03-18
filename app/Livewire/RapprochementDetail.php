<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Cotisation;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use Illuminate\View\View;
use Livewire\Component;

final class RapprochementDetail extends Component
{
    public RapprochementBancaire $rapprochement;

    public function mount(RapprochementBancaire $rapprochement): void
    {
        $this->rapprochement = $rapprochement;
    }

    public function toggle(string $type, int $id): void
    {
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
        try {
            app(RapprochementBancaireService::class)->supprimer($this->rapprochement);
            $this->redirect(route('rapprochement.index'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function verrouiller(): void
    {
        try {
            app(RapprochementBancaireService::class)
                ->verrouiller($this->rapprochement);
            $this->rapprochement = $this->rapprochement->fresh();
            session()->flash('success', 'Rapprochement verrouillé avec succès.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
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

        // Dons
        Don::where('compte_id', $compte->id)
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
            ->each(function (Don $d) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $d->id,
                    'type' => 'don',
                    'date' => $d->date,
                    'label' => $d->tiers
                        ? $d->tiers->displayName()
                        : ($d->objet ?? 'Don anonyme'),
                    'tiers' => $d->tiers ? $d->tiers->displayName() : ($d->objet ?? 'Don anonyme'),
                    'reference' => null,
                    'montant_signe' => (float) $d->montant,
                    'pointe' => (int) $d->rapprochement_id === $rid,
                ]);
            });

        // Cotisations
        Cotisation::where('compte_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin, $verrouille) {
                if ($verrouille) {
                    $q->where('rapprochement_id', $rid);
                } else {
                    $q->where(function ($inner) use ($dateFin) {
                        $inner->whereNull('rapprochement_id')
                            ->where('date_paiement', '<=', $dateFin);
                    })->orWhere('rapprochement_id', $rid);
                }
            })
            ->with('tiers')
            ->get()
            ->each(function (Cotisation $c) use (&$transactions, $rid) {
                $transactions->push([
                    'id'            => $c->id,
                    'type'          => 'cotisation',
                    'date'          => $c->date_paiement,
                    'label'         => $c->tiers ? $c->tiers->displayName() : 'Cotisation',
                    'tiers'         => $c->tiers ? $c->tiers->displayName() : 'Cotisation',
                    'reference'     => null,
                    'montant_signe' => (float) $c->montant,
                    'pointe'        => (int) $c->rapprochement_id === $rid,
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

        $totalDebitPointe  = abs($transactions->where('pointe', true)->where('montant_signe', '<', 0)->sum('montant_signe'));
        $totalCreditPointe = $transactions->where('pointe', true)->where('montant_signe', '>', 0)->sum('montant_signe');

        $soldePointage = $service->calculerSoldePointage($this->rapprochement);
        $ecart = $service->calculerEcart($this->rapprochement);

        return view('livewire.rapprochement-detail', [
            'transactions'       => $transactions,
            'soldePointage'      => $soldePointage,
            'ecart'              => $ecart,
            'totalDebitPointe'   => $totalDebitPointe,
            'totalCreditPointe'  => $totalCreditPointe,
        ]);
    }
}
