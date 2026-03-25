<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class ParticipantList extends Component
{
    public Operation $operation;

    public ?int $selectedTiersId = null;

    public string $dateInscription = '';

    public string $notes = '';

    public bool $showAddModal = false;

    public bool $showMedicalModal = false;

    public ?int $editingParticipantId = null;

    public string $medDateNaissance = '';

    public string $medSexe = '';

    public string $medPoids = '';

    public function openAddModal(): void
    {
        $this->resetValidation();
        $this->selectedTiersId = null;
        $this->dateInscription = now()->format('Y-m-d');
        $this->notes = '';
        $this->showAddModal = true;
    }

    public function addParticipant(): void
    {
        $this->validate([
            'selectedTiersId' => ['required', 'exists:tiers,id'],
            'dateInscription' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'selectedTiersId.required' => 'Veuillez sélectionner un participant.',
            'selectedTiersId.exists' => 'Le tiers sélectionné est invalide.',
            'dateInscription.required' => 'La date d\'inscription est requise.',
            'dateInscription.date' => 'La date d\'inscription est invalide.',
        ]);

        $exists = Participant::where('tiers_id', $this->selectedTiersId)
            ->where('operation_id', $this->operation->id)
            ->exists();

        if ($exists) {
            $this->addError('selectedTiersId', 'Ce participant est déjà inscrit à cette opération.');

            return;
        }

        Participant::create([
            'tiers_id' => $this->selectedTiersId,
            'operation_id' => $this->operation->id,
            'date_inscription' => $this->dateInscription,
            'notes' => $this->notes ?: null,
        ]);

        $this->showAddModal = false;
        $this->reset(['selectedTiersId', 'dateInscription', 'notes']);
    }

    public function removeParticipant(int $id): void
    {
        $participant = Participant::where('id', $id)
            ->where('operation_id', $this->operation->id)
            ->firstOrFail();

        $participant->delete();
    }

    public function openMedicalModal(int $participantId): void
    {
        $this->resetValidation();
        $this->editingParticipantId = $participantId;

        $participant = Participant::with('donneesMedicales')->findOrFail($participantId);
        $medical = $participant->donneesMedicales;

        $this->medDateNaissance = $medical?->date_naissance ?? '';
        $this->medSexe = $medical?->sexe ?? '';
        $this->medPoids = $medical?->poids ?? '';
        $this->showMedicalModal = true;
    }

    public function saveMedicalData(): void
    {
        $this->validate([
            'medDateNaissance' => ['nullable', 'date'],
            'medSexe' => ['nullable', 'string', 'in:M,F'],
            'medPoids' => ['nullable', 'string', 'max:10'],
        ], [
            'medDateNaissance.date' => 'La date de naissance est invalide.',
            'medSexe.in' => 'Le sexe doit être M ou F.',
        ]);

        ParticipantDonneesMedicales::updateOrCreate(
            ['participant_id' => $this->editingParticipantId],
            [
                'date_naissance' => $this->medDateNaissance ?: null,
                'sexe' => $this->medSexe ?: null,
                'poids' => $this->medPoids ?: null,
            ],
        );

        $this->showMedicalModal = false;
        $this->reset(['editingParticipantId', 'medDateNaissance', 'medSexe', 'medPoids']);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $canSeeSensible = (bool) $user->peut_voir_donnees_sensibles;

        $query = Participant::with('tiers')
            ->where('operation_id', $this->operation->id);

        if ($canSeeSensible) {
            $query->with('donneesMedicales');
        }

        $participants = $query->orderBy('created_at', 'desc')->get();

        return view('livewire.participant-list', [
            'participants' => $participants,
            'canSeeSensible' => $canSeeSensible,
        ]);
    }
}
