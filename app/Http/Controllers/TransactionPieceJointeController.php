<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class TransactionPieceJointeController extends Controller
{
    public function __invoke(Request $request, Transaction $transaction): Response
    {
        if (! $transaction->hasPieceJointe()) {
            abort(404);
        }

        $fullPath = $transaction->pieceJointeFullPath();

        if ($fullPath === null || ! Storage::disk('local')->exists($fullPath)) {
            abort(404);
        }

        $downloadName = $transaction->piece_jointe_nom;
        if ($transaction->numero_piece) {
            $downloadName = $transaction->numero_piece.' - '.$downloadName;
        }

        // Sanitize for Symfony Content-Disposition (refuse / and \)
        $downloadName = str_replace(['/', '\\'], '-', $downloadName);

        return Storage::disk('local')->response(
            $fullPath,
            $downloadName,
            ['Content-Type' => $transaction->piece_jointe_mime],
            'inline'
        );
    }
}
