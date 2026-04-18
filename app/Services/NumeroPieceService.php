<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CurrentAssociation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class NumeroPieceService
{
    public function assign(Carbon $date): string
    {
        $exercice = $this->exerciceFromDate($date);
        $associationId = CurrentAssociation::id();

        // Garantit que la ligne existe avant le SELECT FOR UPDATE
        // insertOrIgnore = INSERT IGNORE INTO sequences ...
        DB::table('sequences')->insertOrIgnore(
            ['association_id' => $associationId, 'exercice' => $exercice, 'dernier_numero' => 0],
        );

        $sequence = DB::table('sequences')
            ->where('association_id', $associationId)
            ->where('exercice', $exercice)
            ->lockForUpdate()
            ->first();

        $numero = $sequence->dernier_numero + 1;

        DB::table('sequences')
            ->where('association_id', $associationId)
            ->where('exercice', $exercice)
            ->update(['dernier_numero' => $numero, 'updated_at' => now()]);

        return $exercice.':'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }

    public function exerciceFromDate(Carbon $date): string
    {
        $year = $date->year;
        if ($date->month >= 9) {
            return "{$year}-".($year + 1);
        }

        return ($year - 1)."-{$year}";
    }
}
