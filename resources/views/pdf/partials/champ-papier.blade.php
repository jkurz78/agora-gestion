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
            $satisLabels = [
                1 => 'Très insatisfait',
                2 => 'Insatisfait',
                3 => 'Neutre',
                4 => 'Satisfait',
                5 => 'Très satisfait',
            ];
        @endphp
        <table style="width:100%; border-collapse:collapse; margin-top:8px;">
            <tr>
                @foreach($satisLabels as $val => $lbl)
                    <td style="width:20%; text-align:center; vertical-align:top; padding:0 4px;">
                        <div style="font-size:9px; color:#444; margin-bottom:4px; line-height:1.2;">{{ $lbl }}</div>
                        <div style="width:20px; height:20px; border:2px solid #333; margin:0 auto; background:#fff; font-size:14px; line-height:18px; text-align:center;">&#9744;</div>
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
        <table style="width:100%; border-collapse:collapse; margin-top:8px;">
            <tr>
                <td style="width:18%; vertical-align:middle; font-size:9px; color:#444; text-align:left;">{{ $labelG }}</td>
                <td style="vertical-align:middle; padding:0 8px;">
                    <div style="border-bottom:2px solid #333; height:1px; margin-top:16px; position:relative;">
                        {{-- Tick marks every 10% --}}
                        <table style="width:100%; border-collapse:collapse; margin-top:0;">
                            <tr>
                                @for($t = 0; $t <= 10; $t++)
                                    <td style="width:{{ 100/11 }}%; text-align:center; vertical-align:top; padding:0;">
                                        <div style="border-left:1px solid #777; height:6px; margin:0 auto; width:1px;"></div>
                                    </td>
                                @endfor
                            </tr>
                        </table>
                    </div>
                    <div style="font-size:8px; color:#888; text-align:center; margin-top:2px;">Marquez d'une croix</div>
                </td>
                <td style="width:18%; vertical-align:middle; font-size:9px; color:#444; text-align:right;">{{ $labelD }}</td>
            </tr>
        </table>
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
