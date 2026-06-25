<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class QuestionnaireExcelExporter
{
    /**
     * Matrice de lignes (en-têtes + données) — extraite pour être testable sans I/O.
     *
     * @return array<int, array<int, mixed>>
     */
    public function lignes(QuestionnaireCampaign $campagne): array
    {
        $questions = $campagne->questions()->get();

        $entetes = [
            'Association', 'Type opération', 'Opération', 'Campagne', 'Date de soumission',
            'Réponse confidentielle', 'A accepté le contact', 'Participant (si contact accepté)',
        ];
        foreach ($questions as $q) {
            $entetes[] = $q->libelle; // libellé figé (snapshot) → stable
            if ($q->type === TypeQuestion::Satisfaction && ($q->config['commentaire'] ?? false)) {
                $entetes[] = $q->libelle.' — commentaire';
            }
        }

        $rows = [$entetes];

        $soumissions = $campagne->submissions()
            ->where('statut', StatutSubmission::Soumise->value)
            ->with(['answers', 'invitation.participant.tiers'])
            ->get();

        foreach ($soumissions as $sub) {
            $consent = (bool) $sub->accepte_contact;
            $participant = $sub->invitation?->participant;
            // Identité : exposée si le questionnaire est nominatif OU si le répondant a consenti.
            $montrerIdentite = (! $campagne->anonymise) || $consent;
            $identite = $montrerIdentite && $participant?->tiers
                ? trim(($participant->tiers->prenom ?? '').' '.($participant->tiers->nom ?? ''))
                : ''; // colonne TOUJOURS présente, valeur vide si identité non divulguée

            $ligne = [
                $campagne->operation->association->nom ?? '',
                $campagne->operation->typeOperation->nom ?? '',
                $campagne->operation->nom,
                $campagne->titre_affiche,
                $sub->submitted_at?->format('Y-m-d H:i') ?? '',
                'Oui',                       // toute réponse est confidentielle par défaut
                $consent ? 'Oui' : 'Non',
                $identite,
            ];

            $answersParQ = $sub->answers->keyBy('campaign_question_id');
            foreach ($questions as $q) {
                $answer = $answersParQ->get($q->id);
                $ligne[] = $this->valeurAffichee($q->type, $answer, $q);
                if ($q->type === TypeQuestion::Satisfaction && ($q->config['commentaire'] ?? false)) {
                    $ligne[] = $answer?->value_text ?? '';
                }
            }

            $rows[] = $ligne;
        }

        return $rows;
    }

    public function ecrire(QuestionnaireCampaign $campagne, string $cheminAbsolu): void
    {
        $writer = new Writer;
        $writer->openToFile($cheminAbsolu);
        foreach ($this->lignes($campagne) as $ligne) {
            $writer->addRow(Row::fromValues($ligne));
        }
        $writer->close();
    }

    private function valeurAffichee(TypeQuestion $type, mixed $answer, mixed $question): mixed
    {
        if ($answer === null) {
            return '';
        }

        return match ($type) {
            TypeQuestion::TexteCourt, TypeQuestion::TexteLong => $answer->value_text ?? '',
            TypeQuestion::Satisfaction, TypeQuestion::Ressenti => $answer->value_integer ?? '',
            TypeQuestion::CaseACocher => $answer->value_boolean ? 'Oui' : 'Non',
            TypeQuestion::ChoixUnique => $question->libelleOption((string) $answer->value_option) ?? ($answer->value_option ?? ''),
        };
    }
}
