<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\InvoiceOcrLigne;
use App\DTOs\InvoiceOcrResult;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Support\CurrentAssociation;
use App\Support\Demo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class InvoiceOcrService
{
    private const MODEL = 'claude-sonnet-4-20250514';

    private const DEMO_STUB_MONTANT = 100.0;

    private const DEMO_STUB_TIERS_NOM = 'Facture exemple';

    private const DEMO_STUB_DESCRIPTION = 'Prestation exemple';

    public static function isConfigured(): bool
    {
        return CurrentAssociation::tryGet()?->anthropic_api_key !== null;
    }

    private function apiKey(): ?string
    {
        return CurrentAssociation::tryGet()?->anthropic_api_key;
    }

    /**
     * @param  array{tiers_attendu?: string, operation_attendue?: string, seance_attendue?: int, reference_attendue?: string, date_attendue?: string}|null  $context
     */
    public function analyze(UploadedFile $file, ?array $context = null): InvoiceOcrResult
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
            context: $context,
        );
    }

    /**
     * @param  array{tiers_attendu?: string, operation_attendue?: string, seance_attendue?: int, reference_attendue?: string, date_attendue?: string}|null  $context
     */
    public function analyzeFromPath(string $path, string $mime, ?array $context = null): InvoiceOcrResult
    {
        if (Demo::isActive()) {
            return $this->demoStub();
        }

        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            throw new OcrNotConfiguredException;
        }

        if (! file_exists($path)) {
            throw new OcrAnalysisException('Fichier introuvable : '.$path);
        }

        return $this->performAnalysis(
            apiKey: $apiKey,
            base64: base64_encode(file_get_contents($path)),
            mime: $mime,
            context: $context,
        );
    }

    private function demoStub(): InvoiceOcrResult
    {
        return new InvoiceOcrResult(
            date: now()->format('Y-m-d'),
            reference: 'DEMO-001',
            tiers_id: null,
            tiers_nom: self::DEMO_STUB_TIERS_NOM,
            montant_total: self::DEMO_STUB_MONTANT,
            lignes: [
                new InvoiceOcrLigne(
                    description: self::DEMO_STUB_DESCRIPTION,
                    sous_categorie_id: null,
                    operation_id: null,
                    seance: null,
                    montant: self::DEMO_STUB_MONTANT,
                ),
            ],
            warnings: [],
        );
    }

    /**
     * @param  array{tiers_attendu?: string, operation_attendue?: string, seance_attendue?: int, reference_attendue?: string, date_attendue?: string}|null  $context
     */
    private function performAnalysis(string $apiKey, string $base64, string $mime, ?array $context): InvoiceOcrResult
    {
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
- L'association n'est PAS assujettie à la TVA et ne la récupère pas. Utilise TOUJOURS les montants TTC. Si la facture affiche des montants HT avec TVA, calcule le TTC pour chaque ligne (montant HT × (1 + taux TVA)). Pour montant_total, utilise le "Net à payer" ou "Total TTC".
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
            $ctxBlock = $this->buildContextBlock($context);

            if ($ctxBlock !== '') {
                $prompt .= $ctxBlock;
            }
        }

        $prompt .= "\n\nRéponds UNIQUEMENT avec le JSON, sans commentaire ni bloc markdown.";

        return $prompt;
    }

    /**
     * Construit le bloc "CONTEXTE ENCADRANT" injecté dans le prompt lorsque des valeurs
     * attendues sont fournies. Retourne une chaîne vide si aucune clé reconnue n'est présente.
     *
     * @param  array{tiers_attendu?: string, operation_attendue?: string, seance_attendue?: int, reference_attendue?: string, date_attendue?: string}  $context
     * @return string Empty string if $context contains no recognized key.
     */
    private function buildContextBlock(array $context): string
    {
        $ctxParts = [];
        $warningExamples = [];

        if (isset($context['tiers_attendu'])) {
            $ctxParts[] = "Tiers attendu : {$context['tiers_attendu']}";
            $warningExamples[] = '"Le tiers sur la facture (X) ne correspond pas au tiers sélectionné (Y)"';
        }

        if (isset($context['operation_attendue'])) {
            $ctxParts[] = "Opération attendue : {$context['operation_attendue']}";
            $warningExamples[] = '"L\'opération détectée (X) ne correspond pas à l\'opération sélectionnée (Y)"';
        }

        if (isset($context['seance_attendue'])) {
            $ctxParts[] = "Séance attendue : {$context['seance_attendue']}";
            $warningExamples[] = '"La séance détectée (N) ne correspond pas à la séance sélectionnée (M)"';
        }

        if (isset($context['reference_attendue'])) {
            $ctxParts[] = "Numéro de facture attendu : {$context['reference_attendue']}";
            $warningExamples[] = '"Le numéro extrait (X) ne correspond pas au numéro déposé (Y)"';
        }

        if (isset($context['date_attendue'])) {
            $ctxParts[] = "Date de facture attendue : {$context['date_attendue']}";
            $warningExamples[] = '"La date extraite (X) ne correspond pas à la date déposée (Y)"';
        }

        if ($ctxParts === []) {
            return '';
        }

        $ctxStr = implode("\n", $ctxParts);
        $examplesStr = implode("\n- ", $warningExamples);

        return <<<BLOCK


CONTEXTE ENCADRANT (valeurs attendues) :
{$ctxStr}

Compare les informations extraites de la facture avec ce contexte. Si une valeur ne correspond pas, ajoute un warning dans le champ "warnings". Exemples :
- {$examplesStr}
BLOCK;
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
