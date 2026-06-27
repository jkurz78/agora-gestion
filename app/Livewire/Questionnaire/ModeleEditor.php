<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

final class ModeleEditor extends Component
{
    public QuestionnaireTemplate $template;

    public string $libelle = '';

    public string $aide = '';

    public string $type = 'texte_court';

    public bool $obligatoire = false;

    /** Une option par ligne (saisie brute admin) — pour les choix uniques. */
    public string $optionsBrut = '';

    /** Commentaire optionnel (satisfaction uniquement). */
    public bool $commentaire = false;

    public string $commentaireLibelle = '';

    /** Zone de texte long obligatoire (satisfaction_texte_long uniquement). */
    public bool $texteObligatoire = false;

    /** Labels d'extrémité (ressenti uniquement). */
    public string $labelGauche = '';

    public string $labelDroite = '';

    public ?int $editingQuestionId = null;

    public function mount(QuestionnaireTemplate $template): void
    {
        $this->template = $template;
    }

    public function render(): View
    {
        return view('livewire.questionnaire.modele-editor', [
            'questions' => $this->template->questions()->get(),
            'types' => TypeQuestion::pourSelect(),
        ]);
    }

    public function editerQuestion(int $id): void
    {
        $question = $this->template->questions()->findOrFail($id);
        $this->editingQuestionId = $id;
        $this->libelle = $question->libelle;
        $this->aide = $question->aide ?? '';
        $this->type = $question->type->value;
        $this->obligatoire = $question->obligatoire;

        // Charger les props spécifiques au type
        $config = $question->config ?? [];
        $this->commentaire = (bool) ($config['commentaire'] ?? false);
        $this->commentaireLibelle = $config['commentaire_libelle'] ?? '';
        $this->labelGauche = $config['label_gauche'] ?? '';
        $this->labelDroite = $config['label_droite'] ?? '';
        $this->texteObligatoire = (bool) ($config['texte_obligatoire'] ?? false);

        // Options (choix unique)
        if ($question->type->aDesOptions()) {
            $options = $config['options'] ?? [];
            $this->optionsBrut = implode("\n", array_column($options, 'libelle'));
        } else {
            $this->optionsBrut = '';
        }
    }

    public function ajouterQuestion(): void
    {
        $this->validate([
            'libelle' => 'required|string|max:255',
            'type' => 'required|in:'.implode(',', array_column(TypeQuestion::cases(), 'value')),
        ]);

        $type = TypeQuestion::from($this->type);
        $ordre = (int) $this->template->questions()->max('ordre') + 1;

        $obligatoire = $type === TypeQuestion::Information ? false : $this->obligatoire;

        QuestionnaireTemplateQuestion::create([
            'template_id' => $this->template->id,
            'libelle' => $this->libelle,
            'aide' => $this->aide ?: null,
            'type' => $type,
            'ordre' => $ordre,
            'obligatoire' => $obligatoire,
            'config' => $this->buildConfig($type),
        ]);

        $this->resetFormulaire();
    }

    public function sauvegarderQuestion(): void
    {
        $this->validate([
            'libelle' => 'required|string|max:255',
            'type' => 'required|in:'.implode(',', array_column(TypeQuestion::cases(), 'value')),
        ]);

        $question = $this->template->questions()->findOrFail($this->editingQuestionId);
        $type = TypeQuestion::from($this->type);
        $obligatoire = $type === TypeQuestion::Information ? false : $this->obligatoire;

        $question->update([
            'libelle' => $this->libelle,
            'aide' => $this->aide ?: null,
            'type' => $type,
            'obligatoire' => $obligatoire,
            'config' => $this->buildConfig($type),
        ]);

        $this->resetFormulaire();
    }

    public function annulerEdition(): void
    {
        $this->resetFormulaire();
    }

    private function resetFormulaire(): void
    {
        $this->editingQuestionId = null;
        $this->reset(['libelle', 'aide', 'obligatoire', 'optionsBrut', 'commentaire', 'commentaireLibelle', 'labelGauche', 'labelDroite', 'texteObligatoire']);
        $this->type = 'texte_court';
    }

    public function toggleGroupe(int $id): void
    {
        $question = $this->template->questions()->findOrFail($id);
        $question->update(['grouper_avec_precedente' => ! $question->grouper_avec_precedente]);
    }

    public function supprimerQuestion(int $id): void
    {
        QuestionnaireTemplateQuestion::where('template_id', $this->template->id)->findOrFail($id)->delete();
    }

    public function monter(int $id): void
    {
        $this->echangerOrdre($id, -1);
    }

    public function descendre(int $id): void
    {
        $this->echangerOrdre($id, +1);
    }

    private function echangerOrdre(int $id, int $sens): void
    {
        $courant = QuestionnaireTemplateQuestion::where('template_id', $this->template->id)->findOrFail($id);
        $voisin = QuestionnaireTemplateQuestion::where('template_id', $this->template->id)
            ->where('ordre', $courant->ordre + $sens)
            ->first();

        if ($voisin === null) {
            return;
        }

        $tmp = $courant->ordre;
        $courant->update(['ordre' => $voisin->ordre]);
        $voisin->update(['ordre' => $tmp]);
    }

    /** @return array<string, mixed>|null */
    private function buildConfig(TypeQuestion $type): ?array
    {
        if ($type === TypeQuestion::Satisfaction && $this->commentaire) {
            return [
                'commentaire' => true,
                'commentaire_libelle' => $this->commentaireLibelle !== '' ? $this->commentaireLibelle : 'Un commentaire ? (optionnel)',
            ];
        }

        if ($type === TypeQuestion::SatisfactionTexteLong) {
            return ['texte_obligatoire' => $this->texteObligatoire];
        }

        if ($type === TypeQuestion::Ressenti) {
            $config = [];
            if ($this->labelGauche !== '') {
                $config['label_gauche'] = $this->labelGauche;
            }
            if ($this->labelDroite !== '') {
                $config['label_droite'] = $this->labelDroite;
            }

            return $config !== [] ? $config : null;
        }

        if (! $type->aDesOptions()) {
            return null;
        }

        $lignes = collect(explode("\n", $this->optionsBrut))
            ->map(fn (string $l): string => trim($l))
            ->filter()
            ->values();

        $options = $lignes->map(fn (string $libelle, int $i): array => [
            'libelle' => $libelle,
            'valeur' => 'opt_'.Str::lower(Str::random(6)), // valeur technique stable générée une fois
            'ordre' => $i + 1,
        ])->all();

        return ['rendu' => 'auto', 'options' => $options];
    }
}
