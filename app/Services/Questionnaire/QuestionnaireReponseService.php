<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutInvitation;
use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireSubmission;
use App\Models\QuestionnaireTemplateQuestion;
use Illuminate\Support\Facades\DB;

final class QuestionnaireReponseService
{
    /** Invariant ≤1 active : récupère la soumission active ou en crée une. */
    public function demarrerOuReprendre(QuestionnaireInvitation $invitation): QuestionnaireSubmission
    {
        return DB::transaction(function () use ($invitation): QuestionnaireSubmission {
            $submission = $invitation->submissions()
                ->whereIn('statut', [StatutSubmission::EnCours->value, StatutSubmission::Soumise->value])
                ->first();

            if ($submission === null) {
                $submission = $invitation->submissions()->create([
                    'campaign_id' => $invitation->campaign_id,
                    'statut' => StatutSubmission::EnCours,
                    'source' => 'en_ligne',
                    'active_key' => $invitation->id,
                ]);
            }

            if ($invitation->statut === StatutInvitation::NonOuvert) {
                $invitation->update(['statut' => StatutInvitation::Commence, 'opened_at' => now()]);
            }

            return $submission;
        });
    }

    /** Persiste/écrase la réponse d'UNE question (upsert par (submission, question)). */
    public function enregistrerReponse(
        QuestionnaireSubmission $submission,
        QuestionnaireCampaignQuestion $question,
        int|string|bool|null $valeurBrute,
        ?string $commentaire = null,
    ): void {
        $payload = $this->normaliser($question, $valeurBrute, $commentaire);

        $submission->answers()->updateOrCreate(
            ['campaign_question_id' => $question->id],
            $payload,
        );
    }

    /** @return array<string, mixed> */
    private function normaliser(QuestionnaireCampaignQuestion $question, int|string|bool|null $v, ?string $commentaire = null): array
    {
        $base = [
            'value_text' => null, 'value_integer' => null,
            'value_boolean' => null, 'value_option' => null, 'value_meta' => null,
        ];

        $payload = match ($question->type) {
            TypeQuestion::TexteCourt, TypeQuestion::TexteLong => ($v === null || $v === '') ? $base : [...$base, 'value_text' => (string) $v],
            TypeQuestion::Satisfaction, TypeQuestion::SatisfactionTexteLong, TypeQuestion::Ressenti => ($v === null || $v === '') ? $base : [...$base, 'value_integer' => (int) $v],
            TypeQuestion::CaseACocher => ($v === null || $v === '') ? $base : [...$base, 'value_boolean' => (bool) $v],
            TypeQuestion::ChoixUnique => ($v === null || $v === '') ? $base : [
                ...$base,
                'value_option' => (string) $v,
                'value_meta' => ['libelle' => $question->libelleOption((string) $v)],
            ],
        };

        // Commentaire optionnel (satisfaction) : stocké dans value_text, indépendamment de la note.
        if ($question->type === TypeQuestion::Satisfaction
            && ($question->config['commentaire'] ?? false)
            && $commentaire !== null && $commentaire !== '') {
            $payload['value_text'] = $commentaire;
        }

        // Texte long compound (SatisfactionTexteLong) : value_text toujours stocké quand non vide.
        if ($question->type === TypeQuestion::SatisfactionTexteLong
            && $commentaire !== null && $commentaire !== '') {
            $payload['value_text'] = $commentaire;
        }

        return $payload;
    }

    public function finaliser(QuestionnaireSubmission $submission, bool $accepteContact): void
    {
        DB::transaction(function () use ($submission, $accepteContact): void {
            $this->verifierObligatoires($submission);

            $this->marquerSoumise($submission, $accepteContact);
        });
    }

    public function finaliserSansBloquer(QuestionnaireSubmission $submission, bool $accepteContact): void
    {
        DB::transaction(function () use ($submission, $accepteContact): void {
            $this->marquerSoumise($submission, $accepteContact);
        });
    }

    private function marquerSoumise(QuestionnaireSubmission $submission, bool $accepteContact): void
    {
        $submission->update([
            'statut' => StatutSubmission::Soumise,
            'accepte_contact' => $accepteContact,
            'submitted_at' => now(),
        ]);

        $submission->invitation->update([
            'statut' => StatutInvitation::Soumis,
            'submitted_at' => now(),
        ]);
    }

