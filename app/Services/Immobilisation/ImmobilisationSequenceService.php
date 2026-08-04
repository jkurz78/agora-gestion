<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation publique des immobilisations : IM00001, IM00002…
 *
 * Séquence par tenant et NON par exercice, contrairement au numéro de pièce :
 * une immobilisation traverse les exercices.
 */
final class ImmobilisationSequenceService
{
    public function prochain(): string
    {
        $associationId = (int) TenantContext::currentId();

        DB::table('immobilisation_sequences')->insertOrIgnore([
            'association_id' => $associationId,
            'dernier_numero' => 0,
        ]);

        $sequence = DB::table('immobilisation_sequences')
            ->where('association_id', $associationId)
            ->lockForUpdate()
            ->first();

        $numero = (int) $sequence->dernier_numero + 1;

        DB::table('immobilisation_sequences')
            ->where('association_id', $associationId)
            ->update(['dernier_numero' => $numero]);

        return 'IM'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }
}
