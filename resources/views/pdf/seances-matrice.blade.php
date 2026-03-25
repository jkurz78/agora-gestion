<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Matrice de présences — {{ $operation->nom }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            color: #212529;
            line-height: 1.3;
            margin: 10mm;
        }
        table { width: 100%; border-collapse: collapse; }

        .header { margin-bottom: 10px; }
        .header .logo { max-height: 40px; max-width: 80px; }
        .association-name { font-size: 11px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 8px; color: #6c757d; }
        .doc-title { font-size: 13px; font-weight: bold; color: #A9014F; text-align: right; }
        .doc-subtitle { font-size: 9px; color: #6c757d; text-align: right; margin-top: 2px; }

        .matrix { margin-top: 8px; }
        .matrix th, .matrix td {
            border: 1px solid #ccc;
            padding: 3px 4px;
            text-align: center;
            font-size: 7px;
        }
        .matrix th { background-color: #3d5473; color: #fff; font-weight: 600; }
        .matrix .col-name { text-align: left; font-weight: 500; min-width: 80px; }
        .matrix .header-row td { background: #f8f9fa; font-size: 7px; }
        .matrix .footer-row td { background: #f0f0f0; font-weight: 600; }

        .present { color: #198754; font-weight: bold; }
        .excuse { color: #fd7e14; }
        .absence { color: #dc3545; font-weight: bold; }
        .arret { color: #6c757d; font-style: italic; }
        .kine-oui { background: #d4edda; }
        .kine-non { background: #f8d7da; }

        .page-number:after { content: counter(page) " / " counter(pages); }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7px;
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
                    {{ $operation->date_debut?->format('d/m/Y') }} → {{ $operation->date_fin?->format('d/m/Y') ?? '...' }}
                    · {{ $participants->count() }} participants · {{ $seances->count() }} séances
                </div>
            </td>
        </tr>
    </table>

    <table class="matrix">
        <thead>
            {{-- Séance numbers --}}
            <tr>
                <th rowspan="4" class="col-name" style="vertical-align:middle">Participant</th>
                @foreach($seances as $seance)
                    <th colspan="2" style="text-align:center">S{{ $seance->numero }}</th>
                @endforeach
            </tr>
            {{-- Titre row --}}
            <tr class="header-row">
                @foreach($seances as $seance)
                    <td colspan="2" style="text-align:center">{{ Str::limit($seance->titre, 18) }}</td>
                @endforeach
            </tr>
            {{-- Date row --}}
            <tr class="header-row">
                @foreach($seances as $seance)
                    <td colspan="2" style="text-align:center">{{ $seance->date?->format('d/m/Y') }}</td>
                @endforeach
            </tr>
            {{-- Sub-header: Présence / Kiné --}}
            <tr class="header-row">
                @foreach($seances as $seance)
                    <td style="text-align:center;font-size:6px;color:#888">Présence</td>
                    <td style="text-align:center;font-size:6px;color:#888;width:18px">K</td>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($participants->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? ''))) as $p)
                {{-- Ligne 1 : Présence + Kiné --}}
                <tr>
                    <td rowspan="2" class="col-name" style="vertical-align:middle">{{ $p->tiers->nom }} {{ $p->tiers->prenom }}</td>
                    @foreach($seances as $seance)
                        @php
                            $key = $seance->id . '-' . $p->id;
                            $presence = $presenceMap[$key] ?? null;
                            $statut = $presence?->statut ?? '';
                            $kine = $presence?->kine ?? '';
                            $commentaire = $presence?->commentaire ?? '';

                            $statusClass = match($statut) {
                                'present' => 'present',
                                'excuse' => 'excuse',
                                'absence_non_justifiee' => 'absence',
                                'arret' => 'arret',
                                default => '',
                            };
                            $statusLabel = match($statut) {
                                'present' => 'Présent',
                                'excuse' => 'Excusé',
                                'absence_non_justifiee' => 'Abs.',
                                'arret' => 'Arrêt',
                                default => '',
                            };
                            $kineBg = match($kine) {
                                'oui' => '#d4edda',
                                'non' => '#f8d7da',
                                default => '#fff',
                            };
                        @endphp
                        <td class="{{ $statusClass }}" style="font-size:7px">{{ $statusLabel }}</td>
                        <td style="background:{{ $kineBg }};width:18px">
                            @if($kine === 'oui') <span style="color:#198754">✓</span> @elseif($kine === 'non') <span style="color:#dc3545">✗</span> @endif
                        </td>
                    @endforeach
                </tr>
                {{-- Ligne 2 : Commentaire sur toute la largeur --}}
                <tr>
                    @foreach($seances as $seance)
                        @php
                            $key = $seance->id . '-' . $p->id;
                            $presence = $presenceMap[$key] ?? null;
                            $commentaire = $presence?->commentaire ?? '';
                        @endphp
                        <td colspan="2" style="font-size:6px;color:#888;padding:1px 3px;border-top:none">{{ Str::limit($commentaire, 25) }}</td>
                    @endforeach
                </tr>
            @endforeach
            {{-- Footer totals --}}
            <tr class="footer-row">
                <td class="col-name">Présents</td>
                @foreach($seances as $seance)
                    @php
                        $presents = 0;
                        foreach ($participants as $p) {
                            $k = $seance->id . '-' . $p->id;
                            if (isset($presenceMap[$k]) && $presenceMap[$k]->statut === 'present') $presents++;
                        }
                    @endphp
                    <td colspan="2" style="text-align:center">{{ $presents }}/{{ $participants->count() }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 8px; font-size: 7px; color: #999;">
        Abs. = Absence non justifiée
        <span style="float:right">Généré le {{ now()->format('d/m/Y à H:i') }}</span>
    </div>
</body>
</html>
