<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Component;

final class SousCategorieList extends Component
{
    // ── Modal state ──────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    // ── Form fields ──────────────────────────────────────────────
    public string $categorie_id = '';

    public string $nom = '';

    public string $code_cerfa = '';

    // ── Flash message ────────────────────────────────────────────
    public string $flashMessage = '';

    public string $flashType = '';

    public function render(): View
    {
        return view('livewire.sous-categorie-list', [
            'categories' => Categorie::orderBy('nom')->get(),
            'sousCategories' => SousCategorie::with('categorie')
                ->join('categories', 'sous_categories.categorie_id', '=', 'categories.id')
                ->orderBy('categories.nom')
                ->orderBy('sous_categories.code_cerfa')
                ->select('sous_categories.*')
                ->get(),
        ]);
    }

    // ── Modal actions ────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $sc = SousCategorie::findOrFail($id);

        $this->editingId = $sc->id;
        $this->categorie_id = (string) $sc->categorie_id;
        $this->nom = $sc->nom;
        $this->code_cerfa = $sc->code_cerfa ?? '';

        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'categorie_id' => 'required|exists:categories,id',
            'nom' => 'required|string|max:100',
            'code_cerfa' => 'nullable|string|max:10',
        ]);

        $data = [
            'categorie_id' => (int) $this->categorie_id,
            'nom' => $this->nom,
            'code_cerfa' => $this->code_cerfa !== '' ? $this->code_cerfa : null,
        ];

        if ($this->editingId !== null) {
            SousCategorie::findOrFail($this->editingId)->update($data);
        } else {
            SousCategorie::create($data);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    // ── Inline edit ──────────────────────────────────────────────

    public function updateField(int $id, string $field, string $value): void
    {
        if (! in_array($field, ['nom', 'code_cerfa'], true)) {
            return;
        }

        $rules = [
            'nom' => 'required|string|max:100',
            'code_cerfa' => 'nullable|string|max:10',
        ];

        $validator = validator([$field => $value], [$field => $rules[$field]]);

        if ($validator->fails()) {
            $this->flashMessage = $validator->errors()->first($field);
            $this->flashType = 'danger';

            return;
        }

        $sc = SousCategorie::findOrFail($id);
        $sc->update([$field => ($field === 'code_cerfa' && $value === '') ? null : $value]);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function delete(int $id): void
    {
        try {
            SousCategorie::findOrFail($id)->delete();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->flashMessage = 'Suppression impossible : cet élément est utilisé dans les données de l\'application.';
                $this->flashType = 'danger';

                return;
            }
            throw $e;
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->categorie_id = '';
        $this->nom = '';
        $this->code_cerfa = '';
        $this->flashMessage = '';
        $this->flashType = '';
        $this->resetValidation();
    }
}
