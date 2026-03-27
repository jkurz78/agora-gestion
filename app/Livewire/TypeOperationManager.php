<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\SousCategorie;
use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class TypeOperationManager extends Component
{
    use WithFileUploads;

    // ── Modal state ──────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    // ── Form fields ──────────────────────────────────────────────
    public string $code = '';

    public string $nom = '';

    public string $description = '';

    public string $sous_categorie_id = '';

    public string $nombre_seances = '';

    public bool $confidentiel = false;

    public bool $reserve_adherents = false;

    public bool $actif = true;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    // ── Tarifs management ────────────────────────────────────────
    /** @var array<int, array{id: int|null, libelle: string, montant: string}> */
    public array $tarifs = [];

    public string $newTarifLibelle = '';

    public string $newTarifMontant = '';

    // ── Filter ───────────────────────────────────────────────────
    public string $filter = 'tous';

    // ── Tarifs flagged for deletion ──────────────────────────────
    /** @var array<int, int> */
    private array $tarifsToDelete = [];

    public function render(): View
    {
        $query = TypeOperation::with(['sousCategorie', 'tarifs'])
            ->withCount('operations');

        if ($this->filter === 'actif') {
            $query->where('actif', true);
        } elseif ($this->filter === 'inactif') {
            $query->where('actif', false);
        }

        $types = $query->orderBy('code')->get();

        $sousCategories = SousCategorie::where('pour_inscriptions', true)
            ->with('categorie')
            ->orderBy('nom')
            ->get();

        return view('livewire.type-operation-manager', [
            'types' => $types,
            'sousCategories' => $sousCategories,
        ]);
    }

    // ── Modal actions ────────────────────────────────────────────

    #[On('openTypeOperationModal')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $type = TypeOperation::with('tarifs')->findOrFail($id);

        $this->editingId = $type->id;
        $this->code = $type->code;
        $this->nom = $type->nom;
        $this->description = $type->description ?? '';
        $this->sous_categorie_id = (string) $type->sous_categorie_id;
        $this->nombre_seances = $type->nombre_seances !== null ? (string) $type->nombre_seances : '';
        $this->confidentiel = $type->confidentiel;
        $this->reserve_adherents = $type->reserve_adherents;
        $this->actif = $type->actif;
        $this->logo = null;
        $this->tarifs = $type->tarifs->map(fn (TypeOperationTarif $t) => [
            'id' => $t->id,
            'libelle' => $t->libelle,
            'montant' => (string) $t->montant,
        ])->toArray();
        $this->tarifsToDelete = [];

        $this->showModal = true;
    }

    public function save(): void
    {
        $rules = [
            'code' => 'required|string|max:20|unique:type_operations,code'.($this->editingId ? ','.$this->editingId : ''),
            'nom' => 'required|string|max:150|unique:type_operations,nom'.($this->editingId ? ','.$this->editingId : ''),
            'sous_categorie_id' => 'required|exists:sous_categories,id',
            'nombre_seances' => 'nullable|integer|min:1',
            'logo' => 'nullable|image|max:512',
        ];

        $this->validate($rules);

        $logoPath = null;

        if ($this->logo) {
            $logoPath = $this->logo->store('type-operations', 'public');
        }

        $data = [
            'code' => $this->code,
            'nom' => $this->nom,
            'description' => $this->description !== '' ? $this->description : null,
            'sous_categorie_id' => (int) $this->sous_categorie_id,
            'nombre_seances' => $this->nombre_seances !== '' ? (int) $this->nombre_seances : null,
            'confidentiel' => $this->confidentiel,
            'reserve_adherents' => $this->reserve_adherents,
            'actif' => $this->actif,
        ];

        if ($logoPath !== null) {
            $data['logo_path'] = $logoPath;
        }

        if ($this->editingId) {
            $type = TypeOperation::findOrFail($this->editingId);

            // Delete old logo if a new one is uploaded
            if ($logoPath !== null && $type->logo_path) {
                Storage::disk('public')->delete($type->logo_path);
            }

            $type->update($data);
        } else {
            if ($logoPath !== null) {
                $data['logo_path'] = $logoPath;
            }
            $type = TypeOperation::create($data);
        }

        // ── Sync tarifs ──────────────────────────────────────────
        $this->syncTarifs($type);

        $this->showModal = false;
        $this->resetForm();

        $this->dispatch('typeOperationCreated', id: $type->id);
    }

    public function delete(int $id): void
    {
        $type = TypeOperation::withCount('operations')->findOrFail($id);

        if ($type->operations_count > 0) {
            $this->dispatch('toast', message: 'Impossible de supprimer : des opérations utilisent ce type.', type: 'error');

            return;
        }

        // Delete logo file if exists
        if ($type->logo_path) {
            Storage::disk('public')->delete($type->logo_path);
        }

        $type->delete();
    }

    // ── Tarifs ───────────────────────────────────────────────────

    public function addTarif(): void
    {
        if ($this->newTarifLibelle === '' || $this->newTarifMontant === '') {
            return;
        }

        $this->tarifs[] = [
            'id' => null,
            'libelle' => $this->newTarifLibelle,
            'montant' => $this->newTarifMontant,
        ];

        $this->newTarifLibelle = '';
        $this->newTarifMontant = '';
    }

    public function removeTarif(int $index): void
    {
        if (! isset($this->tarifs[$index])) {
            return;
        }

        $tarif = $this->tarifs[$index];

        // Track existing tarifs for deletion on save
        if ($tarif['id'] !== null) {
            $this->tarifsToDelete[] = $tarif['id'];
        }

        unset($this->tarifs[$index]);
        $this->tarifs = array_values($this->tarifs);
    }

    // ── Private helpers ──────────────────────────────────────────

    private function syncTarifs(TypeOperation $type): void
    {
        // Delete tarifs flagged for removal (only if no participants use them)
        foreach ($this->tarifsToDelete as $tarifId) {
            $tarif = TypeOperationTarif::find($tarifId);
            if ($tarif === null) {
                continue;
            }

            if ($tarif->participants()->exists()) {
                // Don't delete, re-add to the list
                continue;
            }

            $tarif->delete();
        }

        // Update existing tarifs and create new ones
        foreach ($this->tarifs as $tarifData) {
            if ($tarifData['id'] !== null) {
                // Update existing
                TypeOperationTarif::where('id', $tarifData['id'])->update([
                    'libelle' => $tarifData['libelle'],
                    'montant' => (float) str_replace(',', '.', $tarifData['montant']),
                ]);
            } else {
                // Create new
                TypeOperationTarif::create([
                    'type_operation_id' => $type->id,
                    'libelle' => $tarifData['libelle'],
                    'montant' => (float) str_replace(',', '.', $tarifData['montant']),
                ]);
            }
        }
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->code = '';
        $this->nom = '';
        $this->description = '';
        $this->sous_categorie_id = '';
        $this->nombre_seances = '';
        $this->confidentiel = false;
        $this->reserve_adherents = false;
        $this->actif = true;
        $this->logo = null;
        $this->tarifs = [];
        $this->newTarifLibelle = '';
        $this->newTarifMontant = '';
        $this->tarifsToDelete = [];
        $this->resetValidation();
    }
}
