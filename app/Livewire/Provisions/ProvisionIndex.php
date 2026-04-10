<?php

declare(strict_types=1);

namespace App\Livewire\Provisions;

use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\Provision;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class ProvisionIndex extends Component
{
    use WithFileUploads;

    // ── Modal state ──────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    // ── Form fields ──────────────────────────────────────────────
    public string $libelle = '';

    public string $sous_categorie_id = '';

    public string $type = '';

    public string $montant = '';

    public ?int $tiers_id = null;

    public string $operation_id = '';

    public string $seance = '';

    public string $notes = '';

    /** @var TemporaryUploadedFile|null */
    public $piece_jointe = null;

    // ── Flash message ────────────────────────────────────────────
    public string $flashMessage = '';

    public string $flashType = '';

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();
        $dateRange = $exerciceService->dateRange($exercice);
        $exerciceLabel = $exerciceService->label($exercice);
        $exerciceModel = $exerciceService->exerciceAffiche();
        $isCloture = $exerciceModel !== null && $exerciceModel->isCloture();

        $provisions = Provision::with(['sousCategorie.categorie', 'tiers', 'operation'])
            ->forExercice($exercice)
            ->orderBy('libelle')
            ->get();

        $categories = Categorie::with('sousCategories')
            ->orderBy('nom')
            ->get();

        $operations = Operation::forExercice($exercice)
            ->orderBy('nom')
            ->get();

        $totalDepenses = $provisions
            ->filter(fn (Provision $p) => $p->type === TypeTransaction::Depense)
            ->sum(fn (Provision $p) => (float) $p->montant);

        $totalRecettes = $provisions
            ->filter(fn (Provision $p) => $p->type === TypeTransaction::Recette)
            ->sum(fn (Provision $p) => (float) $p->montant);

        return view('livewire.provisions.provision-index', [
            'provisions' => $provisions,
            'categories' => $categories,
            'operations' => $operations,
            'isCloture' => $isCloture,
            'exerciceLabel' => $exerciceLabel,
            'dateRange' => $dateRange,
            'totalDepenses' => $totalDepenses,
            'totalRecettes' => $totalRecettes,
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
        $provision = Provision::findOrFail($id);

        $this->editingId = $provision->id;
        $this->libelle = $provision->libelle;
        $this->sous_categorie_id = (string) $provision->sous_categorie_id;
        $this->type = $provision->type->value;
        $this->montant = (string) $provision->montant;
        $this->tiers_id = $provision->tiers_id ? (int) $provision->tiers_id : null;
        $this->operation_id = $provision->operation_id ? (string) $provision->operation_id : '';
        $this->seance = $provision->seance ? (string) $provision->seance : '';
        $this->notes = $provision->notes ?? '';
        $this->piece_jointe = null;

        $this->showModal = true;
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();

        try {
            $exerciceService->assertOuvert($exercice);
        } catch (ExerciceCloturedException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'danger';
            $this->showModal = false;

            return;
        }

        $this->validate([
            'libelle' => 'required|string|max:255',
            'sous_categorie_id' => 'required|exists:sous_categories,id',
            'type' => 'required|in:depense,recette',
            'montant' => 'required|numeric',
            'tiers_id' => 'nullable|exists:tiers,id',
            'operation_id' => 'nullable|exists:operations,id',
            'seance' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:2000',
            'piece_jointe' => 'nullable|file|max:10240',
        ]);

        $dateRange = $exerciceService->dateRange($exercice);

        $data = [
            'exercice' => $exercice,
            'libelle' => $this->libelle,
            'sous_categorie_id' => (int) $this->sous_categorie_id,
            'type' => $this->type,
            'montant' => (float) $this->montant,
            'tiers_id' => $this->tiers_id,
            'operation_id' => $this->operation_id !== '' ? (int) $this->operation_id : null,
            'seance' => $this->seance !== '' ? (int) $this->seance : null,
            'date' => $dateRange['end']->toDateString(),
            'notes' => $this->notes !== '' ? $this->notes : null,
            'saisi_par' => auth()->id(),
        ];

        if ($this->piece_jointe !== null) {
            $path = $this->piece_jointe->store('provisions', 'local');
            $data['piece_jointe_path'] = $path;
            $data['piece_jointe_nom'] = $this->piece_jointe->getClientOriginalName();
            $data['piece_jointe_mime'] = $this->piece_jointe->getMimeType();
        }

        if ($this->editingId !== null) {
            Provision::findOrFail($this->editingId)->update($data);
        } else {
            Provision::create($data);
        }

        $this->showModal = false;
        $this->resetForm();

        $this->flashMessage = $this->editingId !== null
            ? 'Provision modifiée avec succès.'
            : 'Provision créée avec succès.';
        $this->flashType = 'success';
    }

    // ── Delete ───────────────────────────────────────────────────

    public function delete(int $id): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();

        try {
            $exerciceService->assertOuvert($exercice);
        } catch (ExerciceCloturedException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'danger';

            return;
        }

        Provision::findOrFail($id)->delete();

        $this->flashMessage = 'Provision supprimée.';
        $this->flashType = 'success';
    }

    // ── Private helpers ──────────────────────────────────────────

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->libelle = '';
        $this->sous_categorie_id = '';
        $this->type = '';
        $this->montant = '';
        $this->tiers_id = null;
        $this->operation_id = '';
        $this->seance = '';
        $this->notes = '';
        $this->piece_jointe = null;
        $this->flashMessage = '';
        $this->flashType = '';
        $this->resetValidation();
    }
}
