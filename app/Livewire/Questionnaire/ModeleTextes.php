<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\EmailTemplate;
use App\Models\QuestionnaireTemplate;
use Illuminate\View\View;
use Livewire\Component;

final class ModeleTextes extends Component
{
    public QuestionnaireTemplate $template;

    public string $titreAffiche = '';

    public string $intro = '';

    public string $remerciement = '';

    public function mount(QuestionnaireTemplate $template): void
    {
        $this->template = $template;
        $this->titreAffiche = $template->titre_affiche ?? '';
        $this->intro = $template->intro ?? '';
        $this->remerciement = $template->remerciement ?? '';
    }

    public function enregistrer(): void
    {
        $this->template->update([
            'titre_affiche' => $this->titreAffiche,
            'intro' => $this->intro === '' ? null : EmailTemplate::sanitizeCorps($this->intro),
            'remerciement' => $this->remerciement === '' ? null : EmailTemplate::sanitizeCorps($this->remerciement),
        ]);

        session()->flash('textes_ok', true);
    }

    public function render(): View
    {
        return view('livewire.questionnaire.modele-textes');
    }
}
