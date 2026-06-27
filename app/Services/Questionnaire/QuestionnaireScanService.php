<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireOcrDraft;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\Contracts\QrDecoderContract;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class QuestionnaireScanService
{
    public function __construct(
        private readonly QrDecoderContract $decoder,
        private readonly QuestionnaireOcrService $ocr,
    ) {}

    public function ingererUpload(UploadedFile $file, ?int $campaignId = null): QuestionnairePaperScan
    {
        $mime = $file->getMimeType() ?? 'image/png';
        $cheminRelatif = $this->stocker($file);

        return $this->ingerer($file->getRealPath(), $mime, $cheminRelatif, 'upload', $campaignId);
    }

    public function ingererPourInvitation(UploadedFile $file, QuestionnaireInvitation $invitation): QuestionnairePaperScan
    {
        $mime = $file->getMimeType() ?? 'image/png';
        $cheminRelatif = $this->stocker($file);
        $filePath = Storage::disk('local')->path('associations/'.TenantContext::currentId().'/'.$cheminRelatif);

        $scan = QuestionnairePaperScan::create([
            'association_id' => TenantContext::currentId(),
            'campaign_id' => (int) $invitation->campaign_id,
            'invitation_id' => (int) $invitation->id,
            'source' => 'upload_manuel',
            'chemin_fichier' => $cheminRelatif,
            'qr_statut' => 'ignore',
            'statut' => 'rattache',
        ]);

        if (QuestionnaireOcrService::isConfigured()) {
            $campaign = $invitation->campaign;
            $campaign->loadMissing('questions');

            $ocrResult = $this->ocr->analyzeFromPath($filePath, $mime, $campaign);

            QuestionnaireOcrDraft::create([
                'association_id' => TenantContext::currentId(),
                'scan_id' => $scan->id,
                'invitation_id' => (int) $invitation->id,
                'payload' => $ocrResult,
                'statut' => 'brouillon',
            ]);
        }

        return $scan;
    }

    public function ingererDepuisFichier(string $path, string $mime, string $source, ?string $token = null, ?int $campaignId = null): QuestionnairePaperScan
    {
        // Copy to tenant storage
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            default => 'png',
        };
        $filename = 'scan-'.now()->format('Ymd-His').'-'.substr(md5((string) mt_rand()), 0, 6).'.'.$ext;
        $cheminRelatif = 'questionnaire-scans/'.$filename;
        $tenantPath = 'associations/'.TenantContext::currentId().'/'.$cheminRelatif;
        Storage::disk('local')->put($tenantPath, (string) file_get_contents($path));

        return $this->ingerer($path, $mime, $cheminRelatif, $source, $campaignId, $token);
    }

    private function ingerer(string $filePath, string $mime, string $cheminRelatif, string $source, ?int $campaignId, ?string $tokenPreDecoded = null): QuestionnairePaperScan
    {
        // 1. Decode QR (or use pre-decoded token from email handler)
        $token = $tokenPreDecoded ?? $this->decoder->decodeFromPath($filePath, $mime);

        // 2. Resolve invitation from token
        $invitation = null;
        $qrStatut = 'illisible';

        if ($token !== null) {
            $qrStatut = 'detecte';
            $tokenHash = hash('sha256', $token);
            $invitation = QuestionnaireInvitation::withoutGlobalScopes()
                ->where('token_hash', $tokenHash)
                ->first();
        }

        // 3. Create scan record
        $scan = QuestionnairePaperScan::create([
            'association_id' => TenantContext::currentId(),
            'campaign_id' => $invitation?->campaign_id ?? $campaignId,
            'invitation_id' => $invitation?->id,
            'source' => $source,
            'chemin_fichier' => $cheminRelatif,
            'qr_statut' => $qrStatut,
            'statut' => $invitation !== null ? 'rattache' : 'en_attente',
        ]);

        // 4. If invitation resolved AND OCR configured, run OCR
        if ($invitation !== null && QuestionnaireOcrService::isConfigured()) {
            $campaign = $invitation->campaign;
            $campaign->loadMissing('questions');

            $ocrResult = $this->ocr->analyzeFromPath($filePath, $mime, $campaign);

            QuestionnaireOcrDraft::create([
                'association_id' => TenantContext::currentId(),
                'scan_id' => $scan->id,
                'invitation_id' => $invitation->id,
                'payload' => $ocrResult,
                'statut' => 'brouillon',
            ]);
        }

        return $scan;
    }

    private function stocker(UploadedFile $file): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'png';
        $filename = 'scan-'.now()->format('Ymd-His').'-'.substr(md5((string) mt_rand()), 0, 6).'.'.$ext;
        $cheminRelatif = 'questionnaire-scans/'.$filename;
        $tenantPath = 'associations/'.TenantContext::currentId().'/'.$cheminRelatif;
        Storage::disk('local')->put($tenantPath, (string) file_get_contents($file->getRealPath()));

        return $cheminRelatif;
    }
}
