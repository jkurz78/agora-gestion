<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Feuille de présence — {{ $operation->nom }} — Séance {{ $seance->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm;
        }
        table { width: 100%; border-collapse: collapse; }

        .header { margin-bottom: 14px; }
        .header .logo { max-height: 96px; max-width: 192px; }
        .association-name { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 9px; color: #6c757d; }
        .doc-title { font-size: 15px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 10px; color: #6c757d; text-align: right; margin-top: 2px; }

        .emargement-title {
            text-align: center;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin: 16px 0 10px 0;
        }
        .data-table { margin-top: 0; border: 1px solid #999; }
        .data-table th {
            background-color: #fff;
            color: #333;
            padding: 10px 12px;
            font-size: 13px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #333;
            border-left: 1px solid #ddd;
        }
        .data-table th:first-child { border-left: none; }
        .data-table td {
            padding: 22px 12px;
            border-bottom: 1px solid #dee2e6;
            font-size: 13px;
            vertical-align: middle;
        }
        .data-table tr:nth-child(even) td { background-color: #f8f9fa; }
        .data-table .col-name { width: 25%; }
        .data-table .col-signature { width: 30%; border-left: 1px solid #999; border-right: 1px solid #999; }
        .data-table .col-kine { width: 10%; text-align: center; border-right: 1px solid #999; }
        .data-table .col-obs { width: 35%; }

        .checkbox-empty {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 1.5px solid #666;
            border-radius: 2px;
        }

        .footer-logo {
            height: 12mm;
            vertical-align: middle;
            margin-left: 6px;
            opacity: 0.6;
        }
    </style>
</head>
<body>
    @if($footerLogoBase64)
        <div style="position:fixed; bottom:10mm; left:15mm;">
            <img src="data:{{ $footerLogoMime }};base64,{{ $footerLogoBase64 }}" style="height:12mm; opacity:0.6;" alt="">
        </div>
    @endif
    @if($appLogoBase64)
        <div style="position:fixed; bottom:10mm; right:15mm;">
            <img src="data:image/svg+xml;base64,{{ $appLogoBase64 }}" class="footer-logo" alt="">
        </div>
    @endif
    <script type="text/php">
        $font = $fontMetrics->getFont('DejaVu Sans');
        $size = 8;
        $y = $pdf->get_height() - 36;

        // Centre : pagination (A4 = 595.28pt de large)
        $pageText = "Page {PAGE_NUM} / {PAGE_COUNT}";
        $pageWidth = $fontMetrics->getTextWidth($pageText, $font, $size);
        $pdf->page_text((595.28 - $pageWidth) / 2, $y, $pageText, $font, $size, [0.6, 0.6, 0.6]);

        // Droite (à gauche du logo) : AgoraGestion · date
        $rightText = "AgoraGestion \xC2\xB7 {{ now()->format('d/m/Y H:i') }}";
        $rightWidth = $fontMetrics->getTextWidth($rightText, $font, $size);
        $pdf->page_text($pdf->get_width() - 42 - 40 - $rightWidth, $y, $rightText, $font, $size, [0.6, 0.6, 0.6]);
    </script>

    <table class="header">
        <tr>
            <td style="width:60%">
                @if($headerLogoBase64)
                    <img class="logo" src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" alt="Logo">
                @endif
                @if($association)
                    <div class="association-name">{{ $association->nom }}</div>
                    <div class="association-address">
                        {{ $association->adresse }}
                        @if($association->code_postal || $association->ville)
                            — {{ $association->code_postal }} {{ $association->ville }}
                        @endif
                    </div>
                @endif
            </td>
            <td style="width:40%">
                <div class="doc-title">{{ $operation->nom }}</div>
                <div class="doc-subtitle">
                    Séance {{ $seance->numero }}{{ $seance->titre ? ' — '.$seance->titre : '' }}<br>
                    {{ $seance->date?->format('d/m/Y') ?? 'Date non définie' }}
                    · {{ $participants->count() }} participants
                </div>
            </td>
        </tr>
    </table>

    <div class="emargement-title">Feuille d'émargement</div>

    <table class="data-table">
        <thead>
            <tr>
                <th class="col-name">Participant</th>
                <th class="col-signature">Signature</th>
                @if($isConfidentiel)
                    <th class="col-kine">Kiné</th>
                @endif
                <th class="col-obs">Observations</th>
            </tr>
        </thead>
        <tbody>
            @foreach($participants->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? ''))) as $p)
                <tr>
                    <td style="font-weight:600">{{ $p->tiers->nom ?? '' }} {{ $p->tiers->prenom ?? '' }}</td>
                    <td class="col-signature"></td>
                    @if($isConfidentiel)
                        <td class="col-kine"><span class="checkbox-empty"></span></td>
                    @endif
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

</body>
</html>
