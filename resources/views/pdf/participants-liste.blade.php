<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Participants — {{ $operation->nom }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm;
        }
        table { width: 100%; border-collapse: collapse; }

        /* Header */
        .header { margin-bottom: 14px; }
        .header .logo { max-height: 50px; max-width: 100px; }
        .association-name { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 9px; color: #6c757d; }
        .doc-title { font-size: 15px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 10px; color: #6c757d; text-align: right; margin-top: 2px; }

        /* Table */
        .data-table { margin-top: 10px; }
        .data-table th {
            background-color: #3d5473;
            color: #fff;
            padding: 5px 6px;
            font-size: 9px;
            text-align: left;
            font-weight: 600;
        }
        .data-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
            font-size: 9px;
        }
        .data-table tr:nth-child(even) td { background-color: #f8f9fa; }

        .text-right { text-align: right; }
        .text-muted { color: #6c757d; }
        .fw-bold { font-weight: bold; }

        /* Footer pagination */
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

    {{-- Header --}}
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
                    {{ $operation->date_debut?->format('d/m/Y') }} → {{ $operation->date_fin?->format('d/m/Y') ?? '...' }}
                    · {{ $participants->count() }} participants
                </div>
            </td>
        </tr>
    </table>

    {{-- Table --}}
    <table class="data-table">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Prénom</th>
                <th>Téléphone</th>
                <th>Email</th>
                <th>Inscription</th>
                @if($confidentiel)
                    <th>Date naiss.</th>
                    <th>Âge</th>
                    <th>Sexe</th>
                    <th>Taille</th>
                    <th>Poids</th>
                    <th>Référé par</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($participants as $p)
                @php
                    $med = $confidentiel ? $p->donneesMedicales : null;
                    $age = null;
                    if ($med?->date_naissance) {
                        try { $age = \Carbon\Carbon::parse($med->date_naissance)->age; } catch (\Throwable) {}
                    }
                @endphp
                <tr>
                    <td class="fw-bold">{{ $p->tiers->nom ?? '' }}</td>
                    <td>{{ $p->tiers->prenom ?? '' }}</td>
                    <td>{{ $p->tiers->telephone ?? '' }}</td>
                    <td>{{ $p->tiers->email ?? '' }}</td>
                    <td>{{ $p->date_inscription?->format('d/m/Y') }}</td>
                    @if($confidentiel)
                        <td>{{ $med?->date_naissance ? \Carbon\Carbon::parse($med->date_naissance)->format('d/m/Y') : '' }}</td>
                        <td>{{ $age !== null ? $age.' ans' : '' }}</td>
                        <td>{{ $med?->sexe ?? '' }}</td>
                        <td>{{ $med?->taille ? $med->taille.' cm' : '' }}</td>
                        <td>{{ $med?->poids ? $med->poids.' kg' : '' }}</td>
                        <td>{{ $p->referePar?->displayName() ?? '' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top: 12px; font-size: 8px; color: #999; text-align: right;">
        Généré le {{ now()->format('d/m/Y à H:i') }}
        @if($confidentiel) — <strong style="color:#A9014F">CONFIDENTIEL</strong>@endif
    </div>
</body>
</html>
