<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\InvoiceOcrLigne;
use App\DTOs\InvoiceOcrResult;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\Association;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class InvoiceOcrService
{
    private const MODEL = 'claude-sonnet-4-20250514';

    public static function isConfigured(): bool
    {
        return Association::first()?->anthropic_api_key !== null;
    }

    /**
     * @param  array{tiers_attendu?: string, operation_attendue?: string, seance_attendue?: int}|null  $context
     */
    public function analyze(UploadedFile $file, ?array $context = null): InvoiceOcrResult
    {
        $apiKey = Association::first()?->anthropic_api_key;
        if ($apiKey === null) {
            throw new OcrNotConfiguredException;
        }

        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $mime = $file->getMimeType();
        $prompt = $this->buildPrompt($context);

        $sourceType = $mime === 'application/pdf' ? 'document' : 'image';

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => self::MODEL,
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

    private function buildPrompt(?array $context): string
    {
        $tiers = Tiers::orderBy('nom')->get()
            ->map(fn (Tiers $t) => $t->id.': '.$t->displayName())
            ->implode("\n");

        $sousCategories = SousCategorie::with('categorie')
            ->whereHas('categorie', fn ($q) => $q->where('type', 'depense'))
            ->orderBy('nom')
            ->get()
            ->map(fn (SousCategorie $s) => $s->id.': '.$s->nom.' ('.$s->categorie->nom.')')
            ->implode("\n");

        $exercice = app(ExerciceService::class)->current();
        $operations = Operation::with('typeOperation')
            ->forExercice($exercice)
            ->orderBy('nom')
            ->get()
            ->map(fn (Operation $o) => $o->id.': '.$o->nom.' (type: '.($o->typeOperation?->nom ?? '-').', séances: '.$o->nombre_seances.')')
            ->implode("\n");

        $today = now()->format('d/m/Y');
        $nextYear = $exercice + 1;
        $exerciceLabel = $exercice.'/'.$nextYear;

        $prompt = <<<PROMPT
Tu es un assistant d'extraction de factures fournisseur pour une association.
Date du jour : {$today}. Exercice comptable en cours : {$exerciceLabel} (du 01/09/{$exercice} au 31/08/{$nextYear}).

Extrais les informations de cette facture au format JSON suivant :

{"date": "YYYY-MM-DD", "reference": "numéro de facture", "tiers_id": null, "tiers_nom": "nom fournisseur", "montant_total": 0.00, "lignes": [{"description": "...", "sous_categorie_id": null, "operation_id": null, "seance": null, "montant": 0.00}], "warnings": []}

Règles :
- Pour la date, lis EXACTEMENT ce qui est écrit sur la facture. Ne corrige pas l'année.
- Respecte les lignes telles qu'elles apparaissent sur la facture. Si la facture indique quantité 2 à 70€ pour un montant de 140€, c'est UNE SEULE ligne à 140€. Ne ventile jamais.
- Pour tiers_id, cherche le tiers le plus proche dans la liste ci-dessous. Si aucun ne correspond, mets null.
- Pour sous_categorie_id, choisis la sous-catégorie la plus pertinente. Si aucune ne correspond, mets null.
- Pour operation_id, cherche l'opération la plus proche si la facture mentionne une activité. Sinon mets null.
- Pour seance, extrais le numéro de séance si identifiable dans la description. Sinon mets null.

TIERS EXISTANTS :
{$tiers}

SOUS-CATEGORIES DEPENSE :
{$sousCategories}

OPERATIONS EN COURS :
{$operations}
PROMPT;

        if ($context !== null) {
            $ctxParts = [];
            if (isset($context['tiers_attendu'])) {
                $ctxParts[] = "Tiers attendu : {$context['tiers_attendu']}";
            }
            if (isset($context['operation_attendue'])) {
                $ctxParts[] = "Opération attendue : {$context['operation_attendue']}";
            }
            if (isset($context['seance_attendue'])) {
                $ctxParts[] = "Séance attendue : {$context['seance_attendue']}";
            }
            $ctxStr = implode("\n", $ctxParts);

            $prompt .= <<<PROMPT


CONTEXTE ENCADRANT (valeurs attendues) :
{$ctxStr}

Compare les informations extraites de la facture avec ce contexte. Si une valeur ne correspond pas, ajoute un warning dans le champ "warnings". Exemples :
- "Le tiers sur la facture (X) ne correspond pas au tiers sélectionné (Y)"
- "L'opération détectée (X) ne correspond pas à l'opération sélectionnée (Y)"
- "La séance détectée (N) ne correspond pas à la séance sélectionnée (M)"
PROMPT;
        }

        $prompt .= "\n\nRéponds UNIQUEMENT avec le JSON, sans commentaire ni bloc markdown.";

        return $prompt;
    }

    private function parseResult(array $data): InvoiceOcrResult
    {
        $lignes = [];
        foreach ($data['lignes'] ?? [] as $l) {
            $lignes[] = new InvoiceOcrLigne(
                description: $l['description'] ?? null,
                sous_categorie_id: isset($l['sous_categorie_id']) && $l['sous_categorie_id'] !== null ? (int) $l['sous_categorie_id'] : null,
                operation_id: isset($l['operation_id']) && $l['operation_id'] !== null ? (int) $l['operation_id'] : null,
                seance: isset($l['seance']) && $l['seance'] !== null ? (int) $l['seance'] : null,
                montant: (float) ($l['montant'] ?? 0),
            );
        }

        return new InvoiceOcrResult(
            date: $data['date'] ?? null,
            reference: $data['reference'] ?? null,
            tiers_id: isset($data['tiers_id']) && $data['tiers_id'] !== null ? (int) $data['tiers_id'] : null,
            tiers_nom: $data['tiers_nom'] ?? null,
            montant_total: isset($data['montant_total']) ? (float) $data['montant_total'] : null,
            lignes: $lignes,
            warnings: $data['warnings'] ?? [],
        );
    }
}
