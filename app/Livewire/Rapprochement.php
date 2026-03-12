<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Recette;
use App\Services\RapprochementService;
use Livewire\Component;

final class Rapprochement extends Component
{
    public ?int $compte_id = null;

    public ?string $date_debut = null;

    public ?string $date_fin = null;

    public function toggle(string $type, int $id): void
    {
        app(RapprochementService::class)->togglePointe($type, $id);
    }

    public function render()
    {
        $comptes = CompteBancaire::orderBy('nom')->get();
        $transactions = collect();
        $soldeTheorique = null;

        if ($this->compte_id) {
            $compte = CompteBancaire::findOrFail($this->compte_id);

            // Depenses
            $depenses = Depense::where('compte_id', $this->compte_id)
                ->when($this->date_debut, fn ($q) => $q->where('date', '>=', $this->date_debut))
                ->when($this->date_fin, fn ($q) => $q->where('date', '<=', $this->date_fin))
                ->get()
                ->map(fn (Depense $d) => (object) [
                    'id' => $d->id,
                    'date' => $d->date,
                    'type' => 'depense',
                    'label' => $d->libelle,
                    'montant' => (float) $d->montant_total,
                    'pointe' => $d->pointe,
                ]);

            // Recettes
            $recettes = Recette::where('compte_id', $this->compte_id)
                ->when($this->date_debut, fn ($q) => $q->where('date', '>=', $this->date_debut))
                ->when($this->date_fin, fn ($q) => $q->where('date', '<=', $this->date_fin))
                ->get()
                ->map(fn (Recette $r) => (object) [
                    'id' => $r->id,
                    'date' => $r->date,
                    'type' => 'recette',
                    'label' => $r->libelle,
                    'montant' => (float) $r->montant_total,
                    'pointe' => $r->pointe,
                ]);

            // Dons
            $dons = Don::where('compte_id', $this->compte_id)
                ->when($this->date_debut, fn ($q) => $q->where('date', '>=', $this->date_debut))
                ->when($this->date_fin, fn ($q) => $q->where('date', '<=', $this->date_fin))
                ->with('donateur')
                ->get()
                ->map(fn (Don $d) => (object) [
                    'id' => $d->id,
                    'date' => $d->date,
                    'type' => 'don',
                    'label' => $d->donateur ? $d->donateur->nom.' '.$d->donateur->prenom : ($d->objet ?? 'Don anonyme'),
                    'montant' => (float) $d->montant,
                    'pointe' => $d->pointe,
                ]);

            // Cotisations
            $cotisations = Cotisation::where('compte_id', $this->compte_id)
                ->when($this->date_debut, fn ($q) => $q->where('date_paiement', '>=', $this->date_debut))
                ->when($this->date_fin, fn ($q) => $q->where('date_paiement', '<=', $this->date_fin))
                ->with('membre')
                ->get()
                ->map(fn (Cotisation $c) => (object) [
                    'id' => $c->id,
                    'date' => $c->date_paiement,
                    'type' => 'cotisation',
                    'label' => $c->membre ? $c->membre->nom.' '.$c->membre->prenom : 'Cotisation',
                    'montant' => (float) $c->montant,
                    'pointe' => $c->pointe,
                ]);

            $transactions = $depenses->concat($recettes)->concat($dons)->concat($cotisations)
                ->sortBy('date')
                ->values();

            $soldeTheorique = app(RapprochementService::class)->soldeTheorique($compte, $this->date_fin);
        }

        return view('livewire.rapprochement', [
            'comptes' => $comptes,
            'transactions' => $transactions,
            'soldeTheorique' => $soldeTheorique,
        ]);
    }
}
