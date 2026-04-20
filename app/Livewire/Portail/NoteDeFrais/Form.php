<?php

declare(strict_types=1);

namespace App\Livewire\Portail\NoteDeFrais;

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutOperation;
use App\Enums\TypeCategorie;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\Portail\NoteDeFrais\JustificatifAnalyser;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class Form extends Component
{
    use WithFileUploads;
    use WithPortailTenant;

    public Association $association;

    public ?NoteDeFrais $noteDeFrais = null;

    public ?string $dateInput = null;

    public ?string $libelle = null;

    /** @var list<array<string, mixed>> */
    public array $lignes = [];

    // ---------------------------------------------------------------------------
    // Wizard d'ajout de ligne
    // ---------------------------------------------------------------------------

    /** 0 = fermé, 1-3 = étape courante du wizard */
    public int $wizardStep = 0;

    /** @var array{justif: mixed, libelle: string, montant: string, sous_categorie_id: int|null, operation_id: int|null, seance_id: int|null} */
    public array $draftLigne = [
        'justif' => null,
        'libelle' => '',
        'montant' => '',
        'sous_categorie_id' => null,
        'operation_id' => null,
        'seance_id' => null,
    ];

    public function mount(Association $association, ?NoteDeFrais $noteDeFrais = null): void
    {
        $this->association = $association;

        if ($noteDeFrais !== null) {
            Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('update', $noteDeFrais);

            // Brouillon et Soumise peuvent être édités — les autres statuts sont refusés par la policy
            $editableStatuts = [StatutNoteDeFrais::Brouillon, StatutNoteDeFrais::Soumise];
            if (! in_array($noteDeFrais->statut, $editableStatuts, true)) {
                abort(403, 'Seul un brouillon ou une NDF soumise peut être modifié(e).');
            }

            $this->noteDeFrais = $noteDeFrais;
            $this->dateInput = $noteDeFrais->date?->format('Y-m-d');
            $this->libelle = $noteDeFrais->libelle;
            $this->lignes = $noteDeFrais->lignes->map(fn (NoteDeFraisLigne $l) => [
                'id' => $l->id,
                'sous_categorie_id' => $l->sous_categorie_id,
                'operation_id' => $l->operation_id,
                'seance_id' => $l->seance_id,
                'libelle' => $l->libelle,
                'montant' => (string) $l->montant,
                'piece_jointe_path' => $l->piece_jointe_path,
                'justif' => null,
            ])->all();
        } else {
            $this->dateInput = now()->format('Y-m-d');
            // Pas de ligne par défaut — l'utilisateur ajoute ses lignes via le wizard
        }
    }

    // ---------------------------------------------------------------------------
    // Wizard methods
    // ---------------------------------------------------------------------------

    public function openLigneWizard(): void
    {
        $this->resetDraftLigne();
        $this->wizardStep = 1;
        $this->dispatch('ligne-wizard-opened');
    }

    public function cancelLigneWizard(): void
    {
        $this->resetDraftLigne();
        $this->wizardStep = 0;
        $this->dispatch('ligne-wizard-closed');
    }

    public function wizardNext(): void
    {
        if ($this->wizardStep === 1) {
            $this->validateOnly('draftLigne.justif', [
                'draftLigne.justif' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic', 'max:5120'],
            ], [
                'draftLigne.justif.required' => 'Un justificatif est obligatoire.',
                'draftLigne.justif.file' => 'Le justificatif doit être un fichier.',
                'draftLigne.justif.mimes' => 'Le justificatif doit être un PDF, JPG, PNG ou HEIC.',
                'draftLigne.justif.max' => 'Le justificatif ne doit pas dépasser 5 Mo.',
            ]);

            /** @var TemporaryUploadedFile $justif */
            $justif = $this->draftLigne['justif'];
            $hints = app(JustificatifAnalyser::class)->analyse($justif);

            if ($hints['libelle']) {
                $this->draftLigne['libelle'] = $hints['libelle'];
            }

            if ($hints['montant']) {
                $this->draftLigne['montant'] = (string) $hints['montant'];
            }

            $this->wizardStep = 2;

            return;
        }

        if ($this->wizardStep === 2) {
            $this->validateOnly('draftLigne.montant', [
                'draftLigne.montant' => ['required', 'numeric', 'gt:0'],
            ], [
                'draftLigne.montant.required' => 'Le montant est obligatoire.',
                'draftLigne.montant.numeric' => 'Le montant doit être un nombre.',
                'draftLigne.montant.gt' => 'Le montant doit être supérieur à zéro.',
            ]);

            $this->wizardStep = 3;
        }
    }

    public function wizardPrev(): void
    {
        if ($this->wizardStep > 1) {
            $this->wizardStep--;
        }
    }

    public function wizardConfirm(): void
    {
        $this->validateOnly('draftLigne.sous_categorie_id', [
            'draftLigne.sous_categorie_id' => ['required'],
        ], [
            'draftLigne.sous_categorie_id.required' => 'La sous-catégorie est obligatoire.',
        ]);

        $this->lignes[] = [
            'id' => null,
            'sous_categorie_id' => $this->draftLigne['sous_categorie_id'],
            'operation_id' => $this->draftLigne['operation_id'],
            'seance_id' => $this->draftLigne['seance_id'],
            'libelle' => $this->draftLigne['libelle'],
            'montant' => $this->draftLigne['montant'],
            'piece_jointe_path' => null,
            'justif' => $this->draftLigne['justif'],
        ];

        $this->resetDraftLigne();
        $this->wizardStep = 0;
        $this->dispatch('ligne-wizard-closed');
    }

    // ---------------------------------------------------------------------------
    // Ligne methods (inline)
    // ---------------------------------------------------------------------------

    public function addLigne(): void
    {
        $this->lignes[] = [
            'id' => null,
            'sous_categorie_id' => null,
            'operation_id' => null,
            'seance_id' => null,
            'libelle' => null,
            'montant' => null,
            'piece_jointe_path' => null,
            'justif' => null,
        ];
    }

    public function removeLigne(int $index): void
    {
        if (isset($this->lignes[$index])) {
            $ligneId = $this->lignes[$index]['id'] ?? null;
            if ($ligneId !== null) {
                $ligne = NoteDeFraisLigne::find((int) $ligneId);
                if ($ligne !== null) {
                    $ligne->delete();
                }
            }
            array_splice($this->lignes, $index, 1);
            $this->lignes = array_values($this->lignes);
        }
    }

    public function deleteNdf(): void
    {
        if ($this->noteDeFrais === null) {
            return;
        }

        Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('delete', $this->noteDeFrais);
        app(NoteDeFraisService::class)->delete($this->noteDeFrais);

        session()->flash('portail.success', 'Note de frais supprimée.');
        $this->redirectRoute('portail.ndf.index', ['association' => $this->association->slug]);
    }

    public function getTotalProperty(): float
    {
        $total = 0.0;
        foreach ($this->lignes as $ligne) {
            $montant = $ligne['montant'] ?? null;
            if ($montant !== null && $montant !== '') {
                $total += (float) str_replace(',', '.', (string) $montant);
            }
        }

        return $total;
    }

    public function saveDraft(): void
    {
        /** @var Tiers $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        $service = app(NoteDeFraisService::class);

        $data = $this->buildData();
        $ndf = $service->saveDraft($tiers, $data);

        // Second pass: store uploaded justificatifs
        $this->storeJustificatifs($ndf);

        session()->flash('portail.success', 'Brouillon enregistré.');
        $this->redirectRoute('portail.ndf.index', ['association' => $this->association->slug]);
    }

    public function submit(): void
    {
        /** @var Tiers $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        $service = app(NoteDeFraisService::class);

        $data = $this->buildData();
        $ndf = $service->saveDraft($tiers, $data);

        // Second pass: store uploaded justificatifs
        $this->storeJustificatifs($ndf);

        // Reload NDF with fresh lignes (piece_jointe_path updated)
        $ndf->refresh();

        try {
            $service->submit($ndf);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError('submit', $message);
                }
            }

            return;
        }

        session()->flash('portail.success', 'Note de frais soumise.');
        $this->redirectRoute('portail.ndf.index', ['association' => $this->association->slug]);
    }

    public function render(): View
    {
        $exerciceCourant = app(ExerciceService::class)->current();

        $sousCategories = SousCategorie::whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Depense))
            ->orderBy('nom')
            ->get();

        $operations = Operation::where('statut', '!=', StatutOperation::Cloturee->value)
            ->orderBy('nom')
            ->get();

        $seances = collect();
        if (! empty($this->draftLigne['operation_id'])) {
            $seances = Seance::where('operation_id', (int) $this->draftLigne['operation_id'])
                ->orderBy('date')
                ->get();
        }

        return view('livewire.portail.note-de-frais.form', [
            'sousCategories' => $sousCategories,
            'operations' => $operations,
            'seances' => $seances,
        ])->layout('portail.layouts.app');
    }

    /**
     * Build the $data array for NoteDeFraisService::saveDraft().
     *
     * @return array<string, mixed>
     */
    private function buildData(): array
    {
        $lignesData = [];
        foreach ($this->lignes as $ligne) {
            $lignesData[] = [
                'libelle' => $ligne['libelle'] ?? null,
                'montant' => $ligne['montant'] !== null && $ligne['montant'] !== ''
                    ? (float) str_replace(',', '.', (string) $ligne['montant'])
                    : 0,
                'sous_categorie_id' => $ligne['sous_categorie_id'] ? (int) $ligne['sous_categorie_id'] : null,
                'operation_id' => $ligne['operation_id'] ? (int) $ligne['operation_id'] : null,
                'seance_id' => $ligne['seance_id'] ? (int) $ligne['seance_id'] : null,
                'piece_jointe_path' => $ligne['piece_jointe_path'] ?? null,
            ];
        }

        $data = [
            'date' => $this->dateInput ?? now()->format('Y-m-d'),
            'libelle' => $this->libelle ?? '',
            'lignes' => $lignesData,
        ];

        if ($this->noteDeFrais !== null) {
            $data['id'] = $this->noteDeFrais->id;
        }

        return $data;
    }

    /**
     * Store uploaded justificatifs and update piece_jointe_path on each ligne.
     */
    private function storeJustificatifs(NoteDeFrais $ndf): void
    {
        $assoId = (int) $this->association->id;
        $freshLignes = $ndf->lignes()->orderBy('id')->get();

        foreach ($this->lignes as $i => $ligneData) {
            $justif = $ligneData['justif'] ?? null;
            if (! ($justif instanceof TemporaryUploadedFile)) {
                continue;
            }

            $ligne = $freshLignes->get($i);
            if ($ligne === null) {
                continue;
            }

            $ext = $justif->getClientOriginalExtension();
            $path = "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-{$ligne->id}.{$ext}";
            Storage::disk('local')->put($path, file_get_contents($justif->getRealPath()));

            $ligne->update(['piece_jointe_path' => $path]);
        }
    }

    private function resetDraftLigne(): void
    {
        $this->draftLigne = [
            'justif' => null,
            'libelle' => '',
            'montant' => '',
            'sous_categorie_id' => null,
            'operation_id' => null,
            'seance_id' => null,
        ];
        $this->resetErrorBag('draftLigne.justif');
        $this->resetErrorBag('draftLigne.montant');
        $this->resetErrorBag('draftLigne.sous_categorie_id');
    }
}
