<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\Tiers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class EmailLogAttachmentController
{
    public function __invoke(Request $request): Response
    {
        $emailLogId = (int) $request->route('emailLog');
        $emailLog = EmailLog::find($emailLogId);
        abort_unless($emailLog !== null, 404);

        // Multi-tenant guard : EmailLog n'extends pas TenantModel.
        // On vérifie l'appartenance au tenant via Tiers (qui est TenantModel-scopé).
        $emailTiers = Tiers::find($emailLog->tiers_id);
        abort_unless($emailTiers !== null, 404);

        abort_unless($emailLog->attachment_path !== null, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($emailLog->attachment_path), 404);

        $contents = $disk->get($emailLog->attachment_path);
        abort_unless($contents !== null, 404);

        $mime = $disk->mimeType($emailLog->attachment_path) ?: 'application/octet-stream';

        // RFC 5987 Content-Disposition avec fallback ASCII pour vieux clients
        $filename = basename($emailLog->attachment_path);
        $asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'piece-jointe';
        $encodedFilename = rawurlencode($filename);
        $contentDisposition = "inline; filename=\"{$asciiFilename}\"; filename*=UTF-8''{$encodedFilename}";

        Log::info('email-log.attachment.telecharge', [
            'email_log_id' => $emailLog->id,
            'user_id' => Auth::id(),
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
