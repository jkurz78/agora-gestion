<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\RapportService;
use Livewire\Component;

final class RapportSeances extends Component
{
    public ?int $operation_id = null;

    public function exportCsv()
    {
        if (! $this->operation_id) {
            return;
        }

        $rapportService = app(RapportService::class);
        $data = $rapportService->rapportSeances($this->operation_id);
        $operation = Operation::findOrFail($this->operation_id);
        $nbSeances = $operation->nombre_seances ?? 0;

        $headers = ['Sous-catégorie', 'Type'];
        for ($i = 1; $i <= $nbSeances; $i++) {
            $headers[] = 'Séance '.$i;
        }
        $headers[] = 'Total';

        $rows = [];
        foreach ($data as $row) {
            $csvRow = [$row['sous_categorie'], $row['type']];
            for ($i = 1; $i <= $nbSeances; $i++) {
                $csvRow[] = number_format($row['seances'][$i] ?? 0, 2, ',', '');
            }
            $csvRow[] = number_format($row['total'], 2, ',', '');
            $rows[] = $csvRow;
        }

        $csv = $rapportService->toCsv($rows, $headers);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'rapport_seances_'.$this->operation_id.'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function render()
    {
        $operations = Operation::whereNotNull('nombre_seances')
            ->where('nombre_seances', '>', 0)
            ->orderBy('nom')
            ->get();

        $data = [];
        $operation = null;
        if ($this->operation_id) {
            $rapportService = app(RapportService::class);
            $data = $rapportService->rapportSeances($this->operation_id);
            $operation = Operation::find($this->operation_id);
        }

        return view('livewire.rapport-seances', [
            'operations' => $operations,
            'data' => $data,
            'operation' => $operation,
        ]);
    }
}
