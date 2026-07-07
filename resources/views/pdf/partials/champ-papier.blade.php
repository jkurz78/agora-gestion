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
        <div style="margin-top:6px;">
            <div style="height:12mm; border-bottom:1px solid #333;"></div>
            <div style="height:12mm; border-bottom:1px solid #333;"></div>
            <div style="height:12mm; border-bottom:1px solid #333;"></div>
            <div style="height:12mm; border-bottom:1px solid #333;"></div>
        </div>
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
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">Cochez une seule réponse</div>
            @foreach($options as $opt)
                <div style="margin-bottom:4px;">
                    <span style="font-size:16px; line-height:1; vertical-align:middle;">&#9675;</span>
                    <span style="font-size:10px; color:#333; margin-left:6px; vertical-align:middle;">{{ $opt['libelle'] }}</span>
                </div>
            @endforeach
        </div>
        @break

    @case('date')
        <div style="margin-top:8px;">
            <div style="font-size:8px; color:#888; margin-bottom:4px; font-style:italic;">Saisissez la date : JJ / MM / AAAA</div>
            <table style="border-collapse:collapse;">
                <tr>
                    @for ($i = 0; $i < 2; $i++)
                        <td style="border:1px solid #333; width:20px; height:24px; text-align:center;"></td>
                    @endfor
                    <td style="padding:0 4px; font-size:14px; font-weight:bold;">/</td>
                    @for ($i = 0; $i < 2; $i++)
                        <td style="border:1px solid #333; width:20px; height:24px; text-align:center;"></td>
                    @endfor
                    <td style="padding:0 4px; font-size:14px; font-weight:bold;">/</td>
                    @for ($i = 0; $i < 4; $i++)
                        <td style="border:1px solid #333; width:20px; height:24px; text-align:center;"></td>
                    @endfor
                </tr>
                <tr>
                    <td colspan="2" style="font-size:7px; color:#999; text-align:center;">J&nbsp;&nbsp;J</td>
                    <td></td>
                    <td colspan="2" style="font-size:7px; color:#999; text-align:center;">M&nbsp;&nbsp;M</td>
                    <td></td>
                    <td colspan="4" style="font-size:7px; color:#999; text-align:center;">A&nbsp;&nbsp;A&nbsp;&nbsp;A&nbsp;&nbsp;A</td>
                </tr>
            </table>
        </div>
        @break

    @case('choix_multiple')
        @php $options = $question->options(); @endphp
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">Cochez une ou plusieurs réponses</div>
            @foreach($options as $opt)
                <div style="margin-bottom:4px;">
                    <span style="font-size:16px; line-height:1; vertical-align:middle;">&#9744;</span>
                    <span style="font-size:10px; color:#333; margin-left:6px; vertical-align:middle;">{{ $opt['libelle'] }}</span>
                </div>
            @endforeach
        </div>
        @break

    @case('nombre')
        @php
            $instr = 'Entrez un nombre';
            if (isset($question->config['min'], $question->config['max'])) {
                $instr .= ' entre ' . $question->config['min'] . ' et ' . $question->config['max'];
            } elseif (isset($question->config['min'])) {
                $instr .= ' (min : ' . $question->config['min'] . ')';
            } elseif (isset($question->config['max'])) {
                $instr .= ' (max : ' . $question->config['max'] . ')';
            }
        @endphp
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">{{ $instr }}</div>
            <div style="border-bottom:1px solid #333; height:1.6em;"></div>
        </div>
        @break

    @case('email')
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">Adresse email</div>
            <div style="border-bottom:1px solid #333; height:1.6em;"></div>
        </div>
        @break

    @case('selection_numerique')
        @php
            $sMin = (int) ($question->config['min'] ?? 0);
            $sMax = (int) ($question->config['max'] ?? 100);
        @endphp
        <div style="margin-top:6px;">
            <div style="font-size:8px; color:#888; margin-bottom:3px; font-style:italic;">Entrez un nombre entre {{ $sMin }} et {{ $sMax }}</div>
            <div style="border-bottom:1px solid #333; height:1.6em;"></div>
        </div>
        @break

@endswitch
