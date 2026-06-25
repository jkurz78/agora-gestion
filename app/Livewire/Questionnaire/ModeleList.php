<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

final class ModeleList extends Component
{
    public bool $showModal = false;

    public string $titre_interne = '';

    public string $titre_affiche = '';

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
        $this->reset(['titre_interne', 'titre_affiche']);
        $this->showModal = true;
    }

    public function save(): RedirectResponse|Redirector
    {
        $data = $this->validate([
            'titre_interne' => 'required|string|max:150',
            'titre_affiche' => 'required|string|max:150',
        ]);

        $model = QuestionnaireTemplate::create($data);

        return redirect()->route('questionnaires.modeles.infos', $model);
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
