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
        .header .logo { max-height: 50px; max-width: 100px; }
        .association-name { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 9px; color: #6c757d; }
        .doc-title { font-size: 15px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 10px; color: #6c757d; text-align: right; margin-top: 2px; }

        .data-table { margin-top: 14px; }
        .data-table th {
            background-color: #fff;
            color: #333;
            padding: 10px 12px;
            font-size: 13px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #333;
        }
        .data-table td {
            padding: 18px 12px;
            border-bottom: 1px solid #dee2e6;
            font-size: 13px;
            vertical-align: middle;
        }
        .data-table tr:nth-child(even) td { background-color: #f8f9fa; }
        .data-table .col-signature { width: 35%; border-left: 1px solid #999; border-right: 1px solid #999; }
        .data-table .col-kine { width: 8%; text-align: center; }
        .data-table .col-obs { width: 20%; }
        }

        .checkbox-empty {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 1.5px solid #666;
            border-radius: 2px;
        }

        .page-number:after { content: counter(page) " / " counter(pages); }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="footer"><span class="page-number"></span></div>

    <table class="header">
        <tr>
            <td style="width:60%">
                @if($logoBase64)
                    <img class="logo" src="data:{{ $logoMime }};base64,{{ $logoBase64 }}" alt="Logo">
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

    <table class="data-table">
        <thead>
            <tr>
                <th>Participant</th>
                <th class="col-signature">Signature</th>
                <th class="col-kine">Kiné</th>
                <th class="col-obs">Observations</th>
            </tr>
        </thead>
        <tbody>
            @foreach($participants->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? ''))) as $p)
                <tr>
                    <td style="font-weight:600">{{ $p->tiers->nom ?? '' }} {{ $p->tiers->prenom ?? '' }}</td>
                    <td class="col-signature"></td>
                    <td class="col-kine"><span class="checkbox-empty"></span></td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top: 12px; font-size: 8px; color: #999; text-align: right;">
        Généré le {{ now()->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
