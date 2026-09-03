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

    /**
     * Clé d'exercice d'une date, telle qu'elle entre dans le numéro de pièce.
     *
     * Le mois de début d'exercice est un réglage de l'association, pas une
     * constante : cette méthode codait septembre en dur, ce qui rattachait
     * toute pièce d'une association en exercice civil au mauvais exercice.
     * ExerciceService porte les deux règles — quelle année pour une date, et
     * comment cette année s'écrit (« 2025-2026 » en exercice décalé, « 2026 »
     * tout court en exercice civil).
     */
    public function exerciceFromDate(Carbon $date): string
    {
        $exerciceService = app(ExerciceService::class);

        return $exerciceService->label($exerciceService->anneeForDate($date));
    }
}
