<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatutInvitation;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\Association;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireTokenService;
use App\Services\Questionnaire\QuestionnaireVariableResolver;
use App\Tenant\TenantContext;
use App\Tenant\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class QuestionnaireRepondantController extends Controller
{
    public function __construct(
        private readonly QuestionnaireTokenService $tokens,
        private readonly QuestionnaireReponseService $reponses,
        private readonly QuestionnaireVariableResolver $variables,
    ) {}

    public function show(Request $request, string $token): View
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;

        if (! $campagne->statut->accepteReponses()) {
            return view('questionnaire.repondant.indisponible', compact('campagne'));
        }
        if ($invitation->statut === StatutInvitation::Soumis) {
            return view('questionnaire.repondant.indisponible', ['campagne' => $campagne, 'dejaRepondu' => true]);
        }

        $page = max(0, (int) $request->query('page', '0'));
        $questions = $campagne->questions()->get();

        if ($page === 0) {
            $vars = $this->variables->pour($invitation);

            return view('questionnaire.repondant.intro', [
                'invitation' => $invitation,
                'campagne' => $campagne,
                'token' => $token,
                'introHtml' => $this->variables->remplacer((string) ($campagne->intro ?? ''), $vars),
                'titre' => $this->variables->remplacer((string) $campagne->titre_affiche, $vars),
            ]);
        }

        $question = $questions[$page - 1] ?? null;
        abort_if($question === null, 404);

        // valeur déjà saisie (reprise)
        $submission = $this->reponses->demarrerOuReprendre($invitation);
        $answer = $submission->answers()->where('campaign_question_id', $question->id)->first();

        return view('questionnaire.repondant.question', [
            'token' => $token, 'campagne' => $campagne, 'question' => $question,
            'page' => $page, 'total' => $questions->count(), 'answer' => $answer,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;
        abort_unless($campagne->statut->accepteReponses(), 422);

        $action = (string) $request->input('action');
        $submission = $this->reponses->demarrerOuReprendre($invitation);

        if ($action === 'start') {
            return redirect()->route('questionnaire.show', ['token' => $token, 'page' => 1]);
        }

        if ($action === 'next') {
            $page = (int) $request->input('page');
            $question = $campagne->questions()->get()[$page - 1] ?? null;
            abort_if($question === null, 404);

            $valeur = $request->input("q_{$question->id}");
            $commentaire = $request->input("q_{$question->id}_commentaire");

            if ($question->obligatoire && ($valeur === null || $valeur === '')) {
                return back()->withErrors(['reponse' => 'Cette question est obligatoire.'])->withInput();
            }

            $this->reponses->enregistrerReponse($submission, $question, $valeur, $commentaire);

            $total = $campagne->questions()->count();
            $next = $page + 1;

            if ($next > $total) {
                if (! $campagne->anonymise) {
                    $this->reponses->finaliser($submission, accepteContact: false);

                    return redirect()->route('questionnaire.merci', ['token' => $token]);
                }

                return redirect()->route('questionnaire.consentement', ['token' => $token]);
            }

            return redirect()->route('questionnaire.show', ['token' => $token, 'page' => $next]);
        }

        if ($action === 'prev') {
            $page = (int) $request->input('page');
            $question = $campagne->questions()->get()[$page - 1] ?? null;

            // Retour en arrière : on persiste la saisie courante sans bloquer (obligatoire ignoré).
            if ($question !== null) {
                $this->reponses->enregistrerReponse(
                    $submission,
                    $question,
                    $request->input("q_{$question->id}"),
                    $request->input("q_{$question->id}_commentaire"),
                );
            }

            return redirect()->route('questionnaire.show', [
                'token' => $token, 'page' => max(0, $page - 1),
            ]);
        }

        if ($action === 'finish') {
            try {
                $this->reponses->finaliser($submission, $request->boolean('accepte_contact'));
            } catch (ReponseObligatoireException) {
                return redirect()->route('questionnaire.show', ['token' => $token, 'page' => 1])
                    ->withErrors(['reponse' => 'Une question obligatoire n\'est pas renseignée.']);
            }

            return redirect()->route('questionnaire.merci', ['token' => $token]);
        }

        abort(400);
    }

    public function consentement(string $token): View
    {
        $invitation = $this->resoudre($token);
        abort_unless($invitation->campaign->statut->accepteReponses(), 422);

        return view('questionnaire.repondant.consentement', [
            'token' => $token, 'campagne' => $invitation->campaign,
            'total' => $invitation->campaign->questions()->count(),
        ]);
    }

    public function merci(string $token): View
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;
        $vars = $this->variables->pour($invitation);

        return view('questionnaire.repondant.merci', [
            'campagne' => $campagne,
            'remerciementHtml' => $this->variables->remplacer((string) ($campagne->remerciement ?? ''), $vars),
            'titre' => $this->variables->remplacer((string) $campagne->titre_affiche, $vars),
        ]);
    }

    /** Résolution par hash + boot tenant (D18, miroir SubscriptionService::findByToken). */
    private function resoudre(string $tokenClair): QuestionnaireInvitation
    {
        $hash = $this->tokens->hash($tokenClair);

        $invitation = QuestionnaireInvitation::withoutGlobalScope(TenantScope::class)
            ->where('token_hash', $hash)
            ->first();

        abort_if($invitation === null, 404);

        if (! TenantContext::hasBooted() || (int) TenantContext::currentId() !== (int) $invitation->association_id) {
            $association = Association::find($invitation->association_id);
            abort_if($association === null, 404);
            TenantContext::boot($association);
        }

        return $invitation;
    }
}
