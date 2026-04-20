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

        if (! Storage::disk('local')->exists($ligne->piece_jointe_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($ligne->piece_jointe_path);
    }
}
