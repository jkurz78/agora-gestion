<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
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

    public function render(): \Illuminate\View\View
    {
        $service = app(RapprochementBancaireService::class);
        $compte = $this->rapprochement->compte;
        $rid = $this->rapprochement->id;
        $dateFin = $this->rapprochement->date_fin;

        $transactions = collect();

        // Dépenses
        Depense::where('compte_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin) {
                $q->where(function ($inner) use ($dateFin) {
                    $inner->whereNull('rapprochement_id')
                          ->where('date', '<=', $dateFin);
                })->orWhere('rapprochement_id', $rid);
            })
            ->get()
            ->each(function (Depense $d) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $d->id,
                    'type' => 'depense',
                    'date' => $d->date,
                    'label' => $d->libelle,
                    'reference' => $d->reference,
                    'montant_signe' => -(float) $d->montant_total,
                    'pointe' => (int) $d->rapprochement_id === $rid,
                ]);
            });

        // Recettes
        Recette::where('compte_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin) {
                $q->where(function ($inner) use ($dateFin) {
                    $inner->whereNull('rapprochement_id')
                          ->where('date', '<=', $dateFin);
                })->orWhere('rapprochement_id', $rid);
            })
            ->get()
            ->each(function (Recette $r) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $r->id,
                    'type' => 'recette',
                    'date' => $r->date,
                    'label' => $r->libelle,
                    'reference' => $r->reference,
                    'montant_signe' => (float) $r->montant_total,
                    'pointe' => (int) $r->rapprochement_id === $rid,
                ]);
            });

        // Dons
        Don::where('compte_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin) {
                $q->where(function ($inner) use ($dateFin) {
                    $inner->whereNull('rapprochement_id')
                          ->where('date', '<=', $dateFin);
                })->orWhere('rapprochement_id', $rid);
            })
            ->with('donateur')
            ->get()
            ->each(function (Don $d) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $d->id,
                    'type' => 'don',
                    'date' => $d->date,
                    'label' => $d->donateur
                        ? $d->donateur->nom.' '.$d->donateur->prenom
                        : ($d->objet ?? 'Don anonyme'),
                    'reference' => null,
                    'montant_signe' => (float) $d->montant,
                    'pointe' => (int) $d->rapprochement_id === $rid,
                ]);
            });

        // Cotisations
        Cotisation::where('compte_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin) {
                $q->where(function ($inner) use ($dateFin) {
                    $inner->whereNull('rapprochement_id')
                          ->where('date_paiement', '<=', $dateFin);
                })->orWhere('rapprochement_id', $rid);
            })
            ->with('membre')
            ->get()
            ->each(function (Cotisation $c) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $c->id,
                    'type' => 'cotisation',
                    'date' => $c->date_paiement,
                    'label' => $c->membre ? $c->membre->nom.' '.$c->membre->prenom : 'Cotisation',
                    'reference' => null,
                    'montant_signe' => (float) $c->montant,
                    'pointe' => (int) $c->rapprochement_id === $rid,
                ]);
            });

        // Virements sortants (source = ce compte)
        VirementInterne::where('compte_source_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin) {
                $q->where(function ($inner) use ($dateFin) {
                    $inner->whereNull('rapprochement_source_id')
                          ->where('date', '<=', $dateFin);
                })->orWhere('rapprochement_source_id', $rid);
            })
            ->with('compteDestination')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'virement_source',
                    'date' => $v->date,
                    'label' => 'Virement vers '.$v->compteDestination->nom,
                    'reference' => $v->reference,
                    'montant_signe' => -(float) $v->montant,
                    'pointe' => (int) $v->rapprochement_source_id === $rid,
                ]);
            });

        // Virements entrants (destination = ce compte)
        VirementInterne::where('compte_destination_id', $compte->id)
            ->where(function ($q) use ($rid, $dateFin) {
                $q->where(function ($inner) use ($dateFin) {
                    $inner->whereNull('rapprochement_destination_id')
                          ->where('date', '<=', $dateFin);
                })->orWhere('rapprochement_destination_id', $rid);
            })
            ->with('compteSource')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'virement_destination',
                    'date' => $v->date,
                    'label' => 'Virement depuis '.$v->compteSource->nom,
                    'reference' => $v->reference,
                    'montant_signe' => (float) $v->montant,
                    'pointe' => (int) $v->rapprochement_destination_id === $rid,
                ]);
            });

        $transactions = $transactions->sortBy('date')->values();

        $soldePointage = $service->calculerSoldePointage($this->rapprochement);
        $ecart = $service->calculerEcart($this->rapprochement);

        return view('livewire.rapprochement-detail', [
            'transactions' => $transactions,
            'soldePointage' => $soldePointage,
            'ecart' => $ecart,
        ]);
    }
}
