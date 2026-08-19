<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Exceptions\ExerciceCloturedException;
use App\Livewire\Concerns\MontantValidation;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\CompteBancaire;
use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\VirementInterneService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class VirementInterneForm extends Component
{
    use RespectsExerciceCloture;

    public ?int $virementId = null;

    public string $date = '';

    public string $montant = '';

    public ?int $compte_source_id = null;

    public ?int $compte_destination_id = null;

    public ?string $reference = null;

    public ?string $notes = null;

    public bool $showForm = false;

    #[On('open-virement-form')]
    public function open(?int $id = null): void
    {
        $this->resetForm();
        if ($id !== null) {
            $virement = VirementInterne::findOrFail($id);
            $this->virementId = $virement->id;
            $this->date = $virement->date->format('Y-m-d');
            $this->montant = (string) $virement->montant;
            $this->compte_source_id = $virement->compte_source_id;
            $this->compte_destination_id = $virement->compte_destination_id;
            $this->reference = $virement->reference;
            $this->notes = $virement->notes;
        } else {
            $this->date = app(ExerciceService::class)->defaultDate();
        }
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'virementId', 'date', 'montant', 'compte_source_id',
            'compte_destination_id', 'reference', 'notes', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        // Plus de bornes d'exercice affiché — seul l'exercice de la date
        // saisie compte, et seule sa clôture peut refuser la saisie
        // (ExerciceCloturedException, attrapée plus bas).
        $this->validate([
            'date' => ['required', 'date'],
            'montant' => ['required', 'numeric', MontantValidation::RULE],
            'compte_source_id' => ['required', 'exists:comptes_bancaires,id'],
            'compte_destination_id' => [
                'required',
                'exists:comptes_bancaires,id',
                'different:compte_source_id',
            ],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], MontantValidation::messages(['montant']));

        $data = [
            'date' => $this->date,
            'montant' => $this->montant,
            'compte_source_id' => $this->compte_source_id,
            'compte_destination_id' => $this->compte_destination_id,
            'reference' => $this->reference ?: null,
            'notes' => $this->notes ?: null,
        ];

        $service = app(VirementInterneService::class);

        try {
            if ($this->virementId) {
                $virement = VirementInterne::findOrFail($this->virementId);
                $service->update($virement, $data);
            } else {
                $service->create($data);
            }
        } catch (ExerciceCloturedException $e) {
            // Le refus porte sur la DATE saisie — c'est elle qui est en cause.
            $this->addError('date', $e->getMessage());

            return;
        }

        $this->dispatch('virement-saved');
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.virement-interne-form', [
            'comptes' => CompteBancaire::saisieManuelle()->orderBy('nom')->get(),
        ]);
    }
}
