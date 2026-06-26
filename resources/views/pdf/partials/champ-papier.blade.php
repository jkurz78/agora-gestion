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
