<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireTemplate;
use Illuminate\View\View;
use Livewire\Component;

final class ModeleList extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $titre_interne = '';

    public string $titre_affiche = '';

    public string $intro = '';

    public string $remerciement = '';

    public function render(): View
    {
        return view('livewire.questionnaire.modele-list', [
            'modeles' => QuestionnaireTemplate::withCount('questions')
                ->orderBy('titre_interne')
                ->get(),
        ]);
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'titre_interne', 'titre_affiche', 'intro', 'remerciement']);
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $m = QuestionnaireTemplate::findOrFail($id);
        $this->editingId = (int) $m->id;
        $this->titre_interne = $m->titre_interne;
        $this->titre_affiche = $m->titre_affiche;
        $this->intro = $m->intro ?? '';
        $this->remerciement = $m->remerciement ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'titre_interne' => 'required|string|max:150',
            'titre_affiche' => 'required|string|max:150',
            'intro' => 'nullable|string',
            'remerciement' => 'nullable|string',
        ]);

        if ($this->editingId !== null) {
            QuestionnaireTemplate::findOrFail($this->editingId)->update($data);
        } else {
            QuestionnaireTemplate::create($data);
        }

        $this->showModal = false;
    }

    public function toggleActif(int $id): void
    {
        $m = QuestionnaireTemplate::findOrFail($id);
        $m->update(['actif' => ! $m->actif]);
    }

    public function supprimer(int $id): void
    {
        QuestionnaireTemplate::findOrFail($id)->delete();
    }
}
