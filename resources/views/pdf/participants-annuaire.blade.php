<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Annuaire participants — {{ $operation->nom }}</title>
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

        /* Cards grid */
        .cards { width: 100%; }
        .card {
            border: 2px solid #aaaaaa;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 10px;
            page-break-inside: avoid;
            background: #fff;
        }
        .card-name {
            font-size: 15px;
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
            font-size: 10px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: inline-block;
            width: 85px;
        }
        .card-value {
            font-size: 12px;
        }
        .confidentiel-badge {
            font-size: 9px;
            color: #A9014F;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

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
                                        @if($confidentiel)
                                        @if($p->referePar)
                                            <span class="card-row">
                                                <span class="card-label">Référé par</span>
                                                <span class="card-value">{{ $p->referePar->displayName() }}</span>
                                            </span>
                                        @endif
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
                                            @php
                                                $medecinNom = $p->medecinTiers
                                                    ? trim(($p->medecinTiers->prenom ? $p->medecinTiers->prenom.' ' : '').$p->medecinTiers->nom)
                                                    : trim(($med?->medecin_prenom ? $med->medecin_prenom.' ' : '').($med?->medecin_nom ?? ''));
                                                $therapeuteNom = $p->therapeuteTiers
                                                    ? trim(($p->therapeuteTiers->prenom ? $p->therapeuteTiers->prenom.' ' : '').$p->therapeuteTiers->nom)
                                                    : trim(($med?->therapeute_prenom ? $med->therapeute_prenom.' ' : '').($med?->therapeute_nom ?? ''));
                                            @endphp
                                            @if($medecinNom)
                                                <span class="card-row">
                                                    <span class="card-label">Médecin</span>
                                                    <span class="card-value">{{ $medecinNom }}</span>
                                                </span>
                                            @endif
                                            @if($therapeuteNom)
                                                <span class="card-row">
                                                    <span class="card-label">Thérapeute</span>
                                                    <span class="card-value">{{ $therapeuteNom }}</span>
                                                </span>
                                            @endif
                                            @if($p->mode_paiement_choisi || $p->moyen_paiement_choisi || $p->typeOperationTarif)
                                                <span class="card-row">
                                                    <span class="card-label">Paiement</span>
                                                    <span class="card-value">
                                                        {{-- mode --}}
                                                        @if($p->mode_paiement_choisi)
                                                            {{ $p->mode_paiement_choisi === 'comptant' ? 'Comptant' : 'Par séance' }}
                                                        @endif
                                                        {{-- moyen --}}
                                                        @if($p->moyen_paiement_choisi)
                                                            @if($p->mode_paiement_choisi) · @endif{{ ucfirst($p->moyen_paiement_choisi) }}
                                                        @endif
                                                        {{-- tarif --}}
                                                        @if($p->typeOperationTarif)
                                                            — {{ $p->typeOperationTarif->libelle }} ({{ number_format((float)$p->typeOperationTarif->montant, 2, ',', ' ') }} €)
                                                        @endif
                                                    </span>
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            </table>
                            @if($confidentiel && $med?->notes)
                                <div style="margin-top:4px;padding-top:4px;border-top:1px solid #eee">
                                    <span class="card-label">Notes</span>
                                    <div class="card-value" style="font-size:8px;color:#444;line-height:1.3">{!! $med->notes !!}</div>
                                </div>
                            @endif
                            @if($showPrescripteur && ($p->adresse_par_nom || $p->adresse_par_etablissement))
                                <div style="margin-top:4px;padding-top:4px;border-top:1px solid #eee">
                                    <span class="card-label">Adressé par</span>
                                    <span class="card-value">
                                        {{ trim(($p->adresse_par_prenom ? $p->adresse_par_prenom.' ' : '').($p->adresse_par_nom ?? '')) }}
                                        @if($p->adresse_par_etablissement)
                                            @if($p->adresse_par_nom) — @endif{{ $p->adresse_par_etablissement }}
                                        @endif
                                    </span>
                                </div>
                            @endif
                            @if($showDroitImage && $p->droit_image)
                                <div style="margin-top:4px;padding-top:4px;border-top:1px solid #eee">
                                    <span class="card-label">Droit image</span>
                                    <span class="card-value">{{ $p->droit_image->label() }}</span>
                                </div>
                            @endif
        </div>
    @endforeach

    @if($confidentiel)
        <div style="position: fixed; top: 5mm; right: 10mm; font-size: 9px; color: #A9014F; font-weight: bold; letter-spacing: 1px;">
            CONFIDENTIEL
        </div>
    @endif
</body>
</html>
