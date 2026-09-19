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
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
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

        // Colonne de participation optionnelle : null = une seule colonne par
        // séance (présence), sinon présence + participation.
        $operation->loadMissing('typeOperation');
        $participationLibelle = $operation->typeOperation?->libelleParticipationSeance();
        $colonnesParSeance = $participationLibelle !== null ? 2 : 1;

        $filename = 'seances-'.Str::slug($operation->nom).'-'.now()->format('Y-m-d').'.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $writer = new Writer;
        $options = $writer->getOptions();

        $border = new Border(
            new BorderPart(
                BorderName::BOTTOM,
                '000000',
                BorderWidth::THIN,
                BorderStyle::SOLID
            ),
            new BorderPart(
                BorderName::TOP,
                '000000',
                BorderWidth::THIN,
                BorderStyle::SOLID
            ),
            new BorderPart(
                BorderName::LEFT,
                '000000',
                BorderWidth::THIN,
                BorderStyle::SOLID
            ),
            new BorderPart(
                BorderName::RIGHT,
                '000000',
                BorderWidth::THIN,
                BorderStyle::SOLID
            ),
        );

        $base = (new Style)->withBorder($border);
        $bold = (new Style)->withFontBold(true)->withBorder($border);
        $boldCenter = (new Style)->withFontBold(true)->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $kineOuiCenter = (new Style)->withBackgroundColor('D4EDDA')->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $kineNonCenter = (new Style)->withBackgroundColor('F8D7DA')->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $commentStyle = (new Style)->withFontSize(9)->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $centerStyle = (new Style)->withCellAlignment(CellAlignment::CENTER)->withBorder($border);
        $nameStyle = (new Style)->withCellVerticalAlignment(CellVerticalAlignment::CENTER)->withBorder($border);

        // 1-based indices for mergeCells(colStart, rowStart, colEnd, rowEnd, sheetIndex)
        // Col A=1 (Participant), then par séance : col 2+i*$colonnesParSeance (Présence),
        // col 3+i*$colonnesParSeance (participation, si activée)
        $rowNum = 1;

        $writer->openToFile($tempPath);

        // Column widths: A=Participant (25), puis Présence (18) [+ participation (8) si activée]
        $options->setColumnWidth(25.0, 1);
        for ($i = 0; $i < $seances->count(); $i++) {
            $options->setColumnWidth(18.0, 2 + $i * $colonnesParSeance);  // Présence
            if ($participationLibelle !== null) {
                $options->setColumnWidth(8.0, 3 + $i * $colonnesParSeance);  // Participation
            }
        }

        // Row 1: Séance numbers (merged across colonnesParSeance cols each)
        $cells = [Cell::fromValue('Participant', $bold)];
        foreach ($seances as $i => $seance) {
            $cells[] = Cell::fromValue('S'.$seance->numero, $boldCenter);
            if ($participationLibelle !== null) {
                $cells[] = Cell::fromValue('', $base);
                $colStart = 1 + $i * $colonnesParSeance;
                $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
            }
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Row 2: Titres (merged)
        $cells = [Cell::fromValue('', $base)];
        foreach ($seances as $i => $seance) {
            $cells[] = Cell::fromValue($seance->titre ?? '', $centerStyle);
            if ($participationLibelle !== null) {
                $cells[] = Cell::fromValue('', $base);
                $colStart = 1 + $i * $colonnesParSeance;
                $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
            }
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Row 3: Dates (merged)
        $cells = [Cell::fromValue('', $base)];
        foreach ($seances as $i => $seance) {
            $cells[] = Cell::fromValue($seance->date?->format('d/m/Y') ?? '', $centerStyle);
            if ($participationLibelle !== null) {
                $cells[] = Cell::fromValue('', $base);
                $colStart = 1 + $i * $colonnesParSeance;
                $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
            }
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Row 4: Sub-headers Présence / participation
        $cells = [Cell::fromValue('', $base)];
        foreach ($seances as $seance) {
            $cells[] = Cell::fromValue('Présence', $boldCenter);
            if ($participationLibelle !== null) {
                $cells[] = Cell::fromValue($participationLibelle, $boldCenter);
            }
        }
        $writer->addRow(new Row($cells));
        $rowNum++;

        // Participants
        foreach ($participants as $p) {
            // Ligne 1: Présence + participation
            $cells = [Cell::fromValue(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? ''), $nameStyle)];
            // Merge participant name vertically across 2 rows (présence + commentaire)
            $options->mergeCells(0, $rowNum, 0, $rowNum + 1, 0);
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

                $cells[] = Cell::fromValue($statusLabel, $centerStyle);

                if ($participationLibelle !== null) {
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
            }
            $writer->addRow(new Row($cells));
            $rowNum++;

            // Ligne 2: Commentaires (merged)
            $cells = [Cell::fromValue('', $base)];
            foreach ($seances as $i => $seance) {
                $key = $seance->id.'-'.$p->id;
                $presence = $presenceMap[$key] ?? null;
                $commentaire = $presence?->commentaire ?? '';
                $cells[] = Cell::fromValue($commentaire !== '' ? $commentaire : ' ', $commentStyle);
                if ($participationLibelle !== null) {
                    $cells[] = Cell::fromValue(' ', $commentStyle);
                    $colStart = 1 + $i * $colonnesParSeance;
                    $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
                }
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
            if ($participationLibelle !== null) {
                $cells[] = Cell::fromValue('', $base);
                $colStart = 1 + $i * $colonnesParSeance;
                $options->mergeCells($colStart, $rowNum, $colStart + 1, $rowNum, 0);
            }
        }
        $writer->addRow(new Row($cells));

        $writer->close();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
