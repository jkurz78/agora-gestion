<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Reprise : toute adhésion à exercice nul reçoit l'exercice dérivé de sa date de
     * début, selon le mois de début d'exercice de SON association (même règle que
     * AdhesionService::exerciceFromDate). Les dates sont déjà correctes : seul
     * l'exercice est complété.
     *
     * Une adhésion sans date de début ne peut pas être reprise. Elle est laissée telle
     * quelle et comptée — jamais devinée.
     *
     * Requêtes brutes, toutes associations : `artisan migrate` tourne sans TenantContext,
     * et le scope tenant fail-closed (`WHERE 1 = 0`) ferait de toute requête Eloquent un
     * no-op silencieux. Les adhésions supprimées logiquement sont reprises aussi : la
     * déduplication les consulte (withTrashed).
     *
     * Rejouable : seules les lignes encore à exercice nul sont touchées.
     * Toute-ou-rien : une violation de la clé unique annule la reprise entière.
     */
    public function up(): void
    {
        $bilan = $this->reprendre();

        Log::info('[migration] Exercice des adhésions repris', $bilan);

        if ($bilan['laissees_intactes'] > 0) {
            Log::warning('[migration] Adhésions sans date de début : exercice laissé nul, à examiner', $bilan);
        }
    }

    /**
     * @return array{reprises: int, laissees_intactes: int}
     */
    public function reprendre(): array
    {
        return DB::transaction(function (): array {
            $reprises = 0;

            $aReprendre = DB::table('adhesions')
                ->leftJoin('association', 'association.id', '=', 'adhesions.association_id')
                ->whereNull('adhesions.exercice')
                ->whereNotNull('adhesions.date_debut')
                ->orderBy('adhesions.id')
                ->get(['adhesions.id', 'adhesions.date_debut', 'association.exercice_mois_debut']);

            foreach ($aReprendre as $ligne) {
                $debut = CarbonImmutable::parse($ligne->date_debut);
                $moisDebut = (int) ($ligne->exercice_mois_debut ?? 9);
                $exercice = $debut->month >= $moisDebut ? $debut->year : $debut->year - 1;

                $reprises += DB::table('adhesions')
                    ->where('id', $ligne->id)
                    ->whereNull('exercice')
                    ->update(['exercice' => $exercice]);
            }

            $laisseesIntactes = DB::table('adhesions')
                ->whereNull('exercice')
                ->whereNull('date_debut')
                ->count();

            return ['reprises' => $reprises, 'laissees_intactes' => $laisseesIntactes];
        });
    }

    public function down(): void
    {
        // Non réversible : impossible de distinguer un exercice repris d'un exercice saisi.
    }
};
