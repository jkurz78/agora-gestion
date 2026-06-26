<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Services\Questionnaire\QuestionnaireEcranResolver;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireVariableResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class QuestionnaireApercuController extends Controller
{
    public function __construct(
        private readonly QuestionnaireVariableResolver $variables,
        private readonly QuestionnaireEcranResolver $ecranResolver,
        private readonly QuestionnaireReponseService $reponses,
    ) {}

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
        $ecrans = $this->ecranResolver->decouper($questions);
        $total = count($ecrans);
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

        $ecran = $ecrans[$page - 1] ?? null;
        abort_if($ecran === null, 404);

        // Pre-fill every real question of the screen from session
        $reponses = session($sessionKey, []);
        $oldValues = [];
        $oldCommentaires = [];
        foreach ($ecran as $q) {
            if (! $q->type->estReponse()) {
                continue;
            }
            $oldValues[$q->id] = $reponses[$q->id] ?? null;
            $oldCommentaires[$q->id] = $reponses[$q->id.'_commentaire'] ?? null;
        }

        return view('questionnaire.apercu.question', $commun + [
            'ecran' => $ecran,
            'page' => $page,
            'oldValues' => $oldValues,
            'oldCommentaires' => $oldCommentaires,
        ]);
    }

    /**
     * Handle POST navigation: store current screen's answers in session, redirect to next/prev page.
     *
     * @param  Collection<int, QuestionnaireCampaignQuestion|QuestionnaireTemplateQuestion>  $questions
     */
    private function stocker(Request $request, string $source, $questions, string $base, bool $anonymise = true): RedirectResponse
    {
        $action = $request->input('action', 'next');
        $page = max(1, (int) $request->input('page', 1));
        $ecrans = $this->ecranResolver->decouper($questions);
        $total = count($ecrans);
        $sessionKey = 'apercu_reponses.'.$source;

        // Persist every real question of the current screen into session — NO DB writes
        $ecran = $ecrans[$page - 1] ?? null;
        $reponses = session($sessionKey, []);

        if ($ecran !== null) {
            foreach ($ecran as $q) {
                if (! $q->type->estReponse()) {
                    continue; // Information : pas de saisie
                }
                $value = $request->input("q_{$q->id}");
                $commentaire = $request->input("q_{$q->id}_commentaire");
                if ($value !== null) {
                    $reponses[$q->id] = $value;
                }
                if ($commentaire !== null) {
                    $reponses[$q->id.'_commentaire'] = $commentaire;
                }
            }
            session([$sessionKey => $reponses]);
        }

        // Retour en arrière : jamais de blocage.
        if ($action === 'prev') {
            return redirect($base.'?page='.($page > 1 ? $page - 1 : 0));
        }

        // Suivant : valider toutes les questions obligatoires de l'écran.
        if ($ecran !== null) {
            $erreurs = [];
            foreach ($ecran as $q) {
                if (! $q->type->estReponse()) {
                    continue; // Information : pas de validation
                }
                $value = $request->input("q_{$q->id}");
                $commentaire = $request->input("q_{$q->id}_commentaire");
                $erreurs = array_merge($erreurs, $this->reponses->champsManquants($q, $value, $commentaire));
            }
            if ($erreurs !== []) {
                return redirect($base.'?page='.$page)->withErrors($erreurs);
            }
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
