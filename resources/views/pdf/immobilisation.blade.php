@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $immobilisation->numero }} — {{ $immobilisation->libelle }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            margin: 15mm 15mm 25mm 15mm;
            color: #212529;
            line-height: 1.4;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table.layout {
            border-collapse: collapse;
            width: 100%;
        }
        table.layout td {
            vertical-align: top;
            padding: 0;
        }

        /* Header */
        .header {
            margin-bottom: 18px;
        }
        .header .logo {
            max-height: 60px;
            max-width: 120px;
        }
        .association-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .association-address {
            font-size: 10px;
            color: #6c757d;
        }
        .doc-title {
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
            text-align: right;
        }
        .doc-date {
            font-size: 10px;
            color: #6c757d;
            text-align: right;
            margin-top: 4px;
        }

        /* Info block */
        .info-block {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        dl {
            margin: 0;
        }
        dt {
            float: left;
            clear: left;
            width: 130px;
            font-weight: bold;
        }
        dd {
            margin-left: 140px;
            margin-bottom: 3px;
        }

        /* Section title */
        .section-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 2px solid #0d6efd;
            color: #212529;
        }

        /* Plan d'amortissement table */
        .plan-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        .plan-table thead th {
            background: #3d5473;
            color: #fff;
            text-align: left;
            padding: 6px 8px;
            font-size: 10px;
        }
        .plan-table thead th.num {
            text-align: right;
        }
        .plan-table tbody td {
            padding: 5px 8px;
            border-bottom: 1px solid #dee2e6;
        }
        .plan-table tbody td.num {
            text-align: right;
        }
        .plan-table tbody tr.prev {
            color: #6c757d;
            font-style: italic;
        }
    </style>
</head>
<body>
    @include('pdf.partials.footer-logos')

    {{-- HEADER --}}
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width: 60%;">
                    @if ($logoBase64)
                        <img src="data:{{ $logoMime }};base64,{{ $logoBase64 }}" class="logo" alt="Logo">
                    @endif
                    @if ($association)
                        <div class="association-name">{{ $association->nom }}</div>
                        <div class="association-address">
                            @if ($association->adresse){{ $association->adresse }}<br>@endif
                            @if ($association->code_postal || $association->ville){{ $association->code_postal }} {{ $association->ville }}<br>@endif
                            @if ($association->email){{ $association->email }}@endif
                        </div>
                    @endif
                </td>
                <td style="width: 40%;">
                    <div class="doc-title">FICHE IMMOBILISATION {{ $immobilisation->numero }}</div>
                    <div class="doc-date">Généré le {{ \Carbon\Carbon::now()->format('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- INFO BLOCK --}}
    <div class="info-block">
        <dl>
            <dt>Bien</dt><dd><strong>{{ $immobilisation->libelle }}</strong></dd>
            <dt>Quantité</dt><dd>{{ $immobilisation->quantite }}</dd>
            <dt>Compte</dt><dd>{{ $immobilisation->compte->numero_pcg }} — {{ $immobilisation->compte->intitule }}</dd>
            <dt>Amortissements</dt><dd>{{ $immobilisation->compteAmortissement->numero_pcg }} — {{ $immobilisation->compteAmortissement->intitule }}</dd>
            <dt>Mise en service</dt><dd>{{ $immobilisation->date_mise_en_service->format('d/m/Y') }}</dd>
            <dt>Durée</dt><dd>{{ $immobilisation->duree_label }}</dd>
            <dt>Valeur brute</dt><dd>{{ $euros($immobilisation->montantAcquisitionCentimes()) }} €</dd>
            <dt>Valeur nette</dt><dd>{{ $euros($immobilisation->valeurNetteCentimes()) }} €</dd>
            @foreach ($immobilisation->transactionsAcquisition() as $tx)
                <dt>Acquisition</dt>
                <dd>{{ $tx->date->format('d/m/Y') }} — {{ $tx->tiers?->nom_complet ?? '—' }} — pièce {{ $tx->numero_piece ?? '—' }}</dd>
            @endforeach
        </dl>
    </div>

    {{-- PLAN D'AMORTISSEMENT --}}
    <div class="section-title">Plan d'amortissement</div>
    <table class="plan-table">
        <thead>
            <tr>
                <th>Exercice</th>
                <th class="num">Mois</th>
                <th class="num">Dotation</th>
                <th class="num">Cumul</th>
                <th class="num">Valeur nette</th>
                <th>État</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plan as $ligne)
                <tr class="{{ $ligne->comptabilisee ? '' : 'prev' }}">
                    <td>{{ $exerciceService->label($ligne->exercice) }}</td>
                    <td class="num">{{ $ligne->moisEcoules }}</td>
                    <td class="num">{{ $euros($ligne->dotationCentimes) }} €</td>
                    <td class="num">{{ $euros($ligne->cumulCentimes) }} €</td>
                    <td class="num">{{ $euros($ligne->valeurNetteCentimes) }} €</td>
                    <td>{{ $ligne->comptabilisee ? 'Comptabilisée' : 'Prévisionnel' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
