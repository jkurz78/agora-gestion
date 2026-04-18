<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Participant;
use App\Models\ParticipantDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class ParticipantEngagementUpload extends Component
{
    use WithFileUploads;

    public int $participantId;

    public string $label = 'Formulaire papier';

    /** @var TemporaryUploadedFile|null */
    public $scanFormulaire = null;

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()?->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    public function updatedScanFormulaire(): void
    {
        $this->validate([
            'scanFormulaire' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
    }

    public function enregistrer(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->validate([
            'scanFormulaire' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $participant = Participant::findOrFail($this->participantId);
        $originalName = $this->scanFormulaire->getClientOriginalName();
        $extension = $this->scanFormulaire->getClientOriginalExtension();
        $filename = 'doc-'.now()->format('Y-m-d-His').'.'.$extension;

        $tenantDir = 'associations/'.$participant->association_id.'/participants/'.$participant->id;
        $this->scanFormulaire->storeAs($tenantDir, $filename, 'local');

        ParticipantDocument::create([
            'association_id' => $participant->association_id,
            'participant_id' => $participant->id,
            'label' => $this->label,
            'storage_path' => $filename,
            'original_filename' => $originalName,
            'source' => 'manual-upload',
        ]);

        $this->reset('scanFormulaire', 'label');
        $this->label = 'Formulaire papier';
        $this->dispatch('document-uploaded');
    }

    public function render(): View
    {
        $labelSuggestions = ParticipantDocument::distinct()
            ->pluck('label')
            ->sort()
            ->values();

        return view('livewire.participant-engagement-upload', [
            'labelSuggestions' => $labelSuggestions,
        ]);
    }
}
