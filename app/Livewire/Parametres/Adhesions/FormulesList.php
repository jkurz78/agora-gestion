<?php

declare(strict_types=1);

namespace App\Livewire\Parametres\Adhesions;

use App\Enums\UsageComptable;
use App\Models\Categorie;
use App\Models\Compte;
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

    public string $uniteDuree = 'mois'; // 'mois' | 'jours'

    public ?int $dureeJours = null;

    public ?float $montantParDefaut = null;

    public bool $deductibleFiscal = false;

    public ?int $compteId = null;

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
        // Initialisation unité durée selon ce qui est set sur la formule
        if ($formule->duree_jours !== null) {
            $this->uniteDuree = 'jours';
            $this->dureeJours = $formule->duree_jours;
            $this->dureeMois = null;
        } else {
            $this->uniteDuree = 'mois';
            $this->dureeMois = $formule->duree_mois;
            $this->dureeJours = null;
        }
        $this->montantParDefaut = $formule->montant_par_defaut !== null ? (float) $formule->montant_par_defaut : null;
        $this->deductibleFiscal = $formule->deductible_fiscal;
        $this->compteId = $formule->compte_id;
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
            'compteId' => ['required', 'integer', 'exists:comptes,id'],
            'montantParDefaut' => ['nullable', 'numeric', 'min:0'],
            'actif' => ['boolean'],
        ];
        if ($this->mode === 'duree') {
            if ($this->uniteDuree === 'jours') {
                $rules['dureeJours'] = ['required', 'integer', 'min:1'];
            } else {
                $rules['dureeMois'] = ['required', 'integer', 'between:1,36'];
            }
        }
        $this->validate($rules);

        // Validation métier : le compte doit être en usage Cotisation
        $compte = Compte::findOrFail($this->compteId);
        if (! $compte->hasUsage(UsageComptable::Cotisation)) {
            $this->addError('compteId', "Le compte sélectionné n'a pas l'usage \"Cotisation\".");

            return;
        }

        $data = [
            'nom' => $this->nom,
            'description' => $this->description,
            'mode' => $this->mode,
            'duree_mois' => ($this->mode === 'duree' && $this->uniteDuree === 'mois') ? $this->dureeMois : null,
            'duree_jours' => ($this->mode === 'duree' && $this->uniteDuree === 'jours') ? $this->dureeJours : null,
            'montant_par_defaut' => $this->montantParDefaut,
            'deductible_fiscal' => $this->deductibleFiscal,
            // DC-8 : écrit compte_id, le trait remplit le miroir sous_categorie_id.
            'compte_id' => $this->compteId,
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
        // DC-8 : numéro de compte requis — sans lui, pas de Compte miroir, donc
        // la nouvelle entrée serait invisible dans le sélecteur (liste de comptes).
        $this->validate([
            'newSousCatNom' => ['required', 'string', 'max:255'],
            'newSousCatCodeCerfa' => ['required', 'string', 'max:10'],
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

        // Sélectionne le Compte miroir matérialisé par l'observer DC-7.
        $compte = Compte::ofNumero((string) $sc->code_cerfa);
        $this->compteId = $compte?->id;
        $this->showCreateSousCat = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'nom', 'description', 'dureeMois', 'dureeJours', 'montantParDefaut', 'deductibleFiscal', 'compteId', 'errorMessage']);
        $this->mode = 'exercice';
        $this->uniteDuree = 'mois';
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

        // DC-8 : le sélecteur liste les comptes en usage Cotisation.
        $comptesCotisation = Compte::forUsage(UsageComptable::Cotisation)
            ->orderBy('numero_pcg')
            ->get();

        $categories = Categorie::orderBy('nom')->get();

        return view('livewire.parametres.adhesions.formules-list', compact('formules', 'comptesCotisation', 'categories'));
    }
}
