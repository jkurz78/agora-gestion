<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class ParticipantTable extends Component
{
    public Operation $operation;

    // ── Add modal ──────────────────────────────────────────────
    public bool $showAddModal = false;

    public ?int $addTiersId = null;

    public string $addDateInscription = '';

    public string $addNom = '';

    public string $addPrenom = '';

    public string $addAdresse = '';

    public string $addCodePostal = '';

    public string $addVille = '';

    public string $addTelephone = '';

    public string $addEmail = '';

    // ── Edit modal ─────────────────────────────────────────────
    public bool $showEditModal = false;

    public ?int $editParticipantId = null;

    public string $editNom = '';

    public string $editPrenom = '';

    public string $editAdresse = '';

    public string $editCodePostal = '';

    public string $editVille = '';

    public string $editTelephone = '';

    public string $editEmail = '';

    public string $editDateInscription = '';

    public ?int $editReferePar = null;

    // Medical fields (edit modal)
    public string $editDateNaissance = '';

    public string $editSexe = '';

    public string $editTaille = '';

    public string $editPoids = '';

    // ── Notes modal ────────────────────────────────────────────
    public bool $showNotesModal = false;

    public ?int $notesParticipantId = null;

    public string $medNotes = '';

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function render(): View
    {
        $canSeeSensible = (bool) Auth::user()?->peut_voir_donnees_sensibles;

        $query = Participant::where('operation_id', $this->operation->id)
            ->with(['tiers', 'referePar']);

        if ($canSeeSensible) {
            $query->with('donneesMedicales');
        }

        $participants = $query->orderBy('id')->get();

        return view('livewire.participant-table', [
            'participants' => $participants,
            'canSeeSensible' => $canSeeSensible,
        ]);
    }

    // ── Add modal ──────────────────────────────────────────────

    public function openAddModal(): void
    {
        $this->resetAddFields();
        $this->showAddModal = true;
    }

    public function updatedAddTiersId(?int $value): void
    {
        if ($value === null) {
            return;
        }

        $this->quickAddParticipant($value);
    }

    public function addParticipant(): void
    {
        if ($this->addTiersId === null) {
            $this->addError('addTiersId', 'Veuillez sélectionner un tiers.');

            return;
        }

        $this->quickAddParticipant($this->addTiersId);
    }

    private function quickAddParticipant(int $tiersId): void
    {
        $exists = Participant::where('tiers_id', $tiersId)
            ->where('operation_id', $this->operation->id)
            ->exists();

        if ($exists) {
            $this->addError('addTiersId', 'Ce tiers est déjà inscrit à cette opération.');

            return;
        }

        Participant::create([
            'tiers_id' => $tiersId,
            'operation_id' => $this->operation->id,
            'date_inscription' => now()->toDateString(),
        ]);

        $this->showAddModal = false;
        $this->resetAddFields();
    }

    // ── Edit modal ─────────────────────────────────────────────

    public function openEditModal(int $participantId): void
    {
        $participant = Participant::with(['tiers', 'donneesMedicales', 'referePar'])
            ->findOrFail($participantId);

        $this->editParticipantId = $participant->id;
        $this->editNom = $participant->tiers->nom ?? '';
        $this->editPrenom = $participant->tiers->prenom ?? '';
        $this->editAdresse = $participant->tiers->adresse_ligne1 ?? '';
        $this->editCodePostal = $participant->tiers->code_postal ?? '';
        $this->editVille = $participant->tiers->ville ?? '';
        $this->editTelephone = $participant->tiers->telephone ?? '';
        $this->editEmail = $participant->tiers->email ?? '';
        $this->editDateInscription = $participant->date_inscription->format('Y-m-d');
        $this->editReferePar = $participant->refere_par_id;

        // Medical data
        $med = $participant->donneesMedicales;
        $this->editDateNaissance = $med?->date_naissance ?? '';
        $this->editSexe = $med?->sexe ?? '';
        $this->editTaille = $med?->taille ?? '';
        $this->editPoids = $med?->poids ?? '';

        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $participant = Participant::with('tiers')->findOrFail($this->editParticipantId);

        // Update tiers
        $participant->tiers->update([
            'nom' => $this->editNom,
            'prenom' => $this->editPrenom,
            'adresse_ligne1' => $this->editAdresse,
            'code_postal' => $this->editCodePostal,
            'ville' => $this->editVille,
            'telephone' => $this->editTelephone,
            'email' => $this->editEmail,
        ]);

        // Update participant
        $participant->update([
            'date_inscription' => $this->editDateInscription,
            'refere_par_id' => $this->editReferePar,
        ]);

        // Update medical data if user has permission
        if (Auth::user()?->peut_voir_donnees_sensibles) {
            $med = $participant->donneesMedicales ?? new ParticipantDonneesMedicales([
                'participant_id' => $participant->id,
            ]);
            $med->fill([
                'date_naissance' => $this->editDateNaissance !== '' ? $this->editDateNaissance : null,
                'sexe' => $this->editSexe !== '' ? $this->editSexe : null,
                'taille' => $this->editTaille !== '' ? $this->editTaille : null,
                'poids' => $this->editPoids !== '' ? $this->editPoids : null,
            ]);
            $med->save();
        }

        $this->showEditModal = false;
    }

    // ── Inline field updates ───────────────────────────────────

    public function updateTiersField(int $participantId, string $field, string $value): void
    {
        $allowed = ['nom', 'prenom', 'telephone', 'email'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $participant->tiers->update([$field => $value]);
    }

    public function updateParticipantField(int $participantId, string $field, string $value): void
    {
        $allowed = ['date_inscription'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $participant->update([$field => $value]);
    }

    public function updateMedicalField(int $participantId, string $field, string $value): void
    {
        if (! Auth::user()?->peut_voir_donnees_sensibles) {
            return;
        }

        $allowed = ['date_naissance', 'sexe', 'taille', 'poids'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $med = $participant->donneesMedicales ?? ParticipantDonneesMedicales::create([
            'participant_id' => $participant->id,
        ]);

        $med->update([$field => $value !== '' ? $value : null]);
    }

    // ── Remove ─────────────────────────────────────────────────

    public function removeParticipant(int $id): void
    {
        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($id);

        $participant->delete();
    }

    // ── Notes modal ────────────────────────────────────────────

    public function openNotesModal(int $participantId): void
    {
        $participant = Participant::with('donneesMedicales')->findOrFail($participantId);
        $this->notesParticipantId = $participant->id;
        $this->medNotes = $participant->donneesMedicales?->notes ?? '';
        $this->showNotesModal = true;
    }

    public function saveNotes(): void
    {
        if (! Auth::user()?->peut_voir_donnees_sensibles) {
            return;
        }

        $participant = Participant::findOrFail($this->notesParticipantId);

        $med = $participant->donneesMedicales ?? ParticipantDonneesMedicales::create([
            'participant_id' => $participant->id,
        ]);

        $med->update(['notes' => $this->medNotes !== '' ? $this->medNotes : null]);

        $this->showNotesModal = false;
    }

    // ── Helpers ────────────────────────────────────────────────

    private function resetAddFields(): void
    {
        $this->addTiersId = null;
        $this->addDateInscription = '';
        $this->addNom = '';
        $this->addPrenom = '';
        $this->addAdresse = '';
        $this->addCodePostal = '';
        $this->addVille = '';
        $this->addTelephone = '';
        $this->addEmail = '';
    }
}
