<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Seance;
use App\Services\Emargement\SeanceFeuilleAttacher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

final class SeanceFeuilleAttachment extends Component
{
    use WithFileUploads;

    public ?int $seanceId = null;

    public bool $show = false;

    public $feuilleScan = null;

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()?->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    #[On('open-feuille-modal')]
    public function ouvrir(int $seanceId): void
    {
        $this->seanceId = $seanceId;
        $this->reset('feuilleScan');
        $this->resetValidation();
        $this->show = true;
    }

    public function fermer(): void
    {
        $this->show = false;
    }

    public function envoyer(SeanceFeuilleAttacher $attacher): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->validate([
            'feuilleScan' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $seance = Seance::findOrFail($this->seanceId);
        $tempPath = $this->feuilleScan->getRealPath();
        $originalName = $this->feuilleScan->getClientOriginalName();

        $result = $attacher->attach($tempPath, $originalName, $seance);

        if ($result->success) {
            session()->flash('success', 'Feuille d\'émargement attachée à la séance.');
            $this->fermer();
            $this->dispatch('feuille-updated');
        } else {
            $this->addError('feuilleScan', match ($result->reason) {
                'qr_mismatch' => 'Le QR code du fichier correspond à une autre séance. Vérifie que tu as bien scanné la feuille de cette séance.',
                'qr_not_found' => 'Ce PDF ne contient pas de QR code d\'émargement.',
                'qr_wrong_environment' => 'Ce PDF a été généré dans un autre environnement.',
                'qr_unreadable' => 'Le QR code est présent mais illisible.',
                'pdf_unreadable' => 'Le PDF semble corrompu.',
                default => 'Erreur : '.$result->reason,
            });
        }
    }

    public function retirer(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $seance = Seance::findOrFail($this->seanceId);

        if ($seance->feuille_signee_path !== null) {
            Storage::disk('local')->delete($seance->feuilleSigneeFullPath());
            $seance->update([
                'feuille_signee_path' => null,
                'feuille_signee_at' => null,
                'feuille_signee_source' => null,
                'feuille_signee_sender_email' => null,
            ]);

            Log::info('Feuille signée détachée (unlock)', [
                'seance_id' => $seance->id,
                'user_id' => Auth::id(),
                'user_role' => Auth::user()?->currentRole(),
            ]);
        }

        $this->fermer();
        $this->dispatch('feuille-updated');
    }

    public function render(): View
    {
        $seance = $this->seanceId !== null ? Seance::find($this->seanceId) : null;

        return view('livewire.seance-feuille-attachment', [
            'seance' => $seance,
        ]);
    }
}
