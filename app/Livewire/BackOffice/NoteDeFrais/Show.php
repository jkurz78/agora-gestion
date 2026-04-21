<?php

declare(strict_types=1);

namespace App\Livewire\BackOffice\NoteDeFrais;

use App\Enums\ModePaiement;
use App\Enums\StatutNoteDeFrais;
use App\Enums\UsageComptable;
use App\Exceptions\ExerciceCloturedException;
use App\Models\CompteBancaire;
use App\Models\NoteDeFrais;
use App\Models\SousCategorie;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use App\Services\NoteDeFrais\ValidationData;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

final class Show extends Component
{
    public NoteDeFrais $ndf;

    public bool $showMiniForm = false;

    public bool $showRejectModal = false;

    public ?int $compteId = null;

    public string $modePaiement = 'virement';

    public string $dateComptabilisation = '';

    public string $dateDon = '';

    public string $choixValidation = 'normal';

    public string $motifRejet = '';

    public function mount(NoteDeFrais $noteDeFrais): void
    {
        $this->authorize('treat', $noteDeFrais);
        $this->ndf = $noteDeFrais;
        $this->dateComptabilisation = $noteDeFrais->date->format('Y-m-d');
        $this->dateDon = $noteDeFrais->date->format('Y-m-d');
    }

    public function openMiniForm(): void
    {
        $this->showMiniForm = true;
    }

    public function setDateToday(): void
    {
        $this->dateComptabilisation = today()->format('Y-m-d');
    }

    public function setDateDonToday(): void
    {
        $this->dateDon = today()->format('Y-m-d');
    }

    public function confirmValidation(): void
    {
        $associationId = (int) $this->ndf->association_id;

        $isAbandon = $this->ndf->abandon_creance_propose && $this->choixValidation === 'abandon';

        $rules = [
            'compteId' => [
                'required',
                'integer',
                'exists:comptes_bancaires,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($associationId): void {
                    $exists = CompteBancaire::where('id', (int) $value)
                        ->where('association_id', $associationId)
                        ->exists();
                    if (! $exists) {
                        $fail('Le compte bancaire sélectionné est invalide.');
                    }
                },
            ],
            'modePaiement' => [
                'required',
                'string',
                'in:'.implode(',', array_column(ModePaiement::cases(), 'value')),
            ],
            'dateComptabilisation' => ['required', 'date'],
        ];

        $messages = [
            'compteId.required' => 'Veuillez sélectionner un compte bancaire.',
            'compteId.exists' => 'Le compte bancaire sélectionné est invalide.',
            'modePaiement.required' => 'Veuillez sélectionner un mode de règlement.',
            'modePaiement.in' => 'Le mode de règlement sélectionné est invalide.',
            'dateComptabilisation.required' => 'La date de comptabilisation est obligatoire.',
            'dateComptabilisation.date' => 'La date de comptabilisation est invalide.',
        ];

        if ($isAbandon) {
            $rules['dateDon'] = ['required', 'date'];
            $rules['choixValidation'] = ['required', 'in:normal,abandon'];
            $messages['dateDon.required'] = 'La date du don est obligatoire.';
            $messages['dateDon.date'] = 'La date du don est invalide.';
        }

        $this->validate($rules, $messages);

        try {
            $data = new ValidationData(
                compte_id: (int) $this->compteId,
                mode_paiement: ModePaiement::from($this->modePaiement),
                date: $this->dateComptabilisation,
            );

            /** @var NoteDeFraisValidationService $service */
            $service = app(NoteDeFraisValidationService::class);

            if ($isAbandon) {
                $service->validerAvecAbandonCreance($this->ndf, $data, $this->dateDon);
                $flashMsg = 'La note de frais a été validée et l\'abandon de créance constaté.';
            } else {
                $service->valider($this->ndf, $data);
                $flashMsg = 'La note de frais a été validée et comptabilisée avec succès.';
            }

            $this->ndf->refresh();
            $this->showMiniForm = false;
            session()->flash('success', $flashMsg);
        } catch (ExerciceCloturedException $e) {
            session()->flash('error', $e->getMessage());
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            session()->flash('error', 'Une erreur inattendue s\'est produite : '.$e->getMessage());
        }
    }

    public function openRejectModal(): void
    {
        $this->showRejectModal = true;
    }

    public function confirmRejection(): void
    {
        $this->validate([
            'motifRejet' => ['required', 'string', 'min:1'],
        ], [
            'motifRejet.required' => 'Le motif est obligatoire.',
            'motifRejet.min' => 'Le motif est obligatoire.',
        ]);

        try {
            /** @var NoteDeFraisValidationService $service */
            $service = app(NoteDeFraisValidationService::class);
            $service->rejeter($this->ndf, $this->motifRejet);

            session()->flash('success', 'La note de frais a été rejetée.');

            $this->redirect(route('comptabilite.ndf.index'), navigate: false);
        } catch (ValidationException $e) {
            session()->flash('error', $e->getMessage());
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            session()->flash('error', 'Une erreur inattendue s\'est produite : '.$e->getMessage());
        }
    }

    /** @return Collection<int, CompteBancaire> */
    private function comptesBancaires(): Collection
    {
        return CompteBancaire::where('actif_recettes_depenses', true)
            ->where('est_systeme', false)
            ->orderBy('nom')
            ->get();
    }

    private function sousCatAbandon(): ?SousCategorie
    {
        return $this->ndf->association
            ->sousCategoriesFor(UsageComptable::AbandonCreance)
            ->first();
    }

    public function render(): View
    {
        $this->ndf->loadMissing(['tiers', 'lignes', 'transaction', 'association']);

        return view('livewire.back-office.note-de-frais.show', [
            'comptesBancaires' => $this->comptesBancaires(),
            'modesPaiement' => ModePaiement::cases(),
            'statutSoumise' => StatutNoteDeFrais::Soumise,
            'statutValidee' => StatutNoteDeFrais::Validee,
            'statutRejetee' => StatutNoteDeFrais::Rejetee,
            'sousCatAbandon' => $this->sousCatAbandon(),
        ])->layout('layouts.app-sidebar', ['title' => 'Note de frais — Détail']);
    }
}
