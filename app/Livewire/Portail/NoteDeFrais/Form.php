<?php

declare(strict_types=1);

namespace App\Livewire\Portail\NoteDeFrais;

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutOperation;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\ExerciceService;
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

    public function mount(Association $association, ?NoteDeFrais $noteDeFrais = null): void
    {
        $this->association = $association;

        if ($noteDeFrais !== null) {
            Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('update', $noteDeFrais);

            // Seul un brouillon peut être édité
            if ($noteDeFrais->statut !== StatutNoteDeFrais::Brouillon) {
                abort(403, 'Seul un brouillon peut être modifié.');
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
            $this->addLigne();
        }
    }

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
        $this->redirectRoute('portail.ndf.show', [
            'association' => $this->association->slug,
            'noteDeFrais' => $ndf->id,
        ]);
    }

    public function render(): View
    {
        $exerciceCourant = app(ExerciceService::class)->current();

        $sousCategories = SousCategorie::orderBy('nom')->get();

        $operations = Operation::where('statut', '!=', StatutOperation::Cloturee->value)
            ->orderBy('nom')
            ->get();

        return view('livewire.portail.note-de-frais.form', [
            'sousCategories' => $sousCategories,
            'operations' => $operations,
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
}
