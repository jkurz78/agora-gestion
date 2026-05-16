<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Models\EmailLog;
use App\Models\Tiers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class MessageAttachmentController
{
    public function __invoke(Request $request): Response
    {
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $emailLogId = (int) $request->route('emailLog');
        $emailLog = EmailLog::find($emailLogId);
        abort_unless($emailLog !== null, 404);

        // Multi-tenant guard : EmailLog n'extends pas TenantModel.
        // Chercher le Tiers destinataire via TenantScope (Tiers extends TenantModel).
        // Si Tiers introuvable → cross-tenant → 404.
        $emailTiers = Tiers::find($emailLog->tiers_id);
        abort_unless($emailTiers !== null, 404);

        // Ownership : le Tiers connecté doit être le destinataire.
        // 404 et non 403 pour bloquer l'oracle d'énumération cross-Tiers.
        abort_unless((int) $emailTiers->id === (int) $tiers->id, 404);

        // PJ doit exister
        abort_unless($emailLog->attachment_path !== null, 404);

        // Lecture du fichier (disk 'local' avec path absolu storage/app/...)
        $disk = Storage::disk('local');
        abort_unless($disk->exists($emailLog->attachment_path), 404);

        $contents = $disk->get($emailLog->attachment_path);
        abort_unless($contents !== null, 404);

        // MIME detection
        $mime = $disk->mimeType($emailLog->attachment_path) ?: 'application/octet-stream';

        // RFC 5987 Content-Disposition avec fallback ASCII pour vieux clients
        $filename = basename($emailLog->attachment_path);
        $asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'piece-jointe';
        $encodedFilename = rawurlencode($filename);
        $contentDisposition = "inline; filename=\"{$asciiFilename}\"; filename*=UTF-8''{$encodedFilename}";

        Log::info('portail.message.attachment.telecharge', [
            'email_log_id' => $emailLog->id,
            'tiers_id' => $tiers->id,
        ]);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $contentDisposition,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }
}
