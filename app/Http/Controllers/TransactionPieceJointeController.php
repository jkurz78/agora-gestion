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

        if (! Storage::disk('local')->exists($transaction->piece_jointe_path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $transaction->piece_jointe_path,
            $transaction->piece_jointe_nom,
            ['Content-Type' => $transaction->piece_jointe_mime],
            'inline'
        );
    }
}
