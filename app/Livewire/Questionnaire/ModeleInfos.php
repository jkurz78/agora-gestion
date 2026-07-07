<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireTemplate;
use Illuminate\View\View;
use Livewire\Component;

final class ModeleInfos extends Component
{
    public QuestionnaireTemplate $template;

    public string $titreInterne = '';

    public string $titreAffiche = '';

    public bool $anonymise = true;

    public bool $autoriserRetour = true;

    public bool $afficherProgression = true;

    public function mount(QuestionnaireTemplate $template): void
    {
        $this->template = $template;
        $this->titreInterne = $template->titre_interne;
        $this->titreAffiche = $template->titre_affiche;
        $this->anonymise = $template->anonymise;
        $this->autoriserRetour = $template->autoriser_retour;
        $this->afficherProgression = $template->afficher_progression;
    }

    public function enregistrer(): void
    {
        $this->validate([
            'titreInterne' => 'required|string|max:150',
            'titreAffiche' => 'required|string|max:150',
        ]);

        $this->template->update([
            'titre_interne' => $this->titreInterne,
            'titre_affiche' => $this->titreAffiche,
            'anonymise' => $this->anonymise,
            'autoriser_retour' => $this->autoriserRetour,
            'afficher_progression' => $this->afficherProgression,
        ]);

        session()->flash('infos_ok', true);
    }

    public function render(): View
    {
        return view('livewire.questionnaire.modele-infos');
    }
}
