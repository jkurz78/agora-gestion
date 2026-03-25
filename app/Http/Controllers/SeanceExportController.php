<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SeanceExportController extends Controller
{
    public function __invoke(Request $request, Operation $operation): BinaryFileResponse
    {
        $seances = Seance::where('operation_id', $operation->id)->orderBy('numero')->get();

        $participants = Participant::where('operation_id', $operation->id)
            ->with('tiers')
            ->get()
            ->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? '')))
            ->values();

        $seanceIds = $seances->pluck('id');
        $presences = Presence::whereIn('seance_id', $seanceIds)->get();
        $presenceMap = [];
        foreach ($presences as $p) {
            $presenceMap[$p->seance_id.'-'.$p->participant_id] = $p;
        }

        $filename = 'seances-'.Str::slug($operation->nom).'-'.now()->format('Y-m-d').'.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $writer = new Writer();
        $options = $writer->getOptions();

        $border = new \OpenSpout\Common\Entity\Style\Border(
            new \OpenSpout\Common\Entity\Style\BorderPart(
                \OpenSpout\Common\Entity\Style\BorderName::BOTTOM,
                '000000',
                \OpenSpout\Common\Entity\Style\BorderWidth::THIN,
                \OpenSpout\Common\Entity\Style\BorderStyle::SOLID
            ),
            new \OpenSpout\Common\Entity\Style\BorderPart(
                \OpenSpout\Common\Entity\Style\BorderName::TOP,
                '000000',
                \OpenSpout\Common\Entity\Style\BorderWidth::THIN,
                \OpenSpout\Common\Entity\Style\BorderStyle::SOLID
            ),
            new \OpenSpout\Common\Entity\Style\BorderPart(
                \OpenSpout\Common\Entity\Style\BorderName::LEFT,
                '000000',
                \OpenSpout\Common\Entity\Style\BorderWidth::THIN,
                \OpenSpout\Common\Entity\Style\BorderStyle::SOLID
            ),
            new \OpenSpout\Common\Entity\Style\BorderPart(
                \OpenSpout\Common\Entity\Style\BorderName::RIGHT,
                '000000',
                \OpenSpout\Common\Entity\Style\BorderWidth::THIN,
                \OpenSpout\Common\Entity\Style\BorderStyle::SOLID
            ),
        );

        $base = (new Style())->withBorder($border);
        $bold = (new Style())->withFontBold(true)->withBorder($border);
        $boldCenter = (new Style())->withFontBold(true)->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $kineOuiCenter = (new Style())->withBackgroundColor('D4EDDA')->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $kineNonCenter = (new Style())->withBackgroundColor('F8D7DA')->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $commentStyle = (new Style())->withFontSize(9)->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $centerStyle = (new Style())->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $nameStyle = (new Style())->withCellVerticalAlignment(\OpenSpout\Common\Entity\Style\CellVerticalAlignment::CENTER)->withBorder($border);

        // 1-based indices for mergeCells(colStart, rowStart, colEnd, rowEnd, sheetIndex)
        // Col A=1 (Participant), then per séance: col 2+i*2 (Présence), col 3+i*2 (Kiné)
        $rowNum = 1;

        $writer->openToFile($tempPath);

        // Row 1: Séance numbers (merged across 2 cols each)
        $cells = [Cell::fromValue('Participant', $bold)];
        foreach ($seances as $i => $seance) {
            $cells[] = Cell::fromValue('S'.$seance->numero, $boldCenter);
            $cells[] = Cell::fromValue('', $base);
            $colStart = 1 + $i * 2;
            $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Row 2: Titres (merged)
        $cells = [Cell::fromValue('', $base)];
        foreach ($seances as $i => $seance) {
            $cells[] = Cell::fromValue($seance->titre ?? '', $centerStyle);
            $cells[] = Cell::fromValue('', $base);
            $colStart = 1 + $i * 2;
            $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Row 3: Dates (merged)
        $cells = [Cell::fromValue('', $base)];
        foreach ($seances as $i => $seance) {
            $cells[] = Cell::fromValue($seance->date?->format('d/m/Y') ?? '', $centerStyle);
            $cells[] = Cell::fromValue('', $base);
            $colStart = 1 + $i * 2;
            $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Row 4: Sub-headers Présence / Kiné
        $cells = [Cell::fromValue('', $base)];
        foreach ($seances as $seance) {
            $cells[] = Cell::fromValue('Présence', $bold);
            $cells[] = Cell::fromValue('Kiné', $bold);
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Participants
        foreach ($participants as $p) {
            // Ligne 1: Présence + Kiné
            $cells = [Cell::fromValue(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? ''), $nameStyle)];
            foreach ($seances as $seance) {
                $key = $seance->id.'-'.$p->id;
                $presence = $presenceMap[$key] ?? null;
                $statut = $presence?->statut ?? '';
                $kine = $presence?->kine ?? '';

                $statusLabel = match ($statut) {
                    'present' => 'Présent',
                    'excuse' => 'Excusé',
                    'absence_non_justifiee' => 'Abs. non justif.',
                    'arret' => 'Arrêt',
                    default => '',
                };

                $cells[] = Cell::fromValue($statusLabel, $base);

                $kineLabel = match ($kine) {
                    'oui' => 'Oui',
                    'non' => 'Non',
                    default => '',
                };
                $kineStyle = match ($kine) {
                    'oui' => $kineOuiCenter,
                    'non' => $kineNonCenter,
                    default => null,
                };
                $cells[] = Cell::fromValue($kineLabel, $kineStyle ?? $centerStyle);
            }
            $writer->addRow(new Row($cells));
            $rowNum++;

            // Ligne 2: Commentaires (merged)
            $cells = [Cell::fromValue('', $base)];
            foreach ($seances as $i => $seance) {
                $key = $seance->id.'-'.$p->id;
                $presence = $presenceMap[$key] ?? null;
                $commentaire = $presence?->commentaire ?? '';
                $cells[] = Cell::fromValue($commentaire, $commentStyle);
                $cells[] = Cell::fromValue('', $base);
                $colStart = 1 + $i * 2;
                $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
            }
            $writer->addRow(new Row($cells));
            $rowNum++;
        }

        // Totaux (merged)
        $cells = [Cell::fromValue('Présents', $bold)];
        foreach ($seances as $i => $seance) {
            $presents = 0;
            foreach ($participants as $p) {
                $k = $seance->id.'-'.$p->id;
                if (isset($presenceMap[$k]) && $presenceMap[$k]->statut === 'present') {
                    $presents++;
                }
            }
            $cells[] = Cell::fromValue($presents.'/'.$participants->count(), $boldCenter);
            $cells[] = Cell::fromValue('', $base);
            $colStart = 1 + $i * 2;
            $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
        }
        $writer->addRow(new Row($cells));

        $writer->close();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
