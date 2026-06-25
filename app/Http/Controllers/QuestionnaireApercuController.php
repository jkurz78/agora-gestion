<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Services\Questionnaire\QuestionnaireVariableResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class QuestionnaireApercuController extends Controller
{
    public function __construct(private readonly QuestionnaireVariableResolver $variables) {}

    public function modele(Request $request, QuestionnaireTemplate $template): View
    {
        return $this->rendre(
            $request,
            source: 'modele_'.$template->id,
            titre: (string) $template->titre_affiche,
            intro: (string) $template->intro,
            remerciement: (string) $template->remerciement,
            questions: $template->questions()->get(),
            vars: $this->variables->exemple(),
            retour: route('questionnaires.modeles.editor', $template),
            base: route('questionnaires.modeles.apercu', $template),
            postUrl: route('questionnaires.modeles.apercu.store', $template),
            anonymise: (bool) $template->anonymise,
            autoriserRetour: (bool) $template->autoriser_retour,
            afficherProgression: (bool) $template->afficher_progression,
        );
    }

    public function storeModele(Request $request, QuestionnaireTemplate $template): RedirectResponse
    {
        return $this->stocker(
            $request,
            source: 'modele_'.$template->id,
            questions: $template->questions()->get(),
            base: route('questionnaires.modeles.apercu', $template),
            anonymise: (bool) $template->anonymise,
        );
    }

    public function campagne(Request $request, QuestionnaireCampaign $campagne): View
    {
        return $this->rendre(
            $request,
            source: 'campagne_'.$campagne->id,
            titre: (string) $campagne->titre_affiche,
            intro: (string) $campagne->intro,
            remerciement: (string) $campagne->remerciement,
            questions: $campagne->questions()->get(),
            vars: $this->variables->exemple($campagne),
            retour: route('operations.show', $campagne->operation_id),
            base: route('questionnaires.campagnes.apercu', $campagne),
            postUrl: route('questionnaires.campagnes.apercu.store', $campagne),
            anonymise: (bool) $campagne->anonymise,
            autoriserRetour: (bool) $campagne->autoriser_retour,
            afficherProgression: (bool) $campagne->afficher_progression,
        );
    }

    public function storeCampagne(Request $request, QuestionnaireCampaign $campagne): RedirectResponse
    {
        return $this->stocker(
            $request,
            source: 'campagne_'.$campagne->id,
            questions: $campagne->questions()->get(),
            base: route('questionnaires.campagnes.apercu', $campagne),
            anonymise: (bool) $campagne->anonymise,
        );
    }

    /**
     * @param  Collection<int, QuestionnaireCampaignQuestion|QuestionnaireTemplateQuestion>  $questions
     * @param  array<string, string>  $vars
     */
    private function rendre(Request $request, string $source, string $titre, string $intro, string $remerciement, $questions, array $vars, string $retour, string $base, string $postUrl, bool $anonymise = true, bool $autoriserRetour = true, bool $afficherProgression = true): View
    {
        $page = $request->query('page', '0');
        $total = $questions->count();
        $sessionKey = 'apercu_reponses.'.$source;

        $commun = [
            'titre' => $this->variables->remplacer($titre, $vars),
            'base' => $base,
            'postUrl' => $postUrl,
            'retour' => $retour,
            'total' => $total,
            'anonymise' => $anonymise,
            'autoriser_retour' => $autoriserRetour,
            'afficher_progression' => $afficherProgression,
        ];

        if ($page === 'consentement') {
            return view('questionnaire.apercu.consentement', $commun + ['page' => $total + 1]);
        }
        if ($page === 'merci') {
            // Clear session on reaching merci (preview complete)
            session()->forget($sessionKey);

            return view('questionnaire.apercu.merci', $commun + ['remerciementHtml' => $this->variables->remplacer($remerciement, $vars)]);
        }

        $page = max(0, (int) $page);
        if ($page === 0) {
            // Reset session when landing on intro (fresh preview start)
            session()->forget($sessionKey);

            return view('questionnaire.apercu.intro', $commun + ['introHtml' => $this->variables->remplacer($intro, $vars)]);
        }

        $question = $questions[$page - 1] ?? null;
        abort_if($question === null, 404);

        // Pre-fill from session
        $reponses = session($sessionKey, []);
        $oldValue = $reponses[$question->id] ?? null;
        $oldCommentaire = $reponses[$question->id.'_commentaire'] ?? null;

        return view('questionnaire.apercu.question', $commun + [
            'question' => $question,
            'page' => $page,
            'oldValue' => $oldValue,
            'oldCommentaire' => $oldCommentaire,
        ]);
    }

    /**
     * Handle POST navigation: store current answer in session, redirect to next/prev page.
     *
     * @param  Collection<int, QuestionnaireCampaignQuestion|QuestionnaireTemplateQuestion>  $questions
     */
    private function stocker(Request $request, string $source, $questions, string $base, bool $anonymise = true): RedirectResponse
    {
        $action = $request->input('action', 'next');
        $page = max(1, (int) $request->input('page', 1));
        $total = $questions->count();
        $sessionKey = 'apercu_reponses.'.$source;

        // Persist the current page's answer (if any) into session — NO DB writes
        $question = $questions[$page - 1] ?? null;
        $value = null;
        if ($question !== null) {
            $fieldName = 'q_'.$question->id;
            $value = $request->input($fieldName);
            $commentaire = $request->input($fieldName.'_commentaire');

            $reponses = session($sessionKey, []);
            if ($value !== null) {
                $reponses[$question->id] = $value;
            }
            if ($commentaire !== null) {
                $reponses[$question->id.'_commentaire'] = $commentaire;
            }
            session([$sessionKey => $reponses]);
        }

        // Retour en arrière : jamais de blocage.
        if ($action === 'prev') {
            return redirect($base.'?page='.($page > 1 ? $page - 1 : 0));
        }

        // Suivant : bloque sur une question obligatoire vide, comme le parcours réel.
        if ($question !== null && $question->obligatoire && ($value === null || $value === '')) {
            return redirect($base.'?page='.$page)
                ->withErrors(['reponse' => 'Cette question est obligatoire.']);
        }

        if ($page >= $total) {
            // Non anonyme : sauter le consentement, aller directement à merci (efface session).
            if (! $anonymise) {
                session()->forget($sessionKey);

                return redirect($base.'?page=merci');
            }

            return redirect($base.'?page=consentement');
        }

        return redirect($base.'?page='.($page + 1));
    }
}
