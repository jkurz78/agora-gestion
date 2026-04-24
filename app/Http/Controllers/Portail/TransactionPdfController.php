<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Enums\TypeTransaction;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class TransactionPdfController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $transaction = Transaction::findOrFail((int) $request->route('transaction'));

        abort_unless($transaction->hasPieceJointe(), 404);
        abort_unless($transaction->type === TypeTransaction::Depense, 404);

        $tiers = Auth::guard('tiers-portail')->user();

        abort_unless((int) $transaction->tiers_id === (int) $tiers?->id, 403);
        abort_unless((int) $transaction->association_id === (int) TenantContext::currentId(), 403);

        $fullPath = $transaction->pieceJointeFullPath();

        if ($fullPath === null || ! Storage::disk('local')->exists($fullPath)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $fullPath,
            $transaction->piece_jointe_nom,
            ['Content-Type' => $transaction->piece_jointe_mime],
            'inline'
        );
    }
}
