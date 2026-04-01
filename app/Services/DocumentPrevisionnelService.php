<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\DocumentPrevisionnel;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class DocumentPrevisionnelService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function emettre(
        Operation $operation,
        Participant $participant,
        TypeDocumentPrevisionnel $type,
    ): DocumentPrevisionnel {
        $annee = $this->exerciceService->current();
        $this->exerciceService->assertOuvert($annee);

        $seances = Seance::where('operation_id', $operation->id)
            ->orderBy('numero')
            ->get();

        $reglements = Reglement::where('participant_id', $participant->id)
            ->whereIn('seance_id', $seances->pluck('id'))
            ->get()
            ->keyBy('seance_id');

        $lignes = $this->buildLignes($operation, $seances, $reglements, $type);

        $montantTotal = collect($lignes)
            ->where('type', 'montant')
            ->sum('montant');

        // Check if last version has same amounts → return existing
        $lastVersion = DocumentPrevisionnel::where('operation_id', $operation->id)
            ->where('participant_id', $participant->id)
            ->where('type', $type)
            ->orderByDesc('version')
            ->first();

        if ($lastVersion !== null && (float) $lastVersion->montant_total === (float) $montantTotal) {
            $lastMontants = collect($lastVersion->lignes_json)
                ->where('type', 'montant')
                ->pluck('montant')
                ->map(fn ($m) => (float) $m)
                ->values()
                ->toArray();

            $newMontants = collect($lignes)
                ->where('type', 'montant')
                ->pluck('montant')
                ->map(fn ($m) => (float) $m)
                ->values()
                ->toArray();

            if ($lastMontants === $newMontants) {
                return $lastVersion;
            }
        }

        return DB::transaction(function () use ($operation, $participant, $type, $lignes, $montantTotal, $annee): DocumentPrevisionnel {
            $version = (int) DocumentPrevisionnel::where('operation_id', $operation->id)
                ->where('participant_id', $participant->id)
                ->where('type', $type)
                ->max('version') + 1;

            // Count all documents of this type for this exercice for sequential numbering
            $seq = DocumentPrevisionnel::where('type', $type)
                ->where('exercice', $annee)
                ->count() + 1;

            $numero = sprintf('%s-%d-%03d', $type->prefix(), $annee, $seq);

            return DocumentPrevisionnel::create([
                'operation_id' => $operation->id,
                'participant_id' => $participant->id,
                'type' => $type,
                'numero' => $numero,
                'version' => $version,
                'date' => now()->toDateString(),
                'montant_total' => $montantTotal,
                'lignes_json' => $lignes,
                'pdf_path' => null,
                'saisi_par' => Auth::id(),
                'exercice' => $annee,
            ]);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Seance>  $seances
     * @param  \Illuminate\Support\Collection<int, Reglement>  $reglements
     * @return array<int, array{type: string, libelle: string, montant?: float, seance_id?: int}>
     */
    private function buildLignes(
        Operation $operation,
        \Illuminate\Database\Eloquent\Collection $seances,
        \Illuminate\Support\Collection $reglements,
        TypeDocumentPrevisionnel $type,
    ): array {
        $nbSeances = $seances->count();
        $firstDate = $seances->first()?->date;
        $lastDate = $seances->last()?->date;

        $seanceWord = $nbSeances === 1 ? 'séance' : 'séances';

        $headerLibelle = sprintf(
            '%s du %s au %s en %d %s :',
            $operation->nom,
            $firstDate ? $firstDate->format('d/m/Y') : '—',
            $lastDate ? $lastDate->format('d/m/Y') : '—',
            $nbSeances,
            $seanceWord,
        );

        $lignes = [
            ['type' => 'texte', 'libelle' => $headerLibelle],
        ];

        if ($type === TypeDocumentPrevisionnel::Devis) {
            $total = $reglements->sum('montant_prevu');

            $lignes[] = [
                'type' => 'montant',
                'libelle' => sprintf('%s — %d %s', $operation->nom, $nbSeances, $seanceWord),
                'montant' => (float) $total,
            ];
        } else {
            foreach ($seances as $seance) {
                $reglement = $reglements->get($seance->id);
                $montant = $reglement ? (float) $reglement->montant_prevu : 0.0;

                $lignes[] = [
                    'type' => 'montant',
                    'libelle' => sprintf(
                        'Séance %d — %s',
                        $seance->numero,
                        $seance->date ? $seance->date->format('d/m/Y') : '—',
                    ),
                    'montant' => $montant,
                    'seance_id' => $seance->id,
                ];
            }
        }

        return $lignes;
    }
}
