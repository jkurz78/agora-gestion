<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\QuestionnaireCampaign;
use App\Support\CurrentAssociation;
use App\Support\Demo;
use Illuminate\Support\Facades\Http;

final class QuestionnaireOcrService
{
    public static function isConfigured(): bool
    {
        return CurrentAssociation::tryGet()?->anthropic_api_key !== null;
    }

    private function model(): string
    {
        $choisi = CurrentAssociation::tryGet()?->questionnaire_ocr_model;

        if (is_string($choisi) && $choisi !== '') {
            return $choisi;
        }

        return (string) config('services.anthropic.questionnaire_ocr_model', 'claude-sonnet-4-6');
    }

    /**
     * @return array<string, array{value: mixed, confidence: float}>
     */
    public function analyzeFromPath(string $path, string $mime, QuestionnaireCampaign $campagne): array
    {
        if (Demo::isActive()) {
            return $this->demoStub($campagne);
        }

        $apiKey = CurrentAssociation::tryGet()?->anthropic_api_key;
        if ($apiKey === null) {
            throw new OcrNotConfiguredException('Clé Anthropic non configurée.');
        }

        if (! file_exists($path)) {
            throw new OcrAnalysisException('Fichier introuvable : '.$path);
        }

        $base64 = base64_encode((string) file_get_contents($path));

        $sourceType = $mime === 'application/pdf' ? 'document' : 'image';

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(40)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model(),
            'max_tokens' => 1500,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => $sourceType, 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                    ['type' => 'text', 'text' => $this->buildPrompt($campagne)],
                ],
            ]],
        ]);

        if ($response->failed()) {
            throw new OcrAnalysisException('Échec OCR questionnaire : '.$response->status());
        }

        return $this->parse($response->json('content.0.text', ''));
    }

    /**
     * @return array<string, array{value: mixed, confidence: float}>
     */
    public function parse(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', trim($text));
        $text = preg_replace('/\s*```$/m', '', (string) $text);
        $data = json_decode(trim((string) $text), true);

        return is_array($data) ? $data : [];
    }

    private function buildPrompt(QuestionnaireCampaign $campagne): string
    {
        $lignes = $campagne->questions->map(
            fn ($q) => "- id {$q->id} ({$q->type->value}) : {$q->libelle}".
                ($q->aDesOptions() ? ' [options: '.collect($q->options())->pluck('libelle')->join(', ').']' : '')
        )->join("\n");

        return 'Tu lis une feuille de questionnaire remplie à la main. Pour chaque question ci-dessous, '.
            'renvoie un JSON {"<id>":{"value":<valeur>,"confidence":<0..1>}}. '.
            'satisfaction/satisfaction_texte_long : entier 1-5 pour la note (+ champ "text" pour le commentaire si présent), '.
            'ressenti=entier 0-100, case_a_cocher=true/false, '.
            "choix_unique=valeur technique de l'option cochée, textes=transcription.\n\nQuestions :\n{$lignes}";
    }

    /**
     * @return array<string, array{value: mixed, confidence: float}>
     */
    private function demoStub(QuestionnaireCampaign $campagne): array
    {
        return $campagne->questions
            ->filter(fn ($q) => $q->type->estReponse())
            ->mapWithKeys(fn ($q) => [
                (string) $q->id => ['value' => match ($q->type->value) {
                    'satisfaction', 'satisfaction_texte_long' => 4,
                    'ressenti' => 65,
                    'case_a_cocher' => true,
                    default => 'exemple',
                }, 'confidence' => 0.75],
            ])->all();
    }
}
