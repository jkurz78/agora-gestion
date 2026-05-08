@php
    /** @var \App\Models\RecuFiscalEmis $recu */
    /** @var \App\Models\Association $asso */
    /** @var \App\Models\Tiers $donateur */
    /** @var string $montantFormate */
    /** @var string $montantEnLettres */
    /** @var string $articleCgiLibelle */
    /** @var string $formeLibelle */
    /** @var string $modeLibelle */
    /** @var string|null $headerLogoBase64 */
    /** @var string|null $headerLogoMime */
    /** @var string|null $cachetBase64 */
    /** @var string|null $cachetMime */
    /** @var string|null $appLogoBase64 */
    /** @var string|null $footerLogoBase64 */
    /** @var string|null $footerLogoMime */
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu fiscal n° {{ $recu->numero }}</title>
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
        table.layout td { vertical-align: top; padding: 0; }

        /* Header */
        .header { margin-bottom: 18px; }
        .header .logo { max-height: 60px; max-width: 120px; }
        .association-name { font-size: 14px; font-weight: bold; margin-bottom: 2px; }
        .association-subtitle { font-size: 10px; color: #6c757d; margin-bottom: 2px; }
        .association-address { font-size: 10px; color: #6c757d; }

        /* Title */
        .doc-title {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            color: #3d5473;
            border-top: 2px solid #3d5473;
            border-bottom: 2px solid #3d5473;
            padding: 8px 0;
            margin: 14px 0 10px 0;
            letter-spacing: 0.5px;
        }
        .doc-numero {
            text-align: center;
            font-size: 10px;
            color: #6c757d;
            margin-bottom: 16px;
        }

        /* Blocs */
        .bloc {
            margin: 10px 0;
            padding: 8px 12px;
            border: 1px solid #dee2e6;
        }
        .bloc-titre {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #fff;
            background-color: #3d5473;
            letter-spacing: 0.5px;
            padding: 3px 12px;
            margin: -8px -12px 8px -12px;
        }
        .bloc-content { font-size: 10px; }
        .bloc-content .label { color: #6c757d; font-weight: bold; }

        /* Donateur / bénéficiaire */
        .nom-principal { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
        .adresse-ligne { font-size: 10px; color: #555; }

        /* Description du don */
        .description-don {
            margin: 12px 0;
            font-size: 10px;
            line-height: 1.6;
        }
        .montant-principal {
            font-size: 15px;
            font-weight: bold;
            color: #3d5473;
        }
        .montant-lettres {
            font-style: italic;
            color: #555;
        }
        .don-detail-table { margin: 10px 0; }
        .don-detail-table td {
            padding: 3px 8px 3px 0;
            font-size: 10px;
            vertical-align: top;
        }
        .don-detail-table .dl-label { color: #6c757d; font-weight: bold; white-space: nowrap; }
        .don-detail-table .dl-value { color: #212529; }

        /* Mention légale */
        .mention-legale {
            font-size: 9px;
            font-style: italic;
            color: #555;
            border-left: 3px solid #3d5473;
            padding: 6px 10px;
            margin: 14px 0;
            background-color: #f8f9fa;
        }

        /* Signature */
        .signature-block {
            margin-top: 24px;
            text-align: right;
            font-size: 10px;
        }
        .signature-block .fait-a {
            margin-bottom: 6px;
            color: #555;
        }
        .signature-block .signataire-nom {
            font-weight: bold;
        }
        .signature-block .signataire-qualite {
            font-style: italic;
            color: #6c757d;
        }
        .signature-block .cachet-img {
            max-height: 80px;
            margin-top: 8px;
        }

        /* Watermark annulé */
        .watermark {
            position: fixed;
            top: 35%;
            left: 5%;
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

    @if ($recu->isAnnule())
        <div class="watermark">ANNULÉ</div>
    @endif

    {{-- FOOTER LOGOS (position: fixed — doit être avant le contenu pour DomPDF) --}}
    @include('pdf.partials.footer-logos')

    {{-- ===== EN-TÊTE ===== --}}
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width:60%">
                    @if (! empty($headerLogoBase64))
                        <img src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}"
                             class="logo" alt="Logo {{ $asso->nom }}">
                    @endif
                    <div class="association-name">{{ $asso->nom }}</div>
                    @if ($asso->forme_juridique ?? null)
                        <div class="association-subtitle">{{ $asso->forme_juridique }}</div>
                    @endif
                    <div class="association-address">
                        @if ($asso->adresse){{ $asso->adresse }}<br>@endif
                        @if ($asso->code_postal || $asso->ville)
                            {{ $asso->code_postal }} {{ $asso->ville }}<br>
                        @endif
                        @if ($asso->email){{ $asso->email }}@endif
                        @if ($asso->email && $asso->telephone) &mdash; @endif
                        @if ($asso->telephone){{ $asso->telephone }}@endif
                    </div>
                    @if ($asso->siret)
                        <div class="association-address" style="margin-top:2px;">
                            SIRET&nbsp;: {{ $asso->siret }}
                        </div>
                    @endif
                </td>
                <td style="width:40%; text-align:right; vertical-align:top;">
                    <div style="font-size:9px; color:#6c757d; margin-bottom:2px;">
                        Association loi 1901
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ===== TITRE ===== --}}
    <div class="doc-title">
        Reçu au titre des dons à certains organismes d'intérêt général
    </div>
    <div class="doc-numero">
        Reçu n° <strong>{{ $recu->numero }}</strong>
        &mdash;
        Émis le {{ $recu->emitted_at->format('d/m/Y') }}
    </div>

    {{-- ===== BLOC BÉNÉFICIAIRE ===== --}}
    <div class="bloc">
        <div class="bloc-titre">Organisme bénéficiaire</div>
        <div class="bloc-content">
            <div class="nom-principal">{{ $asso->nom }}</div>
            @if ($asso->adresse || $asso->code_postal || $asso->ville)
                <div class="adresse-ligne">
                    {{ $asso->adresse }}
                    @if ($asso->adresse && ($asso->code_postal || $asso->ville)) &mdash; @endif
                    {{ $asso->code_postal }} {{ $asso->ville }}
                </div>
            @endif
            @if ($asso->siret)
                <div class="adresse-ligne" style="margin-top:3px;">
                    <span class="label">SIRET :</span> {{ $asso->siret }}
                </div>
            @endif
            @if ($asso->regime_fiscal_don ?? null)
                <div class="adresse-ligne" style="margin-top:3px;">
                    <span class="label">Régime fiscal :</span>
                    {{ $asso->regime_fiscal_don instanceof \App\Enums\RegimeFiscalDon ? $asso->regime_fiscal_don->label() : $asso->regime_fiscal_don }}
                </div>
            @endif
            @if ($asso->objet_recu_fiscal ?? null)
                <div class="adresse-ligne" style="margin-top:3px;">
                    <span class="label">Objet :</span> {{ $asso->objet_recu_fiscal }}
                </div>
            @endif
            @if (($asso->rescrit_fiscal_numero ?? null) && ($asso->rescrit_fiscal_date ?? null))
                <div class="adresse-ligne" style="margin-top:3px;">
                    <span class="label">Rescrit fiscal :</span>
                    n°&nbsp;{{ $asso->rescrit_fiscal_numero }}
                    en date du {{ $asso->rescrit_fiscal_date->format('d/m/Y') }}
                </div>
            @elseif ($asso->rescrit_fiscal_numero ?? null)
                <div class="adresse-ligne" style="margin-top:3px;">
                    <span class="label">Rescrit fiscal :</span>
                    n°&nbsp;{{ $asso->rescrit_fiscal_numero }}
                </div>
            @endif
        </div>
    </div>

    {{-- ===== BLOC DONATEUR ===== --}}
    <div class="bloc">
        <div class="bloc-titre">Donateur</div>
        <div class="bloc-content">
            <div class="nom-principal">{{ $donateur->displayName() }}</div>
            @if ($donateur->type === 'entreprise' && $donateur->displayContact())
                <div class="adresse-ligne">
                    Contact&nbsp;: {{ $donateur->displayContact() }}
                </div>
            @endif
            @if ($donateur->adresse_ligne1)
                <div class="adresse-ligne">{{ $donateur->adresse_ligne1 }}</div>
            @endif
            @if ($donateur->code_postal || $donateur->ville)
                <div class="adresse-ligne">{{ $donateur->code_postal }} {{ $donateur->ville }}</div>
            @endif
            @if ($donateur->pays && $donateur->pays !== 'FR' && $donateur->pays !== 'France')
                <div class="adresse-ligne">{{ $donateur->pays }}</div>
            @endif
            <div class="adresse-ligne" style="margin-top:4px; font-style:italic; color:#888;">
                {{ $donateur->type === 'entreprise' ? 'Personne morale' : 'Personne physique' }}
            </div>
        </div>
    </div>

    {{-- ===== DESCRIPTION DU DON ===== --}}
    <div class="description-don">
        <p style="margin-bottom:8px;">
            L'association <strong>{{ $asso->nom }}</strong> reconnaît avoir reçu
            de <strong>{{ $donateur->displayName() }}</strong>
            la somme de <span class="montant-principal">{{ $montantFormate }}</span>
            <span class="montant-lettres">({{ $montantEnLettres }})</span>.
        </p>

        <table class="don-detail-table layout">
            <tr>
                <td class="dl-label" style="width:30%;">Date du versement</td>
                <td class="dl-value">{{ $recu->date_versement->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td class="dl-label">Mode de versement</td>
                <td class="dl-value">{{ $modeLibelle }}</td>
            </tr>
            <tr>
                <td class="dl-label">Forme du don</td>
                <td class="dl-value">{{ $formeLibelle }}</td>
            </tr>
        </table>
    </div>

    {{-- ===== MENTION LÉGALE ===== --}}
    <div class="mention-legale">
        Le bénéficiaire certifie sur l'honneur que les dons et versements qu'il reçoit ouvrent droit
        à la réduction d'impôt prévue à l'<strong>{{ $articleCgiLibelle }}</strong>
        du Code général des impôts.
    </div>

    @if (($asso->loi_coluche_eligible ?? false) && $articleCgi === 'art_200')
        <div class="mention-legale">
            Cette association entre dans le champ d'application des dispositions du
            <strong>2° de l'article 200-1 ter du CGI</strong> (aide aux personnes en difficulté).
            Le donateur particulier bénéficie d'une réduction d'impôt à hauteur de 75 % du montant versé,
            dans la limite annuelle prévue par la loi.
        </div>
    @endif

    @if ($asso->ifi_eligible ?? false)
        <div class="mention-legale">
            Ce don ouvre également droit, le cas échéant, à la réduction d'<strong>impôt sur la fortune
            immobilière (IFI) prévue à l'article 978 du CGI</strong> à hauteur de 75 % du versement,
            dans la limite annuelle de 50 000 €.
        </div>
    @endif

    {{-- ===== SIGNATURE ===== --}}
    <div class="signature-block">
        <div class="fait-a">
            Fait à {{ $asso->ville ?? '' }}, le {{ $recu->emitted_at->translatedFormat('j F Y') }}
        </div>
        @if ($asso->signataire_nom ?? null)
            <div class="signataire-nom">{{ $asso->signataire_nom }}</div>
        @endif
        @if ($asso->signataire_qualite ?? null)
            <div class="signataire-qualite">{{ $asso->signataire_qualite }}</div>
        @endif
        @if (! empty($cachetBase64))
            <br>
            <img class="cachet-img"
                 src="data:{{ $cachetMime ?? 'image/png' }};base64,{{ $cachetBase64 }}"
                 alt="Cachet">
        @endif
    </div>

</body>
</html>
