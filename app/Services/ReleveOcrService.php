<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReleveOcrResult;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Support\CurrentAssociation;
use App\Support\Demo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class ReleveOcrService
{
    private const DEMO_STUB_SOLDE_OUVERTURE = 1000.0;

    private const DEMO_STUB_SOLDE_CLOTURE = 1500.0;

    private const DEMO_STUB_BANQUE = 'Banque exemple';

    private const DEMO_STUB_NUMERO_COMPTE = 'FR76 0000 0000 0000 0000 0000 000';

    public static function isConfigured(): bool
    {
        return CurrentAssociation::tryGet()?->anthropic_api_key !== null;
    }

    private function apiKey(): ?string
    {
        return CurrentAssociation::tryGet()?->anthropic_api_key;
    }

    /**
     * Modèle Anthropic utilisé pour l'analyse.
     * Priorité : choix de l'association (Paramètres) → défaut applicatif.
     */
    private function model(): string
    {
        $choisi = CurrentAssociation::tryGet()?->invoice_ocr_model;

        if (is_string($choisi) && $choisi !== '') {
            return $choisi;
        }

        return (string) config('services.anthropic.invoice_ocr_model', 'claude-sonnet-4-6');
    }

    public function analyze(UploadedFile $file): ReleveOcrResult
    {
        if (Demo::isActive()) {
            return $this->demoStub();
        }

        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            throw new OcrNotConfiguredException;
        }

        return $this->performAnalysis(
            apiKey: $apiKey,
            base64: base64_encode(file_get_contents($file->getRealPath())),
            mime: $file->getMimeType(),
        );
    }

    private function demoStub(): ReleveOcrResult
    {
        return new ReleveOcrResult(
            solde_ouverture: self::DEMO_STUB_SOLDE_OUVERTURE,
            solde_cloture: self::DEMO_STUB_SOLDE_CLOTURE,
            date_cloture: now()->format('Y-m-d'),
            banque: self::DEMO_STUB_BANQUE,
            numero_compte: self::DEMO_STUB_NUMERO_COMPTE,
            warnings: [],
        );
    }

    private function performAnalysis(string $apiKey, string $base64, string $mime): ReleveOcrResult
    {
        $prompt = $this->buildPrompt();

        $sourceType = $mime === 'application/pdf' ? 'document' : 'image';

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model(),
            'max_tokens' => 1024,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => $sourceType,
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mime,
                            'data' => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
        ]);

        if ($response->failed()) {
            throw new OcrAnalysisException('Erreur API Anthropic : '.$response->status().' — '.$response->body());
        }

        $text = $response->json('content.0.text', '');
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new OcrAnalysisException('Réponse IA non exploitable : JSON invalide.');
        }

        return $this->parseResult($data);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant d'extraction de relevés bancaires.
Extrais les informations suivantes de ce relevé de compte au format JSON :

{"solde_ouverture": 0.00, "solde_cloture": 0.00, "date_cloture": "YYYY-MM-DD", "banque": "nom de la banque", "numero_compte": "numéro ou IBAN partiel", "warnings": []}

Règles :
- Cherche le solde d'ouverture (« ancien solde », « solde précédent », « solde au ... ») et le solde de clôture (« nouveau solde », « solde final »).
- Pour la date de clôture, prends la date de fin du relevé (souvent en en-tête ou en fin de document).
- Les montants sont en euros. Utilise le point comme séparateur décimal.
- Si une information est introuvable, mets null.
- Ajoute un warning si le document ne ressemble pas à un relevé bancaire.

Réponds UNIQUEMENT avec le JSON, sans commentaire ni bloc markdown.
PROMPT;
    }

    private function parseResult(array $data): ReleveOcrResult
    {
        return new ReleveOcrResult(
            solde_ouverture: isset($data['solde_ouverture']) && $data['solde_ouverture'] !== null ? (float) $data['solde_ouverture'] : null,
            solde_cloture: isset($data['solde_cloture']) && $data['solde_cloture'] !== null ? (float) $data['solde_cloture'] : null,
            date_cloture: $data['date_cloture'] ?? null,
            banque: $data['banque'] ?? null,
            numero_compte: $data['numero_compte'] ?? null,
            warnings: $data['warnings'] ?? [],
        );
    }
}
