@php
    use App\Enums\TypeQuestion;
    /**
     * Variables attendues :
     *   $campagne    — QuestionnaireCampaign
     *   $nomAsso     — string
     *   $logoDataUri — string|null  (data URI de l'image)
     *   $groupes     — array<int, \Illuminate\Support\Collection>  questions découpées en groupes
     *   $pages       — array<int, array{invitation: QuestionnaireInvitation, qr: string, introHtml: string, remerciementHtml: string}>
     */
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $campagne->titre_affiche }} — Questionnaire papier</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #212529;
            line-height: 1.5;
            margin: 12mm 12mm 28mm 12mm;
        }
        table { border-collapse: collapse; }
        table.layout { width: 100%; }
        table.layout td { vertical-align: top; }

        /* ---- Saut de page entre invitations ---- */
        .coupe { page-break-before: always; }

        /* ---- En-tête invitation ---- */
        .invitation-header {
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #3d5473;
        }
        .asso-name {
            font-size: 13px;
            font-weight: bold;
            color: #3d5473;
            margin-bottom: 2px;
        }
        .campagne-titre {
            font-size: 15px;
            font-weight: bold;
            color: #212529;
            margin: 4px 0 2px 0;
        }
        .campagne-intro {
            font-size: 10px;
            color: #555;
            margin-top: 4px;
        }
        .logo-img {
            max-height: 72px;
            max-width: 144px;
        }
        .qr-cell {
            text-align: right;
            width: 140px;
        }
        .code-court {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8px;
            font-weight: normal;
            letter-spacing: 1px;
            color: #999;
            margin-top: 4px;
        }

        /* ---- Pied de page fixe (DomPDF position:fixed) ---- */
        .pdf-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 22mm;
            border-top: 1px solid #ddd;
            padding-top: 4px;
            font-size: 8px;
            color: #888;
        }
        .pdf-footer-left  { float: left; }
        .pdf-footer-right { float: right; }
        .qr-hint {
            font-size: 8px;
            color: #888;
            margin-top: 2px;
        }
        .participant-nom {
            font-size: 10px;
            color: #555;
            margin-top: 6px;
        }

        /* ---- Corps / groupes ---- */
        .groupe-papier {
            background: #f7f9fc;
            border: 1px solid #d8dff0;
            border-left: 3px solid #3d5473;
            border-radius: 3px;
            padding: 10px 12px;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .question {
            margin-bottom: 10px;
            page-break-inside: avoid;
        }
        .question:last-child { margin-bottom: 0; }
        .question-libelle {
            font-size: 11px;
            font-weight: bold;
            color: #212529;
        }
        .question-obligatoire {
            color: #c0392b;
            font-weight: bold;
        }
        .question-aide {
            font-size: 9px;
            color: #6c757d;
            margin-top: 2px;
            margin-bottom: 2px;
        }
        .info-titre {
            font-size: 12px;
            font-weight: bold;
            color: #3d5473;
            margin-bottom: 4px;
            margin-top: 6px;
        }
        .info-aide {
            font-size: 10px;
            color: #555;
        }

        /* ---- Pied d'invitation ---- */
        .consentement {
            margin-top: 14px;
            padding: 8px 10px;
            border: 1px dashed #aaa;
            font-size: 10px;
            color: #444;
            page-break-inside: avoid;
        }
        .remerciement {
            margin-top: 12px;
            text-align: center;
            font-size: 11px;
            color: #3d5473;
            font-weight: bold;
        }
    </style>
</head>
<body>

@foreach($pages as $i => $page)
    @php
        /** @var \App\Models\QuestionnaireInvitation $invitation */
        $invitation       = $page['invitation'];
        $qrDataUri        = $page['qr'];
        $introHtml        = $page['introHtml'] ?? '';
        $remerciementHtml = $page['remerciementHtml'] ?? '';
        $nomParticipant   = $invitation->participant?->tiers?->displayName() ?? '';
    @endphp

    <div class="invitation{{ $i > 0 ? ' coupe' : '' }}">

        {{-- ======= EN-TÊTE ======= --}}
        <div class="invitation-header">
            <table class="layout">
                <tr>
                    {{-- Colonne gauche : logo + asso + titre + intro --}}
                    <td>
                        @if($logoDataUri)
                            <img src="{{ $logoDataUri }}" class="logo-img" alt="{{ $nomAsso }}">
                        @endif
                        <div class="asso-name">{{ $nomAsso }}</div>
                        <div class="campagne-titre">{{ $campagne->titre_affiche }}</div>
                        @if($campagne->operation?->nom)
                            <div class="campagne-operation" style="font-size:10px; color:#555;">{{ $campagne->operation->nom }}</div>
                        @endif
                        @if($introHtml !== '')
                            <div class="campagne-intro">{!! $introHtml !!}</div>
                        @endif
                        @if($nomParticipant)
                            <div class="participant-nom">Participant&nbsp;: <strong>{{ $nomParticipant }}</strong></div>
                        @endif
                    </td>

                    {{-- Colonne droite : QR + code court --}}
                    <td class="qr-cell">
                        <img src="{{ $qrDataUri }}" width="120" height="120" alt="QR code">
                        <div class="code-court">{{ $invitation->code_court }}</div>
                        <div class="qr-hint">Scannez pour répondre en ligne</div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- ======= CORPS : questions par groupe ======= --}}
        @foreach($groupes as $groupe)
            @php $numeroGroupe = $loop->iteration; @endphp
            <div class="groupe-papier">
                @foreach($groupe as $q)
                    {{-- Le premier élément du groupe porte le numéro du groupe. --}}
                    @php $prefixeNumero = $loop->first ? $numeroGroupe.'. ' : ''; @endphp
                    <div class="question">
                        @if($q->type === TypeQuestion::Information)
                            {{-- Intertitre : libellé en titre, aide en texte, pas de zone de réponse --}}
                            <div class="info-titre">{{ $prefixeNumero }}{{ $q->libelle }}</div>
                            @if($q->aide)
                                <div class="info-aide">{{ $q->aide }}</div>
                            @endif
                        @elseif(in_array($q->type, [TypeQuestion::Satisfaction, TypeQuestion::SatisfactionTexteLong], true))
                            {{-- Satisfaction : titre à gauche, smileys compacts sans libellés à droite sur la même ligne --}}
                            <table style="width:100%; border-collapse:collapse;"><tr>
                                <td style="vertical-align:middle;">
                                    <span class="question-libelle">{{ $prefixeNumero }}{{ $q->libelle }}@if($q->obligatoire)<span class="question-obligatoire"> *</span>@endif</span>
                                    @if($q->aide)<div class="question-aide">{{ $q->aide }}</div>@endif
                                </td>
                                <td style="vertical-align:middle; text-align:right; white-space:nowrap; width:175px;">
                                    @include('pdf.partials.champ-papier-smileys', ['question' => $q])
                                </td>
                            </tr></table>
                            @if($q->type === TypeQuestion::SatisfactionTexteLong)
                                @if($q->config['texte_obligatoire'] ?? false)
                                    <div style="font-size:8px; color:#888; margin-top:4px;">Réponse obligatoire</div>
                                @endif
                                <div class="texte-3-lignes" style="margin-top:4px;">
                                    <div style="height:12mm; border-bottom:1px solid #333;"></div>
                                    <div style="height:12mm; border-bottom:1px solid #333;"></div>
                                    <div style="height:12mm; border-bottom:1px solid #333;"></div>
                                </div>
                            @elseif(!empty($q->config['commentaire']) && !empty($q->config['commentaire_libelle']))
                                <div style="margin-top:8px; font-size:9px; color:#555;">{{ $q->config['commentaire_libelle'] }}</div>
                                <div style="border-bottom:1px solid #555; height:1.6em; margin-top:4px;"></div>
                            @endif
                        @else
                            <div class="question-libelle">
                                {{ $prefixeNumero }}{{ $q->libelle }}@if($q->obligatoire)<span class="question-obligatoire"> *</span>@endif
                            </div>
                            @if($q->aide)
                                <div class="question-aide">{{ $q->aide }}</div>
                            @endif
                            @include('pdf.partials.champ-papier', ['question' => $q])
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        {{-- ======= CONSENTEMENT (uniquement si anonyme) ======= --}}
        @if($campagne->anonymise)
            <div class="consentement">
                <span style="font-size:14px; vertical-align:middle;">&#9744;</span>
                <span style="vertical-align:middle; margin-left:6px;">J'accepte d'être recontacté(e) à propos de mes réponses.</span>
            </div>
        @endif

        {{-- ======= REMERCIEMENT ======= --}}
        <div class="remerciement">
            @if($remerciementHtml !== '')
                {!! $remerciementHtml !!}
            @else
                Merci pour votre retour !
            @endif
        </div>

    </div>
@endforeach

{{-- ======= PIED DE PAGE FIXE ======= --}}
{{--
    Le texte du pied de page (« Imprimé le … — opération — titre » à gauche,
    « page X / N » à droite) est injecté sur chaque page par
    App\Support\PdfFooterRenderer::renderQuestionnaire() via canvas->page_text().
    Ce div position:fixed fournit uniquement le filet de séparation.
--}}
<div class="pdf-footer"></div>

</body>
</html>
