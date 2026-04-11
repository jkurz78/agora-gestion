<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Models\MessageTemplate;
use App\Models\Operation;
use App\Models\Participant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class OperationCommunication extends Component
{
    public Operation $operation;

    // Composition
    public string $objet = '';

    public string $corps = '';

    public ?int $selectedTemplateId = null;

    // Participant selection
    /** @var array<int> */
    public array $selectedParticipants = [];

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        $this->initParticipants();
    }

    private function initParticipants(): void
    {
        // Select all participants that have an email
        $this->selectedParticipants = $this->getParticipantsWithEmail()
            ->pluck('id')
            ->toArray();
    }

    public function getParticipantsWithEmail(): Collection
    {
        return $this->operation->participants()
            ->with('tiers')
            ->get()
            ->filter(fn (Participant $p) => ! empty($p->tiers?->email));
    }

    public function getAllParticipants(): Collection
    {
        return $this->operation->participants()
            ->with('tiers')
            ->get();
    }

    public function toggleSelectAll(): void
    {
        $withEmail = $this->getParticipantsWithEmail()->pluck('id')->toArray();
        if (count($this->selectedParticipants) === count($withEmail)) {
            $this->selectedParticipants = [];
        } else {
            $this->selectedParticipants = $withEmail;
        }
    }

    public function loadTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            return;
        }
        $template = MessageTemplate::find($this->selectedTemplateId);
        if ($template) {
            $this->objet = $template->objet;
            $this->corps = $template->corps;
        }
    }

    public function getAvailableTemplates(): Collection
    {
        return MessageTemplate::with('typeOperation')
            ->orderBy('nom')
            ->get()
            ->groupBy(fn (MessageTemplate $t) => $t->typeOperation?->nom ?? 'Modèles généraux');
    }

    public function render(): View
    {
        return view('livewire.operation-communication', [
            'participants' => $this->getAllParticipants(),
            'participantsWithEmailCount' => $this->getParticipantsWithEmail()->count(),
            'templates' => $this->getAvailableTemplates(),
            'messageVariables' => CategorieEmail::Message->variables(),
        ]);
    }
}
