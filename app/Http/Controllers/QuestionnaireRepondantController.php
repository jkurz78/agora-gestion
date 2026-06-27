<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatutInvitation;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\Association;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireEcranResolver;
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
        private readonly QuestionnaireEcranResolver $ecranResolver,
    ) {}

    public function show(Request $request, string $token): View
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;
        $saisiePour = $request->boolean('saisie_pour');

        if (! $campagne->statut->accepteReponses()) {
            return view('questionnaire.repondant.indisponible', compact('campagne'));
        }
        if ($invitation->statut === StatutInvitation::Soumis) {
            return view('questionnaire.repondant.indisponible', ['campagne' => $campagne, 'dejaRepondu' => true]);
        }

        $page = max(0, (int) $request->query('page', '0'));
        $questions = $campagne->questions()->orderBy('ordre')->get();
        $ecrans = $this->ecranResolver->decouper($questions);
        $total = count($ecrans);

        if ($page === 0) {
            $vars = $this->variables->pour($invitation);

            return view('questionnaire.repondant.intro', [
                'invitation' => $invitation,
                'campagne' => $campagne,
                'token' => $token,
                'introHtml' => $this->variables->remplacer((string) ($campagne->intro ?? ''), $vars),
                'titre' => $this->variables->remplacer((string) $campagne->titre_affiche, $vars),
                'saisiePour' => $saisiePour,
            ]);
        }

        $ecran = $ecrans[$page - 1] ?? null;
        abort_if($ecran === null, 404);

        $submission = $this->reponses->demarrerOuReprendre($invitation);
        $answers = $submission->answers()->get()->keyBy('campaign_question_id');

        return view('questionnaire.repondant.question', [
            'token' => $token,
            'campagne' => $campagne,
            'ecran' => $ecran,
            'page' => $page,
            'total' => $total,
            'answers' => $answers,
            'saisiePour' => $saisiePour,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;
        abort_unless($campagne->statut->accepteReponses(), 422);

        $action = (string) $request->input('action');
        $saisiePour = $request->boolean('saisie_pour');
        $submission = $this->reponses->demarrerOuReprendre($invitation);
        $extraParams = $saisiePour ? ['saisie_pour' => 1] : [];

        if ($action === 'start') {
            return redirect()->route('questionnaire.show', ['token' => $token, 'page' => 1, ...$extraParams]);
        }

        if ($action === 'next') {
            $page = (int) $request->input('page');
            $questions = $campagne->questions()->orderBy('ordre')->get();
            $ecrans = $this->ecranResolver->decouper($questions);
            $total = count($ecrans);
            $ecran = $ecrans[$page - 1] ?? null;
            abort_if($ecran === null, 404);

            if (! $saisiePour) {
                $erreurs = [];
                foreach ($ecran as $q) {
                    if (! $q->type->estReponse()) {
                        continue;
                    }
                    $valeur = $request->input("q_{$q->id}");
                    $commentaire = $request->input("q_{$q->id}_commentaire");
                    $erreurs = array_merge($erreurs, $this->reponses->champsManquants($q, $valeur, $commentaire));
                }
                if ($erreurs !== []) {
                    return back()->withErrors($erreurs)->withInput();
                }
            }

            foreach ($ecran as $q) {
                if (! $q->type->estReponse()) {
                    continue;
                }
                $valeur = $request->input("q_{$q->id}");
                $commentaire = $request->input("q_{$q->id}_commentaire");
                $this->reponses->enregistrerReponse($submission, $q, $valeur, $commentaire);
            }

            $next = $page + 1;

            if ($next > $total) {
                if ($saisiePour) {
                    $this->reponses->finaliserSansBloquer($submission, accepteContact: false);

                    return redirect()->route('questionnaire.merci', ['token' => $token]);
                }

                if (! $campagne->anonymise) {
                    $this->reponses->finaliser($submission, accepteContact: false);

                    return redirect()->route('questionnaire.merci', ['token' => $token]);
                }

                return redirect()->route('questionnaire.consentement', ['token' => $token]);
            }

            return redirect()->route('questionnaire.show', ['token' => $token, 'page' => $next, ...$extraParams]);
        }

        if ($action === 'prev') {
            $page = (int) $request->input('page');
            $questions = $campagne->questions()->orderBy('ordre')->get();
            $ecrans = $this->ecranResolver->decouper($questions);
            $ecran = $ecrans[$page - 1] ?? null;

            if ($ecran !== null) {
                foreach ($ecran as $q) {
                    if (! $q->type->estReponse()) {
                        continue;
                    }
                    $this->reponses->enregistrerReponse(
                        $submission,
                        $q,
                        $request->input("q_{$q->id}"),
                        $request->input("q_{$q->id}_commentaire"),
                    );
                }
            }

            return redirect()->route('questionnaire.show', [
                'token' => $token, 'page' => max(0, $page - 1), ...$extraParams,
            ]);
        }

        if ($action === 'finish') {
            try {
                if ($saisiePour) {
                    $this->reponses->finaliserSansBloquer($submission, $request->boolean('accepte_contact'));
                } else {
                    $this->reponses->finaliser($submission, $request->boolean('accepte_contact'));
                }
            } catch (ReponseObligatoireException) {
                return redirect()->route('questionnaire.show', ['token' => $token, 'page' => 1, ...$extraParams])
                    ->withErrors(['reponse' => 'Une question obligatoire n\'est pas renseignée.']);
            }

            return redirect()->route('questionnaire.merci', ['token' => $token]);
        }

        abort(400);
    }

    public function consentement(string $token): View
    {
        $invitation = $this->resoudre($token);
        $campagne = $invitation->campaign;
        abort_unless($campagne->statut->accepteReponses(), 422);

        $questions = $campagne->questions()->orderBy('ordre')->get();
        $total = count($this->ecranResolver->decouper($questions));

        return view('questionnaire.repondant.consentement', [
            'token' => $token,
            'campagne' => $campagne,
            'total' => $total,
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
