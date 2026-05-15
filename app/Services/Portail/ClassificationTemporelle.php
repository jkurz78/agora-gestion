<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Enums\HorizonTemporel;
use App\Models\Operation;
use Illuminate\Support\Carbon;

final class ClassificationTemporelle
{
    public static function pour(Operation $op): HorizonTemporel
    {
        $today = Carbon::today();

        $seances = $op->seances()->orderBy('date')->get();

        if ($seances->isNotEmpty()) {
            /** @var Carbon $min */
            $min = $seances->min('date');
            /** @var Carbon $max */
            $max = $seances->max('date');

            $minDate = Carbon::parse($min);
            $maxDate = Carbon::parse($max);

            if ($minDate->gt($today)) {
                return HorizonTemporel::AVenir;
            }

            if ($maxDate->gte($today)) {
                return HorizonTemporel::EnCours;
            }

            return HorizonTemporel::Terminee;
        }

        $debut = $op->date_debut;
        $fin = $op->date_fin;

        if ($debut !== null && $fin !== null) {
            if ($debut->gt($today)) {
                return HorizonTemporel::AVenir;
            }

            if ($fin->gte($today)) {
                return HorizonTemporel::EnCours;
            }

            return HorizonTemporel::Terminee;
        }

        if ($debut !== null) {
            return $debut->gt($today) ? HorizonTemporel::AVenir : HorizonTemporel::EnCours;
        }

        if ($fin !== null) {
            return $fin->gte($today) ? HorizonTemporel::EnCours : HorizonTemporel::Terminee;
        }

        return HorizonTemporel::EnCours;
    }
}