    /**
     * Retourne les erreurs de validation pour une question donnée.
     *
     * @return array<string, string> erreurs par nom de champ (q_{id} et/ou q_{id}_commentaire)
     */
    public function champsManquants(QuestionnaireCampaignQuestion|QuestionnaireTemplateQuestion $q, ?string $note, ?string $texte): array
    {
        if ($q->type === TypeQuestion::SatisfactionTexteLong) {
            $erreurs = [];

            if ($q->obligatoire && ($note === null || $note === '')) {
                $erreurs["q_{$q->id}"] = 'Veuillez indiquer votre satisfaction.';
            }

            if (($q->config['texte_obligatoire'] ?? false) && ($texte === null || $texte === '')) {
                $erreurs["q_{$q->id}_commentaire"] = 'Ce texte est obligatoire.';
            }

            return $erreurs;
        }

        // Tous les autres types réponse : validation standard sur la colonne primaire.
        if ($q->type->estReponse() && $q->obligatoire && ($note === null || $note === '')) {
            return ["q_{$q->id}" => 'Cette question est obligatoire.'];
        }

        return [];
    }

    private function verifierObligatoires(QuestionnaireSubmission $submission): void
    {
        $answers = $submission->answers()->get()->keyBy('campaign_question_id');

        $manquante = $submission->campaign->questions()
            ->get()
            ->first(function (QuestionnaireCampaignQuestion $q) use ($answers): bool {
                if (! $q->type->estReponse()) {
                    return false;
                }

                $a = $answers->get($q->id);

                // Extraire la valeur primaire selon la colonne du type.
                $colonne = $q->type->valueColumn();
                $valeurPrimaire = $a === null ? null : $a->{$colonne};
                $note = $valeurPrimaire === null ? null : (string) $valeurPrimaire;
                $texte = $a?->value_text;

                return $this->champsManquants($q, $note, $texte) !== [];
            });

        if ($manquante !== null) {
            throw new ReponseObligatoireException('Une question obligatoire n\'est pas renseignée.');
        }
    }

    /**
     * Crée une soumission papier depuis un payload OCR validé.
     * Supersede non destructif : l'ancienne soumission passe en "remplacee", la nouvelle prend la clé active.
     *
     * @param  array<string|int, int|string|bool|null>  $valeursParQuestionId  question_id => valeur brute
     */
    /**
     * @param  array<string|int, int|string|bool|null>  $valeursParQuestionId
     * @param  array<string|int, string>  $commentairesParQuestionId
     */
    public function creerDepuisOcr(
        QuestionnaireInvitation $invitation,
        array $valeursParQuestionId,
        array $commentairesParQuestionId = [],
        bool $accepteContact = false,
        bool $remplacer = false,
    ): QuestionnaireSubmission {
        return DB::transaction(function () use ($invitation, $valeursParQuestionId, $commentairesParQuestionId, $accepteContact, $remplacer): QuestionnaireSubmission {
            $active = $invitation->submissions()
                ->whereIn('statut', [StatutSubmission::EnCours->value, StatutSubmission::Soumise->value])
                ->first();

            if ($active !== null) {
                abort_unless($remplacer, 422, 'Une réponse existe déjà (choisir Ignorer ou Remplacer).');
            }

            // Crée la nouvelle soumission SANS active_key (sera fixée après le supersede)
            $nouvelle = $invitation->submissions()->create([
                'campaign_id' => $invitation->campaign_id,
                'statut' => StatutSubmission::EnCours,
                'source' => 'papier',
                'active_key' => null,
            ]);

            // Enregistre les réponses
            $questions = $invitation->campaign->questions()->get()->keyBy('id');
            foreach ($valeursParQuestionId as $qid => $valeur) {
                $q = $questions->get((int) $qid);
                if ($q === null || ! $q->type->estReponse()) {
                    continue;
                }
                $commentaire = $commentairesParQuestionId[(string) $qid] ?? ($commentairesParQuestionId[(int) $qid] ?? null);
                $this->enregistrerReponse($nouvelle, $q, $valeur, $commentaire);
            }

            // Supersede : libère l'active_key AVANT de la revendiquer sur la nouvelle soumission
            if ($active !== null) {
                $active->update([
                    'statut' => StatutSubmission::Remplacee,
                    'active_key' => null,
                    'remplacee_par_id' => $nouvelle->id,
                ]);
            }

            // Finalise la nouvelle soumission
            $nouvelle->update([
                'statut' => StatutSubmission::Soumise,
                'accepte_contact' => $accepteContact,
                'submitted_at' => now(),
                'active_key' => $invitation->id,
            ]);

            $invitation->update(['statut' => StatutInvitation::Soumis, 'submitted_at' => now()]);

            return $nouvelle;
        });
    }

    /** Réouverture admin (D4) : symétrique invitation + soumission, réponses conservées. */
    public function rouvrir(QuestionnaireInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $fresh = $invitation->fresh();

            $submission = $invitation->submissions()
                ->where('statut', StatutSubmission::Soumise->value)
                ->first();

            if ($submission !== null) {
                $submission->update(['statut' => StatutSubmission::EnCours, 'submitted_at' => null]);
            }

            $fresh?->update(['statut' => StatutInvitation::Commence, 'submitted_at' => null]);
        });
    }
}
