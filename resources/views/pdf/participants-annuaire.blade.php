<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Annuaire participants — {{ $operation->nom }}</title>
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

        /* Cards grid */
        .cards { width: 100%; }
        .card {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 10px;
            page-break-inside: avoid;
            background: #fff;
        }
        .card-name {
            font-size: 12px;
            font-weight: bold;
            color: #A9014F;
            margin-bottom: 4px;
            border-bottom: 1px solid #eee;
            padding-bottom: 4px;
        }
        .card-row {
            display: block;
            margin-bottom: 2px;
        }
        .card-label {
            font-size: 8px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: inline-block;
            width: 70px;
        }
        .card-value {
            font-size: 9px;
        }
        .confidentiel-badge {
            font-size: 7px;
            color: #A9014F;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

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

    {{-- Cards in single column --}}
    @foreach($participants->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? ''))) as $p)
        @php
            $med = $confidentiel ? $p->donneesMedicales : null;
            $age = null;
            if ($med?->date_naissance) {
                try { $age = \Carbon\Carbon::parse($med->date_naissance)->age; } catch (\Throwable) {}
            }
        @endphp
        <div class="card">
                            <div class="card-name">
                                {{ $p->tiers->prenom }} {{ $p->tiers->nom }}
                            </div>
                            <table style="width:100%;border-collapse:collapse">
                                <tr>
                                    <td style="width:50%;vertical-align:top;padding-right:10px">
                                        {{-- Colonne gauche : coordonnées --}}
                                        @if($p->tiers->adresse_ligne1)
                                            <span class="card-row">
                                                <span class="card-label">Adresse</span>
                                                <span class="card-value">{{ $p->tiers->adresse_ligne1 }}</span>
                                            </span>
                                        @endif
                                        @if($p->tiers->code_postal || $p->tiers->ville)
                                            <span class="card-row">
                                                <span class="card-label">Ville</span>
                                                <span class="card-value">{{ $p->tiers->code_postal }} {{ $p->tiers->ville }}</span>
                                            </span>
                                        @endif
                                        @if($p->tiers->telephone)
                                            <span class="card-row">
                                                <span class="card-label">Tél.</span>
                                                <span class="card-value">{{ $p->tiers->telephone }}</span>
                                            </span>
                                        @endif
                                        @if($p->tiers->email)
                                            <span class="card-row">
                                                <span class="card-label">Email</span>
                                                <span class="card-value">{{ $p->tiers->email }}</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td style="width:50%;vertical-align:top;padding-left:10px">
                                        {{-- Colonne droite : inscription + données sensibles --}}
                                        <span class="card-row">
                                            <span class="card-label">Inscrit le</span>
                                            <span class="card-value">{{ $p->date_inscription?->format('d/m/Y') }}</span>
                                        </span>
                                        @if($p->referePar)
                                            <span class="card-row">
                                                <span class="card-label">Référé par</span>
                                                <span class="card-value">{{ $p->referePar->displayName() }}</span>
                                            </span>
                                        @endif
                                        @if($confidentiel)
                                            @if($med?->date_naissance)
                                                <span class="card-row">
                                                    <span class="card-label">Naissance</span>
                                                    <span class="card-value">{{ \Carbon\Carbon::parse($med->date_naissance)->format('d/m/Y') }}{{ $age !== null ? ' ('.$age.' ans)' : '' }}</span>
                                                </span>
                                            @endif
                                            @if($med?->sexe)
                                                <span class="card-row">
                                                    <span class="card-label">Sexe</span>
                                                    <span class="card-value">{{ $med->sexe === 'F' ? 'Féminin' : 'Masculin' }}</span>
                                                </span>
                                            @endif
                                            @if($med?->taille || $med?->poids)
                                                <span class="card-row">
                                                    <span class="card-label">Morpho.</span>
                                                    <span class="card-value">
                                                        {{ $med?->taille ? $med->taille.' cm' : '' }}
                                                        {{ $med?->taille && $med?->poids ? ' / ' : '' }}
                                                        {{ $med?->poids ? $med->poids.' kg' : '' }}
                                                    </span>
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            </table>
        </div>
    @endforeach

    <div style="margin-top: 8px; font-size: 8px; color: #999; text-align: right;">
        Généré le {{ now()->format('d/m/Y à H:i') }}
        @if($confidentiel) — <span class="confidentiel-badge">CONFIDENTIEL</span>@endif
    </div>
</body>
</html>
