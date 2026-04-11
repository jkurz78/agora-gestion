<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Models\MessageTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
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

    // Save as template
    public bool $showSaveTemplate = false;

    public string $templateNom = '';

    public ?int $templateTypeOperationId = null;

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
            $this->dispatch('template-loaded', corps: $this->corps);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getUnresolvedVariables(): array
    {
        if (empty($this->corps)) {
            return [];
        }

        $operation = $this->operation->loadMissing('seances');
        $seances = $operation->seances->sortBy('date');
        $today = now()->startOfDay();

        $prochaine = $seances->first(fn (Seance $s) => $s->date && $s->date->gte($today));
        $precedente = $seances->last(fn (Seance $s) => $s->date && $s->date->lt($today));

        $values = [
            '{date_prochaine_seance}' => $prochaine?->date?->format('d/m/Y') ?? '',
            '{date_precedente_seance}' => $precedente?->date?->format('d/m/Y') ?? '',
            '{numero_prochaine_seance}' => $prochaine ? (string) $prochaine->numero : '',
            '{numero_precedente_seance}' => $precedente ? (string) $precedente->numero : '',
        ];

        $unresolved = [];
        foreach ($values as $var => $value) {
            if ($value === '' && str_contains($this->corps, $var)) {
                $unresolved[] = $var;
            }
        }

        return $unresolved;
    }

    public function saveAsTemplate(): void
    {
        $this->validate([
            'templateNom' => 'required|string|max:100',
            'objet' => 'required|string|max:255',
            'corps' => 'required|string',
        ]);

        MessageTemplate::create([
            'nom' => $this->templateNom,
            'objet' => $this->objet,
            'corps' => $this->corps,
            'type_operation_id' => $this->templateTypeOperationId,
        ]);

        $this->showSaveTemplate = false;
        $this->templateNom = '';
        $this->templateTypeOperationId = null;

        session()->flash('message', 'Modèle enregistré.');
    }

    public function updateTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            return;
        }

        $template = MessageTemplate::find($this->selectedTemplateId);
        if ($template) {
            $template->update([
                'objet' => $this->objet,
                'corps' => $this->corps,
            ]);
            session()->flash('message', 'Modèle mis à jour.');
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
            'unresolvedVariables' => $this->getUnresolvedVariables(),
        ]);
    }
}
