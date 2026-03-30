<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Autorisation de prise de vues — {{ $participant->tiers?->prenom }} {{ $participant->tiers?->nom }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 13px;
            color: #212529;
            line-height: 1.5;
            margin: 18mm 15mm 20mm 15mm;
        }

        /* Header */
        .header { margin-bottom: 18px; }
        .header .logo { max-height: 80px; max-width: 160px; }
        .association-name { font-size: 15px; font-weight: bold; margin-bottom: 2px; }
        .association-address { font-size: 11px; color: #6c757d; }
        .doc-title {
            font-size: 17px;
            font-weight: bold;
            color: #A9014F;
            text-align: right;
        }
        .doc-subtitle { font-size: 12px; color: #6c757d; text-align: right; margin-top: 2px; }

        /* Identity block */
        .identity-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .identity-table td { padding: 3px 6px; font-size: 13px; }
        .identity-label { font-weight: bold; width: 90px; }
        .identity-value { border-bottom: 1px solid #aaa; padding-bottom: 1px; }

        /* Explanatory card */
        .text-card {
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 16px;
            font-size: 12px;
            line-height: 1.55;
            background: #fafafa;
        }
        .text-card p { margin-bottom: 6px; }
        .text-card p:last-child { margin-bottom: 0; }

        /* Choices block */
        .choices-header {
            font-style: italic;
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 13px;
        }
        .choice-row {
            display: block;
            margin-bottom: 9px;
            font-size: 13px;
        }
        .choice-bullet {
            display: inline-block;
            font-size: 15px;
            vertical-align: middle;
            margin-right: 6px;
            line-height: 1;
        }
        .choice-selected { color: #212529; font-weight: bold; }
        .choice-unselected { color: #888; }

        /* Signature line */
        .signature {
            margin-top: 24px;
            font-size: 11px;
            color: #555;
            font-style: italic;
            border-top: 1px dashed #ccc;
            padding-top: 8px;
        }

        /* Footer pagination */
        .page-number:after { content: counter(page) " / " counter(pages); }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="footer"><span class="page-number"></span></div>
    @if($footerLogoBase64)
        <div style="position: fixed; bottom: 10mm; left: 10mm;">
            <img src="data:{{ $footerLogoMime }};base64,{{ $footerLogoBase64 }}" style="height: 12mm;" alt="">
        </div>
    @endif

    {{-- Header --}}
    <table class="header">
        <tr>
            <td style="width:55%; vertical-align:top;">
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
            <td style="width:45%; vertical-align:top;">
                <div class="doc-title">AUTORISATION DE PRISE DE VUES</div>
                <div class="doc-subtitle">Saison sportive : {{ $exerciceLabel }}</div>
            </td>
        </tr>
    </table>

    {{-- Identity block --}}
    <table class="identity-table">
        <tr>
            <td class="identity-label">DATE</td>
            <td class="identity-value">
                {{ $participant->formulaireToken?->rempli_at?->format('d/m/Y') ?? $participant->date_inscription?->format('d/m/Y') ?? '—' }}
            </td>
            <td style="width:20px;"></td>
            <td class="identity-label">NOM</td>
            <td class="identity-value">{{ $participant->tiers?->nom ?? '—' }}</td>
        </tr>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td class="identity-label">Prénom</td>
            <td class="identity-value">{{ $participant->tiers?->prenom ?? '—' }}</td>
        </tr>
    </table>

    {{-- Explanatory text (mirrors step-6.blade.php) --}}
    <div class="text-card">
        <p>Nous avons l'habitude dans les ateliers {{ $qualificatifPluriel }} de proposer la prise de photos à différents temps de l'atelier. Ces photos peuvent être individuelles ou de groupe.</p>
        <p>Ces photos sont réalisées à titre de souvenir mais aussi pour vous permettre d'évaluer avec le temps tout votre cheminement {{ $qualificatif }}.</p>
        <p>Nous vous proposerons, au fil des séances, de vous photographier individuellement ou en groupe avec votre cheval ou poney.</p>
        <p>Les photos vous seront remises individuellement en téléchargement informatique sécurisé à la fin de chaque séance et vous devrez <strong>vous engager au préalable à ne les utiliser que pour votre usage personnel</strong>, éventuellement à l'usage du groupe ou avec l'accord écrit des personnes photographiées en cas de diffusion.</p>
        <p>Le groupe peut également être amené à donner son accord à la diffusion de certaines photos dans le cadre de la formation des équipes encadrantes des ateliers {{ $qualificatifPluriel }} et ce, à visée didactique.</p>
        <p>Vous pouvez à tout moment modifier votre décision en en faisant part au responsable de l'équipe encadrante de votre atelier.</p>
    </div>

    {{-- Choices --}}
    @php
        use App\Enums\DroitImage;
        $choix = $participant->droit_image;
    @endphp

    <div class="choices-header">J'inscris ci-dessous l'accord que je donne parmi les propositions qui suivent :</div>

    @php
        $options = [
            ['enum' => DroitImage::UsagePropre,       'label' => 'Je donne mon accord pour la prise de photos/vidéos me concernant pour mon usage propre'],
            ['enum' => DroitImage::UsageConfidentiel, 'label' => 'Je donne mon accord pour la prise de photos/vidéos me concernant et pour un usage confidentiel au sein de l\'équipe '.$qualificatif],
            ['enum' => DroitImage::Diffusion,         'label' => 'Je donne mon accord pour la prise de photos/vidéos me concernant et pour une diffusion'],
            ['enum' => DroitImage::Refus,             'label' => 'Je ne donne pas mon accord pour la prise de photos/vidéos'],
        ];
    @endphp

    @foreach($options as $option)
        @php $selected = ($choix === $option['enum']); @endphp
        <div class="choice-row {{ $selected ? 'choice-selected' : 'choice-unselected' }}">
            <span class="choice-bullet">{{ $selected ? '●' : '○' }}</span>
            {!! $selected ? '<strong>'.$option['label'].'</strong>' : $option['label'] !!}
        </div>
    @endforeach

    {{-- Electronic signature --}}
    @if($participant->formulaireToken?->rempli_at)
        <div class="signature">
            Signé électroniquement le {{ $participant->formulaireToken->rempli_at->format('d/m/Y à H:i') }}
        </div>
    @endif

    <div style="margin-top: 10px; font-size: 9px; color: #bbb; text-align: right;">
        Généré le {{ now()->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
