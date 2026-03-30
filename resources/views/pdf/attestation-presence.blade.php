<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Attestation de présence — {{ $operation->nom }}</title>
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

        .attestation-title {
            text-align: center;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin: 90px 0 90px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .attestation-body {
            font-size: 12px;
            line-height: 1.8;
            margin: 90px 10px;
            text-align: justify;
        }

        .seance-table { margin: 16px 0; border: 1px solid #999; }
        .seance-table th {
            background-color: #fff;
            color: #333;
            padding: 8px 12px;
            font-size: 11px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #333;
            border-left: 1px solid #ddd;
        }
        .seance-table th:first-child { border-left: none; }
        .seance-table td {
            padding: 6px 12px;
            border-bottom: 1px solid #dee2e6;
            font-size: 11px;
            vertical-align: middle;
        }
        .seance-table tr:nth-child(even) td { background-color: #f8f9fa; }
        .seance-table .col-num { width: 10%; text-align: center; }
        .seance-table .col-date { width: 25%; }
        .seance-table .col-titre { width: 65%; }

        .total-line {
            font-size: 12px;
            font-weight: 600;
            margin: 12px 0 20px 0;
        }

        .signature-block {
            margin-top: 90px;
            margin-left: 8cm;
            font-size: 12px;
            line-height: 1.8;
        }

        .cachet-img {
            max-height: 120px;
            margin-top: 10px;
        }

        .page-break { page-break-after: always; }

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
    <div class="footer">Généré le {{ now()->translatedFormat('j F Y à H:i') }} — <span class="page-number"></span></div>
    @if($footerLogoBase64)
        <div style="position: fixed; bottom: 10mm; left: 10mm;">
            <img src="data:{{ $footerLogoMime }};base64,{{ $footerLogoBase64 }}" style="height: 15mm;" alt="">
        </div>
    @endif

    @if($mode === 'seance')
        {{-- Mode séance: one page per participant --}}
        @foreach($participants as $index => $p)
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
                        </div>
                    </td>
                </tr>
            </table>

            <div class="attestation-title">Attestation de présence</div>

            <div class="attestation-body">
                L'association <strong>{{ $association?->nom ?? '' }}</strong> atteste que
                <strong>{{ $p->tiers->prenom ?? '' }} {{ $p->tiers->nom ?? '' }}</strong>,
                a participé à la séance n°{{ $seance->numero }}@if($seance->titre), « {{ $seance->titre }} »@endif
                du {{ $seance->date ? $seance->date->translatedFormat('j F Y') : 'date non définie' }}
                dans le cadre de {{ $operation->typeOperation?->nom ?? '' }} <strong>{{ $operation->nom }}</strong>.
            </div>

            <div class="signature-block">
                Fait à {{ $association?->ville ?? '' }}, le {{ now()->translatedFormat('j F Y') }}
                @if($cachetBase64)
                    <br>
                    <img class="cachet-img" src="data:{{ $cachetMime }};base64,{{ $cachetBase64 }}" alt="Cachet">
                @endif
            </div>


            @if(!$loop->last)
                <div class="page-break"></div>
            @endif
        @endforeach

    @else
        {{-- Mode recap: single participant, table of séances --}}
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
                        @if($operation->date_debut && $operation->date_fin)
                            Du {{ $operation->date_debut->format('d/m/Y') }} au {{ $operation->date_fin->format('d/m/Y') }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>

        <div class="attestation-title">Attestation de présence</div>

        <div class="attestation-body">
            L'association <strong>{{ $association?->nom ?? '' }}</strong> atteste que
            <strong>{{ $participant->tiers->prenom ?? '' }} {{ $participant->tiers->nom ?? '' }}</strong>,
            a participé aux séances suivantes
            dans le cadre de {{ $operation->typeOperation?->nom ?? '' }} <strong>{{ $operation->nom }}</strong> :
        </div>

        <table class="seance-table">
            <thead>
                <tr>
                    <th class="col-num">N°</th>
                    <th class="col-date">Date</th>
                    <th class="col-titre">Titre</th>
                </tr>
            </thead>
            <tbody>
                @foreach($seancesPresent as $s)
                    <tr>
                        <td class="col-num">{{ $s->numero }}</td>
                        <td>{{ $s->date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $s->titre ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total-line">
            {{ $seancesPresent->count() }} séance(s) sur {{ $totalSeances }}
        </div>

        <div class="signature-block">
            Fait à {{ $association?->ville ?? '' }}, le {{ now()->translatedFormat('j F Y') }}
            @if($cachetBase64)
                <br>
                <img class="cachet-img" src="data:{{ $cachetMime }};base64,{{ $cachetBase64 }}" alt="Cachet">
            @endif
        </div>

        <div style="margin-top: 12px; font-size: 8px; color: #999; text-align: right;">
            Généré le {{ now()->translatedFormat('j F Y à H:i') }}
        </div>
    @endif
</body>
</html>
