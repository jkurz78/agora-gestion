<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture {{ $facture->numero ?? 'Brouillon' }}</title>
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
        table.layout td { vertical-align: top; padding: 0; }

        /* Header */
        .header { margin-bottom: 18px; }
        .header .logo { max-height: 60px; max-width: 120px; }
        .association-name { font-size: 14px; font-weight: bold; margin-bottom: 2px; }
        .association-subtitle { font-size: 10px; color: #6c757d; margin-bottom: 2px; }
        .association-address { font-size: 10px; color: #6c757d; }

        /* Title block */
        .title-block { margin-bottom: 16px; }
        .doc-title { font-size: 22px; font-weight: bold; color: #0d6efd; }
        .doc-info { font-size: 11px; margin-top: 4px; }
        .doc-info span { display: block; margin-bottom: 2px; }

        /* Client block */
        .client-block {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        .client-label {
            font-size: 9px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: bold;
        }
        .client-name { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
        .client-address { font-size: 10px; color: #555; }

        /* Lines table */
        .lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
        }
        .lines-table thead tr { background-color: #e9ecef; }
        .lines-table thead th {
            padding: 6px 8px;
            text-align: left;
            font-size: 10px;
            color: #212529;
            font-weight: bold;
            border-bottom: 2px solid #dee2e6;
        }
        .lines-table thead th.text-end { text-align: right; }
        .lines-table tbody td {
            padding: 5px 8px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        .lines-table tbody td.text-end { text-align: right; }
        .lines-table .row-even { background-color: #f9f9f9; }
        .lines-table .ligne-texte td {
            font-weight: bold;
            color: #333;
            padding-top: 8px;
        }
        .lines-table tfoot tr {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .lines-table tfoot td {
            padding: 6px 8px;
            border-top: 2px solid #dee2e6;
        }
        .lines-table tfoot td.text-end { text-align: right; }

        /* Acquittee stamp */
        .stamp-acquittee {
            display: inline-block;
            border: 3px solid #2E7D32;
            color: #2E7D32;
            font-size: 18px;
            font-weight: bold;
            padding: 6px 18px;
            text-transform: uppercase;
            letter-spacing: 2px;
            transform: rotate(-5deg);
            margin: 12px 0;
        }

        /* Bank details */
        .bank-section {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        .bank-section .section-label {
            font-size: 9px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: bold;
        }
        .bank-section table td {
            padding: 2px 8px 2px 0;
            font-size: 10px;
        }
        .bank-section .label { color: #6c757d; font-weight: bold; }

        /* Footer */
        .footer-section {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #dee2e6;
            font-size: 9px;
            color: #6c757d;
        }
        .footer-section p { margin-bottom: 4px; }
        .footer-section .mentions { white-space: pre-line; }

        /* Watermark brouillon */
        .watermark {
            position: fixed;
            top: 35%;
            left: 10%;
            font-size: 80px;
            font-weight: bold;
            color: rgba(220, 53, 69, 0.12);
            transform: rotate(-35deg);
            letter-spacing: 8px;
            text-transform: uppercase;
            z-index: 0;
            pointer-events: none;
        }
    </style>
</head>
<body>

    @unless ($facture->numero)
        <div class="watermark">BROUILLON</div>
    @endunless

    {{-- HEADER --}}
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width: 60%;">
                    @if ($headerLogoBase64)
                        <img src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" class="logo" alt="Logo">
                    @endif
                    @if ($association)
                        <div class="association-name">{{ $association->nom }}</div>
                        @if ($association->forme_juridique)
                            <div class="association-subtitle">{{ $association->forme_juridique }}</div>
                        @endif
                        <div class="association-address">
                            @if ($association->adresse){{ $association->adresse }}<br>@endif
                            @if ($association->code_postal || $association->ville){{ $association->code_postal }} {{ $association->ville }}<br>@endif
                            @if ($association->email){{ $association->email }}@endif
                            @if ($association->email && $association->telephone) &mdash; @endif
                            @if ($association->telephone){{ $association->telephone }}@endif
                        </div>
                        @if ($association->siret)
                            <div class="association-address" style="margin-top: 2px;">SIRET : {{ $association->siret }}</div>
                        @endif
                    @endif
                </td>
                <td style="width: 40%; text-align: right;">
                    @if ($facture->statut === \App\Enums\StatutFacture::Annulee && $facture->numero_avoir && ! ($forceOriginalFormat ?? false))
                        <div class="doc-title">AVOIR</div>
                        <div class="doc-info">
                            <span><strong>N&deg; :</strong> {{ $facture->numero_avoir }}</span>
                            <span><strong>Date :</strong> {{ $facture->date_annulation->format('d/m/Y') }}</span>
                        </div>
                        <div style="font-size: 9px; color: #6c757d; margin-top: 4px;">
                            Annule la facture {{ $facture->numero }}
                            du {{ $facture->date->format('d/m/Y') }}
                        </div>
                    @else
                        <div class="doc-title">FACTURE</div>
                        <div class="doc-info">
                            <span><strong>N&deg; :</strong> {{ $facture->numero ?? 'Brouillon' }}</span>
                            <span><strong>Date :</strong> {{ $facture->date->format('d/m/Y') }}</span>
                        </div>
                        @if (($forceOriginalFormat ?? false) && $facture->numero_avoir)
                            <div style="font-size: 9px; color: #dc3545; margin-top: 4px;">
                                Annulée — Avoir {{ $facture->numero_avoir }} du {{ $facture->date_annulation->format('d/m/Y') }}
                            </div>
                        @endif
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- CLIENT BLOCK --}}
    <div class="client-block">
        <div class="client-label">Client</div>
        <div class="client-name">{{ $facture->tiers->displayName() }}</div>
        <div class="client-address">
            @if ($facture->tiers->adresse_ligne1){{ $facture->tiers->adresse_ligne1 }}<br>@endif
            @if ($facture->tiers->code_postal || $facture->tiers->ville){{ $facture->tiers->code_postal }} {{ $facture->tiers->ville }}@endif
        </div>
    </div>

    {{-- LINES TABLE --}}
    @php
        $lignes = $facture->lignes->sortBy('ordre');
        $montantIndex = 0;
        $signAnnulee = ($facture->statut === \App\Enums\StatutFacture::Annulee && ! ($forceOriginalFormat ?? false)) ? '-' : '';
    @endphp

    <table class="lines-table">
        <thead>
            <tr>
                <th style="width: 50%;">D&eacute;signation</th>
                <th class="text-end" style="width: 15%;">Prix unitaire</th>
                <th class="text-end" style="width: 10%;">Quantit&eacute;</th>
                <th class="text-end" style="width: 25%;">Montant (&euro;)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lignes as $ligne)
                @if ($ligne->type === \App\Enums\TypeLigneFacture::Texte)
                    {{-- Ligne texte : libellé sur toute la largeur, colonnes monétaires vides --}}
                    <tr class="ligne-texte">
                        <td colspan="4">{{ $ligne->libelle }}</td>
                    </tr>
                @elseif ($ligne->type === \App\Enums\TypeLigneFacture::MontantLibre)
                    {{-- Ligne montant libre : libellé + PU + Qté + montant --}}
                    <tr class="{{ $montantIndex % 2 === 1 ? 'row-even' : '' }}">
                        <td>{{ $ligne->libelle }}</td>
                        <td class="text-end">{{ number_format((float) $ligne->prix_unitaire, 2, ',', "\u{00A0}") }} &euro;</td>
                        <td class="text-end">{{ number_format((float) $ligne->quantite, 3, ',', "\u{00A0}") }}</td>
                        <td class="text-end">{{ $signAnnulee }}{{ number_format((float) $ligne->montant, 2, ',', "\u{00A0}") }} &euro;</td>
                    </tr>
                    @php $montantIndex++; @endphp
                @else
                    {{-- Ligne montant (ref) : libellé + montant, PU et Qté vides --}}
                    <tr class="{{ $montantIndex % 2 === 1 ? 'row-even' : '' }}">
                        <td>{{ $ligne->libelle }}</td>
                        <td class="text-end"></td>
                        <td class="text-end"></td>
                        <td class="text-end">{{ $signAnnulee }}{{ number_format((float) $ligne->montant, 2, ',', "\u{00A0}") }} &euro;</td>
                    </tr>
                    @php $montantIndex++; @endphp
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total</td>
                <td class="text-end">{{ $signAnnulee }}{{ number_format($facture->montantCalcule(), 2, ',', "\u{00A0}") }} &euro;</td>
            </tr>
        </tfoot>
    </table>

    @if ($facture->statut !== \App\Enums\StatutFacture::Annulee || ($forceOriginalFormat ?? false))
    {{-- PAIEMENT --}}
    @php $resteDu = $facture->montantCalcule() - $montantRegle; @endphp
    <table style="width: 50%; margin-left: auto; margin-bottom: 12px; font-size: 10px; border-collapse: collapse;">
        <tr>
            <td style="padding: 4px 8px; color: #6c757d;">Montant r&eacute;gl&eacute;</td>
            <td style="padding: 4px 8px; text-align: right;">{{ number_format($montantRegle, 2, ',', "\u{00A0}") }} &euro;</td>
        </tr>
        <tr style="font-weight: bold; {{ $resteDu > 0 ? 'color: #B5453A;' : 'color: #2E7D32;' }}">
            <td style="padding: 4px 8px; border-top: 1px solid #dee2e6;">Reste d&ucirc;</td>
            <td style="padding: 4px 8px; text-align: right; border-top: 1px solid #dee2e6;">{{ number_format($resteDu, 2, ',', "\u{00A0}") }} &euro;</td>
        </tr>
    </table>

    {{-- ACQUITTEE STAMP --}}
    @if ($isAcquittee)
        <div style="text-align: center; margin: 12px 0;">
            <div class="stamp-acquittee">Acquitt&eacute;e</div>
        </div>
    @endif
    @endif

    {{-- BANK DETAILS --}}
    @if ($facture->compteBancaire)
        <div class="bank-section">
            <div class="section-label">Coordonn&eacute;es bancaires</div>
            <table class="layout">
                @if ($facture->compteBancaire->domiciliation)
                    <tr>
                        <td class="label" style="width: 25%;">Domiciliation</td>
                        <td>{{ $facture->compteBancaire->domiciliation }}</td>
                    </tr>
                @endif
                @if ($facture->compteBancaire->iban)
                    <tr>
                        <td class="label" style="width: 25%;">IBAN</td>
                        <td>{{ $facture->compteBancaire->iban }}</td>
                    </tr>
                @endif
                @if ($facture->compteBancaire->bic)
                    <tr>
                        <td class="label" style="width: 25%;">BIC</td>
                        <td>{{ $facture->compteBancaire->bic }}</td>
                    </tr>
                @endif
            </table>
        </div>
    @endif

    {{-- FOOTER / CONDITIONS / MENTIONS --}}
    <div class="footer-section">
        @if ($facture->conditions_reglement)
            <p><strong>Conditions de r&egrave;glement :</strong> {{ $facture->conditions_reglement }}</p>
        @endif
        @if ($facture->mode_paiement_prevu !== null)
            <p><strong>Mode de r&egrave;glement pr&eacute;vu :</strong> {{ $facture->mode_paiement_prevu->label() }}</p>
        @endif
        @if ($facture->mentions_legales)
            <p class="mentions">{{ $facture->mentions_legales }}</p>
        @endif
        @if ($facture->tiers->type !== 'particulier' && $mentionsPenalites)
            <p class="mentions" style="margin-top: 6px;">{{ $mentionsPenalites }}</p>
        @endif
    </div>

</body>
</html>
