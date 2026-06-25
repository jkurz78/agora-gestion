{{--
    Partial papier : zone de réponse manuscrite pour une question du questionnaire.
    Variables attendues :
      $question — QuestionnaireCampaignQuestion
    DomPDF-safe : pas de flexbox/grid ; tables et inline-block uniquement.
--}}
@php use App\Enums\TypeQuestion; @endphp
@switch($question->type->value)

    @case('texte_court')
        <div style="border-bottom:1px solid #333; height:1.6em; margin-top:6px;"></div>
        @break

    @case('texte_long')
        <div style="border:1px solid #555; height:5.5em; margin-top:6px; background:#fff;"></div>
        @break

    @case('satisfaction')
        @php
            // Mêmes visages qu'à l'écran : couleur du visage + courbe de bouche (Bézier).
            // Niveau => [libellé, couleur, chemin de bouche]
            $satisNiveaux = [
                1 => ['Très insatisfait', '#e53935', 'M 38,68 C 42,60 58,60 62,68'],
                2 => ['Insatisfait',      '#fb8c00', 'M 38,66 C 42,62 58,62 62,66'],
                3 => ['Neutre',           '#fdd835', 'M 38,64 C 42,64 58,64 62,64'],
                4 => ['Satisfait',        '#7cb342', 'M 38,64 C 42,68 58,68 62,64'],
                5 => ['Très satisfait',   '#43a047', 'M 38,62 C 42,70 58,70 62,62'],
            ];
        @endphp
        <table style="width:100%; border-collapse:collapse; margin-top:8px;">
            <tr>
                @foreach($satisNiveaux as $val => $niveau)
                    @php
                        // Même visage qu'à l'écran, embarqué en image SVG (DomPDF ne rend pas le SVG inline).
                        $svgSmiley = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
                            .'<circle cx="50" cy="50" r="46" fill="'.$niveau[1].'"/>'
                            .'<circle cx="36" cy="40" r="5" fill="#ffffff"/>'
                            .'<circle cx="64" cy="40" r="5" fill="#ffffff"/>'
                            .'<path d="'.$niveau[2].'" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>'
                            .'</svg>';
                        $svgSrc = 'data:image/svg+xml;base64,'.base64_encode($svgSmiley);
                    @endphp
                    <td style="width:20%; text-align:center; vertical-align:top; padding:0 4px;">
                        <img src="{{ $svgSrc }}" width="28" height="28" alt="">
                        <div style="font-size:9px; color:#444; margin:4px 0 5px; line-height:1.2;">{{ $niveau[0] }}</div>
                        <div style="width:18px; height:18px; border:1.5px solid #555; margin:0 auto; background:#fff;"></div>
                    </td>
                @endforeach
            </tr>
        </table>
        @if (!empty($question->config['commentaire']) && !empty($question->config['commentaire_libelle']))
            <div style="margin-top:8px; font-size:9px; color:#555;">{{ $question->config['commentaire_libelle'] }}</div>
            <div style="border-bottom:1px solid #555; height:1.6em; margin-top:4px;"></div>
        @endif
        @break

    @case('ressenti')
        @php
            $labelG = $question->config['label_gauche'] ?? 'Pas du tout';
            $labelD = $question->config['label_droite'] ?? 'Tout à fait';
        @endphp
        <table style="width:100%; border-collapse:collapse; margin-top:10px;">
            <tr>
                <td style="vertical-align:middle; font-size:9px; color:#444; text-align:right; white-space:nowrap; padding-right:8px;">{{ $labelG }}</td>
                <td style="vertical-align:middle; width:72%;">
                    {{-- Ligne nue : on y marque un trait vertical --}}
                    <div style="border-bottom:1.5px solid #333; font-size:0; line-height:0;">&nbsp;</div>
                </td>
                <td style="vertical-align:middle; font-size:9px; color:#444; text-align:left; white-space:nowrap; padding-left:8px;">{{ $labelD }}</td>
            </tr>
        </table>
        <div style="font-size:8px; color:#999; text-align:center; margin-top:3px;">Marquez d'un trait vertical</div>
        @break

    @case('case_a_cocher')
        <div style="margin-top:6px;">
            <span style="font-size:16px; line-height:1;">&#9744;</span>
            <span style="font-size:10px; color:#333; margin-left:6px;">{{ $question->libelle }}</span>
        </div>
        @break

    @case('choix_unique')
        @php $options = $question->options(); @endphp
        <div style="margin-top:6px;">
            @foreach($options as $opt)
                <div style="margin-bottom:4px;">
                    <span style="font-size:16px; line-height:1; vertical-align:middle;">&#9744;</span>
                    <span style="font-size:10px; color:#333; margin-left:6px; vertical-align:middle;">{{ $opt['libelle'] }}</span>
                </div>
            @endforeach
        </div>
        @break

@endswitch
