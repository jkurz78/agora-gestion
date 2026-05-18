<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class TransactionLignePieceJointeController extends Controller
{
    public function __invoke(Transaction $transaction, TransactionLigne $ligne): Response
    {
        // Defensive check : ligne appartient bien à cette transaction
        if ((int) $ligne->transaction_id !== (int) $transaction->id) {
            abort(404);
        }

        if ($ligne->piece_jointe_path === null) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($ligne->piece_jointe_path)) {
            abort(404);
        }

        $filename = basename($ligne->piece_jointe_path);
        $asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'piece-jointe';
        $encodedFilename = rawurlencode($filename);

        return $disk->response(
            $ligne->piece_jointe_path,
            $filename,
            [
                'Content-Disposition' => "attachment; filename=\"{$asciiFilename}\"; filename*=UTF-8''{$encodedFilename}",
                'Content-Security-Policy' => 'sandbox',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            ]
        );
    }
}
