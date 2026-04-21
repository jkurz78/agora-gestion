<?php

declare(strict_types=1);

namespace App\Livewire\Portail\NoteDeFrais;

use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutOperation;
use App\Enums\TypeCategorie;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
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

    /** Stocké comme int pour survivre à la rehydratation Livewire sans TenantContext. */
    public ?int $noteDeFraisId = null;

    public ?string $dateInput = null;

    public ?string $libelle = null;

    /** @var list<array<string, mixed>> */
    public array $lignes = [];

    // ---------------------------------------------------------------------------
    // Wizard d'ajout de ligne
    // ---------------------------------------------------------------------------

    /** 0 = fermé, 1-3 = étape courante du wizard */
    public int $wizardStep = 0;

    /** 'standard' | 'kilometrique' | null */
    public ?string $wizardType = null;

    /** @var array{justif: mixed, libelle: string, montant: string, sous_categorie_id: int|null, operation_id: int|null, seance: int|null, cv_fiscaux: int|null, distance_km: string|null, bareme_eur_km: string|null} */
    public array $draftLigne = [
        'justif' => null,
        'libelle' => '',
        'montant' => '',
        'sous_categorie_id' => null,
        'operation_id' => null,
        'seance' => null,
        'cv_fiscaux' => null,
        'distance_km' => null,
        'bareme_eur_km' => null,
    ];

    public function mount(Association $association, ?NoteDeFrais $noteDeFrais = null): void
    {
        $this->association = $association;

        if ($noteDeFrais !== null) {
            // La policy authorize() couvre Brouillon, Soumise et Rejetee
            Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('update', $noteDeFrais);

            $this->noteDeFraisId = $noteDeFrais->id;
            $this->dateInput = $noteDeFrais->date?->format('Y-m-d');
            $this->libelle = $noteDeFrais->libelle;
            $this->lignes = $noteDeFrais->lignes->map(fn (NoteDeFraisLigne $l) => [
                'id' => $l->id,
                'type' => $l->type->value,
                'sous_categorie_id' => $l->sous_categorie_id,
                'operation_id' => $l->operation_id,
                'seance' => $l->seance,
                'libelle' => $l->libelle,
                'montant' => (string) $l->montant,
                'piece_jointe_path' => $l->piece_jointe_path,
                'cv_fiscaux' => $l->metadata['cv_fiscaux'] ?? null,
                'distance_km' => $l->metadata['distance_km'] ?? null,
                'bareme_eur_km' => $l->metadata['bareme_eur_km'] ?? null,
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
        $this->wizardType = 'standard';
        $this->wizardStep = 1;
        $this->dispatch('ligne-wizard-opened');
    }

    public function openKilometriqueWizard(): void
    {
        $this->resetDraftLigne();
        $this->wizardType = 'kilometrique';
        $this->wizardStep = 1;
        $this->dispatch('ligne-wizard-opened');
    }

    public function cancelLigneWizard(): void
    {
        $this->resetDraftLigne();
        $this->wizardType = null;
        $this->wizardStep = 0;
        $this->dispatch('ligne-wizard-closed');
    }

    public function wizardNext(): void
    {
        if ($this->wizardStep === 1) {
            $this->validateOnly('draftLigne.justif', [
                'draftLigne.justif' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic', 'max:5120'],
            ], [
                'draftLigne.justif.required' => $this->wizardType === 'kilometrique'
                    ? 'La carte grise est obligatoire.'
                    : 'Un justificatif est obligatoire.',
                'draftLigne.justif.file' => 'Le fichier est invalide.',
                'draftLigne.justif.mimes' => 'Formats acceptés : PDF, JPG, PNG, HEIC.',
                'draftLigne.justif.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
            ]);

            if ($this->wizardType !== 'kilometrique') {
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

            // kilometrique : pas d'OCR
            $this->wizardStep = 2;

            return;
        }

        if ($this->wizardStep === 2 && $this->wizardType !== 'kilometrique') {
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
        if ($this->wizardType === 'kilometrique') {
            // Normalize comma decimals before validation
            if (is_string($this->draftLigne['distance_km'])) {
                $this->draftLigne['distance_km'] = str_replace(',', '.', $this->draftLigne['distance_km']);
            }
            if (is_string($this->draftLigne['bareme_eur_km'])) {
                $this->draftLigne['bareme_eur_km'] = str_replace(',', '.', $this->draftLigne['bareme_eur_km']);
            }

            $this->validate([
                'draftLigne.libelle' => ['required', 'string', 'min:1'],
                'draftLigne.cv_fiscaux' => ['required', 'integer', 'between:1,50'],
                'draftLigne.distance_km' => ['required', 'numeric', 'gt:0'],
                'draftLigne.bareme_eur_km' => ['required', 'numeric', 'gt:0'],
            ], [
                'draftLigne.libelle.required' => 'Le libellé est obligatoire.',
                'draftLigne.cv_fiscaux.required' => 'La puissance fiscale est obligatoire.',
                'draftLigne.cv_fiscaux.integer' => 'La puissance fiscale doit être un entier.',
                'draftLigne.cv_fiscaux.between' => 'La puissance fiscale doit être entre 1 et 50 CV.',
                'draftLigne.distance_km.required' => 'La distance est obligatoire.',
                'draftLigne.distance_km.numeric' => 'La distance doit être un nombre.',
                'draftLigne.distance_km.gt' => 'La distance doit être supérieure à zéro.',
                'draftLigne.bareme_eur_km.required' => 'Le barème est obligatoire.',
                'draftLigne.bareme_eur_km.numeric' => 'Le barème doit être un nombre.',
                'draftLigne.bareme_eur_km.gt' => 'Le barème doit être supérieur à zéro.',
            ]);

            $this->lignes[] = [
                'id' => null,
                'type' => 'kilometrique',
                'sous_categorie_id' => null,
                'operation_id' => $this->draftLigne['operation_id'] ?? null,
                'seance' => $this->draftLigne['seance'] ?? null,
                'libelle' => $this->draftLigne['libelle'],
                'montant' => (string) $this->draftMontantCalcule,
                'cv_fiscaux' => (int) $this->draftLigne['cv_fiscaux'],
                'distance_km' => $this->toFloatNormalized($this->draftLigne['distance_km']),
                'bareme_eur_km' => $this->toFloatNormalized($this->draftLigne['bareme_eur_km']),
                'piece_jointe_path' => null,
                'justif' => $this->draftLigne['justif'],
            ];

            $this->resetDraftLigne();
            $this->wizardStep = 0;
            $this->wizardType = null;
            $this->dispatch('ligne-wizard-closed');

            return;
        }

        // Flux standard existant
        $this->validateOnly('draftLigne.sous_categorie_id', [
            'draftLigne.sous_categorie_id' => ['required'],
        ], [
            'draftLigne.sous_categorie_id.required' => 'La sous-catégorie est obligatoire.',
        ]);

        $this->lignes[] = [
            'id' => null,
            'type' => 'standard',
            'sous_categorie_id' => $this->draftLigne['sous_categorie_id'],
            'operation_id' => $this->draftLigne['operation_id'],
            'seance' => $this->draftLigne['seance'],
            'libelle' => $this->draftLigne['libelle'],
            'montant' => $this->draftLigne['montant'],
            'piece_jointe_path' => null,
            'justif' => $this->draftLigne['justif'],
        ];

        $this->resetDraftLigne();
        $this->wizardStep = 0;
        $this->wizardType = null;
        $this->dispatch('ligne-wizard-closed');
    }

    // ---------------------------------------------------------------------------
    // Ligne methods (inline)
    // ---------------------------------------------------------------------------

    public function addLigne(): void
    {
        $this->lignes[] = [
            'id' => null,
            'type' => 'standard',
            'sous_categorie_id' => null,
            'operation_id' => null,
            'seance' => null,
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
        $ndf = $this->getNoteDeFrais();
        if ($ndf === null) {
            return;
        }

        Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('delete', $ndf);
        app(NoteDeFraisService::class)->delete($ndf);

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

    public function getDraftMontantCalculeProperty(): float
    {
        if ($this->wizardType !== 'kilometrique') {
            return 0.0;
        }

        $registry = app(LigneTypeRegistry::class);
        $strategy = $registry->for(NoteDeFraisLigneType::Kilometrique);

        return $strategy->computeMontant([
            'distance_km' => $this->draftLigne['distance_km'] ?? 0,
            'bareme_eur_km' => $this->draftLigne['bareme_eur_km'] ?? 0,
        ]);
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
        $this->noteDeFraisId = (int) $ndf->id;

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

        $selectedOperation = ! empty($this->draftLigne['operation_id'])
            ? Operation::find((int) $this->draftLigne['operation_id'])
            : null;

        return view('livewire.portail.note-de-frais.form', [
            'sousCategories' => $sousCategories,
            'operations' => $operations,
            'selectedOperation' => $selectedOperation,
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
                'type' => $ligne['type'] ?? 'standard',
                'libelle' => $ligne['libelle'] ?? null,
                'montant' => $ligne['montant'] !== null && $ligne['montant'] !== ''
                    ? (float) str_replace(',', '.', (string) $ligne['montant'])
                    : 0,
                'sous_categorie_id' => $ligne['sous_categorie_id'] ? (int) $ligne['sous_categorie_id'] : null,
                'operation_id' => $ligne['operation_id'] ? (int) $ligne['operation_id'] : null,
                'seance' => $ligne['seance'] ? (int) $ligne['seance'] : null,
                'piece_jointe_path' => $ligne['piece_jointe_path'] ?? null,
                'cv_fiscaux' => $ligne['cv_fiscaux'] ?? null,
                'distance_km' => $ligne['distance_km'] ?? null,
                'bareme_eur_km' => $ligne['bareme_eur_km'] ?? null,
            ];
        }

        $data = [
            'date' => $this->dateInput ?? now()->format('Y-m-d'),
            'libelle' => $this->libelle ?? '',
            'lignes' => $lignesData,
        ];

        if ($this->noteDeFraisId !== null) {
            $data['id'] = $this->noteDeFraisId;
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

    private function getNoteDeFrais(): ?NoteDeFrais
    {
        return $this->noteDeFraisId !== null ? NoteDeFrais::find($this->noteDeFraisId) : null;
    }

    private function resetDraftLigne(): void
    {
        $this->draftLigne = [
            'justif' => null,
            'libelle' => '',
            'montant' => '',
            'sous_categorie_id' => null,
            'operation_id' => null,
            'seance' => null,
            'cv_fiscaux' => null,
            'distance_km' => null,
            'bareme_eur_km' => null,
        ];
        $this->resetErrorBag();
    }

    private function toFloatNormalized(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }
}
