<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Participants — {{ $operation->nom }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 14px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm 15mm 25mm 15mm;
        }
        table { width: 100%; border-collapse: collapse; }

        /* Header */
        .header { margin-bottom: 14px; }
        .header .logo { max-height: 96px; max-width: 192px; }
        .association-name { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 12px; color: #6c757d; }
        .doc-title { font-size: 18px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 13px; color: #6c757d; text-align: right; margin-top: 2px; }

        /* Table */
        .data-table { margin-top: 10px; }
        .data-table th {
            background-color: #fff;
            color: #212529;
            padding: 5px 6px;
            font-size: 12px;
            font-family: DejaVu Sans, sans-serif;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #212529;
        }
        .data-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #dee2e6;
            font-size: 12px;
        }
        .data-table tr:nth-child(even) td { background-color: #f8f9fa; }

        .text-right { text-align: right; }
        .text-muted { color: #6c757d; }
        .fw-bold { font-weight: bold; }

    </style>
</head>
<body>
    @include('pdf.partials.footer-logos')

    {{-- Header --}}
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
                @if($confidentiel)
                    <th>Date naiss.</th>
                    <th>Âge</th>
                    <th>Sexe</th>
                    <th>Taille</th>
                    <th>Poids</th>
                    <th>Médecin</th>
                    <th>Thérapeute</th>
                @endif
                @if($showDroitImage)
                    <th>D.I.</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($participants as $p)
                @php
                    $med = $confidentiel ? $p->donneesMedicales : null;
                    $dateNaiss = $med?->dateNaissanceCarbon();
                    $age = $dateNaiss?->age;
                @endphp
                <tr>
                    <td class="fw-bold">{{ $p->tiers->nom ?? '' }}</td>
                    <td>{{ $p->tiers->prenom ?? '' }}</td>
                    <td>{{ $p->tiers->telephone ?? '' }}</td>
                    <td>{{ $p->tiers->email ?? '' }}</td>
                    @if($confidentiel)
                        <td>{{ $dateNaiss?->format('d/m/Y') ?? $med?->date_naissance ?? '' }}</td>
                        <td>{{ $age !== null ? $age.' ans' : '' }}</td>
                        <td>{{ $med?->sexe ?? '' }}</td>
                        <td>{{ $med?->taille ? $med->taille.' cm' : '' }}</td>
                        <td>{{ $med?->poids ? $med->poids.' kg' : '' }}</td>
                        <td>{{ $p->medecinTiers?->nom ?? $med?->medecin_nom ?? '' }}</td>
                        <td>{{ $p->therapeuteTiers?->nom ?? $med?->therapeute_nom ?? '' }}</td>
                    @endif
                    @if($showDroitImage)
                        <td>{{ $p->droit_image ? mb_substr($p->droit_image->label(), 0, 1) : '' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($showDroitImage)
    <div style="margin-top: 8px; font-size: 9px; color: #6c757d;">
        <strong>D.I.</strong> : U = Usage propre · C = Usage confidentiel · D = Diffusion · R = Refus
    </div>
    @endif

    @if($confidentiel)
        <div style="position: fixed; top: 5mm; right: 10mm; font-size: 9px; color: #A9014F; font-weight: bold; letter-spacing: 1px;">
            CONFIDENTIEL
        </div>
    @endif
</body>
</html>
