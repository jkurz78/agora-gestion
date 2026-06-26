{{--
    Partial papier : bloc compact de 5 smileys SANS libellés texte, à aligner à droite.
    Variables attendues :
      $question — QuestionnaireCampaignQuestion
    DomPDF-safe : table + img data-URI, pas de flexbox/grid.
--}}
@php
    // Mêmes visages qu'à l'écran : couleur + chemin de bouche (Bézier).
    $satisNiveaux = [
        1 => ['#e53935', 'M 38,68 C 42,60 58,60 62,68'],
        2 => ['#fb8c00', 'M 38,66 C 42,62 58,62 62,66'],
        3 => ['#fdd835', 'M 38,64 C 42,64 58,64 62,64'],
        4 => ['#7cb342', 'M 38,64 C 42,68 58,68 62,64'],
        5 => ['#43a047', 'M 38,62 C 42,70 58,70 62,62'],
    ];
@endphp
<table class="smileys-compact" style="border-collapse:collapse;">
    <tr>
        @foreach($satisNiveaux as $val => [$couleur, $mouthPath])
            @php
                $svgSmiley = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
                    .'<circle cx="50" cy="50" r="46" fill="'.$couleur.'"/>'
                    .'<circle cx="36" cy="40" r="5" fill="#ffffff"/>'
                    .'<circle cx="64" cy="40" r="5" fill="#ffffff"/>'
                    .'<path d="'.$mouthPath.'" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>'
                    .'</svg>';
                $svgSrc = 'data:image/svg+xml;base64,'.base64_encode($svgSmiley);
            @endphp
            <td style="width:28px; text-align:center; vertical-align:top; padding:0 3px;">
                <img src="{{ $svgSrc }}" width="26" height="26" alt="">
                <div style="width:16px; height:16px; border:1.5px solid #555; margin:3px auto 0; background:#fff;"></div>
            </td>
        @endforeach
    </tr>
</table>
