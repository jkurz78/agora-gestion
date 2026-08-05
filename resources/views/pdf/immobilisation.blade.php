@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #212529; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .sub { color: #6c757d; font-size: 10px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #3d5473; color: #fff; text-align: left; padding: 5px 6px; font-size: 10px; }
        td { padding: 4px 6px; border-bottom: 1px solid #dee2e6; }
        .num { text-align: right; }
        .prev { color: #6c757d; font-style: italic; }
        dl { margin: 0; }
        dt { float: left; clear: left; width: 130px; font-weight: bold; }
        dd { margin-left: 140px; margin-bottom: 3px; }
    </style>
</head>
<body>
    <h1>{{ $immobilisation->numero }} — {{ $immobilisation->libelle }}</h1>
    <div class="sub">{{ $association?->nom }} — fiche d'immobilisation éditée le {{ now()->format('d/m/Y') }}</div>

    <dl>
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

    <table>
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
                <tr class="{{ $ligne['comptabilisee'] ? '' : 'prev' }}">
                    <td>{{ $exerciceService->label($ligne['exercice']) }}</td>
                    <td class="num">{{ $ligne['moisEcoules'] }}</td>
                    <td class="num">{{ $euros($ligne['dotationCentimes']) }} €</td>
                    <td class="num">{{ $euros($ligne['cumulCentimes']) }} €</td>
                    <td class="num">{{ $euros($ligne['valeurNetteCentimes']) }} €</td>
                    <td>{{ $ligne['comptabilisee'] ? 'Comptabilisée' : 'Prévisionnel' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
