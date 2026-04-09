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
            margin: 15mm 15mm 25mm 15mm;
        }
        table { width: 100%; border-collapse: collapse; }

        .header { margin-bottom: 14px; }
        .header .logo { max-height: 96px; max-width: 192px; }
        .association-name { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 9px; color: #6c757d; }
        .doc-title { font-size: 15px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 10px; color: #6c757d; text-align: right; margin-top: 2px; }
        .qr-top-right {
            position: absolute;
            top: 10mm;
            right: 10mm;
            width: 96px;
            height: 96px;
        }

        .emargement-title {
            text-align: center;
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin: 8px 0 10px 0;
        }
        .data-table { margin-top: 0; border: 1px solid #999; }
        .data-table th {
            background-color: #fff;
            color: #333;
            padding: 10px 12px;
            font-size: 13px;
            text-align: left;
            font-weight: 600;
            border-top: 1px solid #999;
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

    </style>
</head>
<body>
    @include('pdf.partials.footer-logos')

    <table class="header">
        <tr>
            <td style="width:60%;vertical-align:top;padding-top:0">
                @if($headerLogoBase64)
                    <img class="logo" src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" alt="Logo" style="margin-top:-5mm">
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
            <td style="width:40%;padding-right:25mm;vertical-align:top">
                <div class="doc-title" style="margin-top:-5mm">{{ $operation->nom }}</div>
                <div class="doc-subtitle">
                    Séance {{ $seance->numero }}{{ $seance->titre ? ' — '.$seance->titre : '' }}<br>
                    {{ $seance->date?->format('d/m/Y') ?? 'Date non définie' }}
                    · {{ $participants->count() }} participants
                </div>
            </td>
        </tr>
    </table>

    <img class="qr-top-right"
         src="data:image/png;base64,{{ $qrBase64 }}"
         alt="Code de suivi">


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
            @for($i = 0; $i < $emptyRows; $i++)
                <tr>
                    <td>&nbsp;</td>
                    <td class="col-signature">&nbsp;</td>
                    @if($isConfidentiel)
                        <td class="col-kine"><span class="checkbox-empty"></span></td>
                    @endif
                    <td>&nbsp;</td>
                </tr>
            @endfor
        </tbody>
    </table>

</body>
</html>
