<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuestionnairePaperScan;
use App\Tenant\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class QuestionnaireScanImageController extends Controller
{
    public function __invoke(QuestionnairePaperScan $scan): Response
    {
        abort_unless((int) $scan->association_id === (int) TenantContext::currentId(), 403);

        $fullPath = 'associations/'.TenantContext::currentId().'/'.$scan->chemin_fichier;

        abort_unless(Storage::disk('local')->exists($fullPath), 404);

        $content = Storage::disk('local')->get($fullPath);
        $mime = Storage::disk('local')->mimeType($fullPath) ?: 'image/png';

        return response($content, 200, ['Content-Type' => $mime]);
    }
}
