<?php

declare(strict_types=1);

namespace App\Livewire\Parametres\Adhesions;

use App\Enums\UsageComptable;
use App\Models\Categorie;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use DomainException;
use Illuminate\View\View;
use Livewire\Component;

final class FormulesList extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $nom = '';

    public ?string $description = null;

    public string $mode = 'exercice';

    public ?int $dureeMois = null;

    public ?float $montantParDefaut = null;

    public bool $deductibleFiscal = false;

    public ?int $sousCategorieId = null;

    public bool $actif = true;

    public string $filtre = 'toutes'; // 'toutes' | 'actives' | 'inactives'

    public ?string $errorMessage = null;

    public bool $showCreateSousCat = false;

    public string $newSousCatNom = '';

    public ?string $newSousCatCodeCerfa = null;

    public ?int $newSousCatCategorieId = null;

    public ?string $newSousCatErreur = null;

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $formule = FormuleAdhesion::findOrFail($id);
        $this->editingId = $formule->id;
        $this->nom = $formule->nom;
        $this->description = $formule->description;
        $this->mode = $formule->mode;
        $this->dureeMois = $formule->duree_mois;
        $this->montantParDefaut = $formule->montant_par_defaut !== null ? (float) $formule->montant_par_defaut : null;
        $this->deductibleFiscal = $formule->deductible_fiscal;
        $this->sousCategorieId = $formule->sous_categorie_id;
        $this->actif = $formule->actif;
        $this->errorMessage = null;
        $this->showModal = true;
    }

    public function isEditingHelloasso(): bool
    {
        if ($this->editingId === null) {
            return false;
        }
        $formule = FormuleAdhesion::find($this->editingId);

        return $formule?->est_helloasso ?? false;
    }

    public function save(): void
    {
        $this->errorMessage = null;

        // Cas spécial : édition d'une formule HelloAsso → seul `actif` est modifiable
        if ($this->editingId !== null) {
            $existante = FormuleAdhesion::findOrFail($this->editingId);
            if ($existante->est_helloasso) {
                try {
                    $existante->update(['actif' => $this->actif]);
                } catch (DomainException $e) {
                    $this->errorMessage = $e->getMessage();

                    return;
                }
                session()->flash('success', 'Formule mise à jour.');
                $this->showModal = false;
                $this->resetForm();

                return;
            }
        }

        // Validation standard (formules manuelles)
        $rules = [
            'nom' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'mode' => ['required', 'in:exercice,duree,illimite'],
            'sousCategorieId' => ['required', 'integer', 'exists:sous_categories,id'],
            'montantParDefaut' => ['nullable', 'numeric', 'min:0'],
            'actif' => ['boolean'],
        ];
        if ($this->mode === 'duree') {
            $rules['dureeMois'] = ['required', 'integer', 'between:1,36'];
        }
        $this->validate($rules);

        // Validation métier : sous-cat doit être en usage Cotisation
        $sc = SousCategorie::findOrFail($this->sousCategorieId);
        if (! $sc->hasUsage(UsageComptable::Cotisation)) {
            $this->addError('sousCategorieId', "La sous-catégorie sélectionnée n'a pas l'usage \"Cotisation\".");

            return;
        }

        $data = [
            'nom' => $this->nom,
            'description' => $this->description,
            'mode' => $this->mode,
            'duree_mois' => $this->mode === 'duree' ? $this->dureeMois : null,
            'montant_par_defaut' => $this->montantParDefaut,
            'deductible_fiscal' => $this->deductibleFiscal,
            'sous_categorie_id' => $this->sousCategorieId,
            'actif' => $this->actif,
        ];

        try {
            if ($this->editingId !== null) {
                $formule = FormuleAdhesion::findOrFail($this->editingId);
                $formule->update($data);
            } else {
                FormuleAdhesion::create($data);
            }
        } catch (DomainException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        session()->flash('success', $this->editingId !== null ? 'Formule mise à jour.' : 'Formule créée.');
        $this->showModal = false;
        $this->resetForm();
    }

    public function softDelete(int $id): void
    {
        FormuleAdhesion::findOrFail($id)->delete();
        session()->flash('success', 'Formule supprimée.');
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function openCreateSousCat(): void
    {
        $this->newSousCatNom = '';
        $this->newSousCatCodeCerfa = null;
        $this->newSousCatCategorieId = null;
        $this->newSousCatErreur = null;
        $this->showCreateSousCat = true;
    }

    public function cancelCreateSousCat(): void
    {
        $this->showCreateSousCat = false;
        $this->newSousCatNom = '';
        $this->newSousCatCodeCerfa = null;
        $this->newSousCatCategorieId = null;
        $this->newSousCatErreur = null;
    }

    public function saveNewSousCat(): void
    {
        $this->validate([
            'newSousCatNom' => ['required', 'string', 'max:255'],
            'newSousCatCodeCerfa' => ['nullable', 'string', 'max:10'],
            'newSousCatCategorieId' => ['required', 'integer', 'exists:categories,id'],
        ]);

        $sc = SousCategorie::create([
            'nom' => $this->newSousCatNom,
            'code_cerfa' => $this->newSousCatCodeCerfa,
            'categorie_id' => $this->newSousCatCategorieId,
        ]);

        UsageSousCategorie::create([
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::Cotisation->value,
        ]);

        $this->sousCategorieId = $sc->id;
        $this->showCreateSousCat = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'nom', 'description', 'dureeMois', 'montantParDefaut', 'deductibleFiscal', 'sousCategorieId', 'errorMessage']);
        $this->mode = 'exercice';
        $this->actif = true;
    }

    public function render(): View
    {
        $query = FormuleAdhesion::query()->with('sousCategorie');
        match ($this->filtre) {
            'actives' => $query->where('actif', true),
            'inactives' => $query->where('actif', false),
            default => null,
        };
        $formules = $query->orderBy('est_helloasso')->orderBy('nom')->get();

        $sousCategoriesCotisation = SousCategorie::forUsage(UsageComptable::Cotisation)
            ->orderBy('nom')
            ->get();

        $categories = Categorie::orderBy('nom')->get();

        return view('livewire.parametres.adhesions.formules-list', compact('formules', 'sousCategoriesCotisation', 'categories'));
    }
}
