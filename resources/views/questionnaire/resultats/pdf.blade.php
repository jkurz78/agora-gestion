<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #333; padding: 20mm 15mm; }
        h1 { font-size: 16pt; color: #3d5473; margin-bottom: 4px; }
        h2 { font-size: 11pt; color: #666; margin-bottom: 15px; font-weight: normal; }
        .meta { font-size: 8pt; color: #999; margin-bottom: 20px; }

        .compteurs { width: 100%; margin-bottom: 15px; }
        .compteurs td { width: 33%; text-align: center; padding: 8px; border: 1px solid #ddd; background: #f8f9fa; }
        .compteurs .val { font-size: 18pt; font-weight: bold; color: #3d5473; }
        .compteurs .lbl { font-size: 8pt; color: #666; }

        .question { border: 1px solid #ddd; border-radius: 4px; margin-bottom: 10px; page-break-inside: avoid; }
        .question-header { background: #3d5473; color: #fff; padding: 6px 10px; font-weight: bold; font-size: 10pt; }
        .question-body { padding: 8px 10px; }

        .moyenne { font-size: 16pt; font-weight: bold; color: #3d5473; }
        .sur { font-size: 9pt; color: #999; }
        .distrib { margin-top: 5px; }
        .distrib span { display: inline-block; background: #e9ecef; border: 1px solid #ccc; border-radius: 3px; padding: 1px 6px; margin: 1px 3px 1px 0; font-size: 8pt; }

        .barre-container { background: #e9ecef; height: 6px; border-radius: 3px; margin-top: 4px; }
        .barre { background: #198754; height: 6px; border-radius: 3px; }

        blockquote { border-left: 3px solid #3d5473; padding-left: 8px; margin: 4px 0; font-style: italic; color: #666; font-size: 9pt; }

        .contacts { border: 2px solid #3d5473; border-radius: 4px; margin-top: 15px; page-break-inside: avoid; }
        .contacts-header { background: #3d5473; color: #fff; padding: 6px 10px; font-weight: bold; }
        .contacts ul { list-style: none; padding: 5px 10px; }
        .contacts li { padding: 3px 0; border-bottom: 1px solid #eee; font-size: 9pt; }
        .contacts li:last-child { border-bottom: none; }

        .repartition-row { padding: 2px 0; border-bottom: 1px solid #f0f0f0; }
        .repartition-row:last-child { border-bottom: none; }
        .badge-count { background: #3d5473; color: #fff; border-radius: 10px; padding: 1px 8px; font-size: 8pt; float: right; }
    </style>
</head>
<body>
    <h1>{{ $titre }}</h1>
    <h2>{{ $sousTitre }}</h2>
    <div class="meta">{{ $association->nom }} &mdash; {{ $date }}</div>

    <table class="compteurs">
        <tr>
            <td>
                <div class="val">{{ $resultats['nb_invitations'] }}</div>
                <div class="lbl">Invitations</div>
            </td>
            <td>
                <div class="val">{{ $resultats['nb_soumissions'] }}</div>
                <div class="lbl">Réponses</div>
            </td>
            <td>
                <div class="val">{{ $resultats['taux'] }}%</div>
                <div class="lbl">Taux de réponse</div>
            </td>
        </tr>
    </table>

    @foreach ($resultats['questions'] as $q)
        <div class="question">
            <div class="question-header">{{ $q['libelle'] }}</div>
            <div class="question-body">
                @if ($q['type'] === \App\Enums\TypeQuestion::Satisfaction || $q['type'] === \App\Enums\TypeQuestion::Ressenti || $q['type'] === \App\Enums\TypeQuestion::SatisfactionTexteLong)
                    @if ($q['moyenne'] !== null)
                        <span class="moyenne">{{ number_format($q['moyenne'], 1, ',', '') }}</span>
                        <span class="sur">/ {{ $q['type'] === \App\Enums\TypeQuestion::Ressenti ? '100' : '5' }}</span>
                        <span class="sur">({{ $q['n'] }} rép.)</span>
                        @if (!empty($q['distribution']))
                            <div class="distrib">
                                @foreach ($q['distribution'] as $note => $nb)
                                    <span>{{ $note }} &rarr; {{ $nb }}&times;</span>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <em style="color:#999;">Aucune réponse.</em>
                    @endif
                    @if (!empty($q['verbatims'] ?? []))
                        <div style="margin-top:6px;">
                            <strong style="font-size:8pt;">Commentaires :</strong>
                            @foreach ($q['verbatims'] as $verbatim)
                                <blockquote>&laquo; {{ $verbatim }} &raquo;</blockquote>
                            @endforeach
                        </div>
                    @endif

                @elseif ($q['type'] === \App\Enums\TypeQuestion::CaseACocher)
                    <strong>Oui :</strong> {{ $q['oui'] }} &nbsp;
                    <strong>Non :</strong> {{ $q['non'] }} &nbsp;
                    <span class="sur">({{ $q['n'] }} rép.)</span>
                    @if ($q['n'] > 0)
                        <div class="barre-container">
                            <div class="barre" style="width:{{ round($q['oui'] / $q['n'] * 100) }}%"></div>
                        </div>
                    @endif

                @elseif ($q['type'] === \App\Enums\TypeQuestion::ChoixUnique)
                    @foreach ($q['repartition'] as $item)
                        <div class="repartition-row">
                            {{ $item['libelle'] }}
                            <span class="badge-count">{{ $item['count'] }}</span>
                        </div>
                    @endforeach
                    <div class="sur" style="margin-top:3px;">{{ $q['n'] }} rép.</div>

                @elseif ($q['type'] === \App\Enums\TypeQuestion::TexteCourt || $q['type'] === \App\Enums\TypeQuestion::TexteLong)
                    @forelse ($q['verbatims'] as $verbatim)
                        <blockquote>&laquo; {{ $verbatim }} &raquo;</blockquote>
                    @empty
                        <em style="color:#999;">Aucune réponse texte.</em>
                    @endforelse
                @endif
            </div>
        </div>
    @endforeach

    @if ($contacts->isNotEmpty())
        <div class="contacts">
            <div class="contacts-header">Souhaitent être recontactés ({{ $contacts->count() }})</div>
            <ul>
                @foreach ($contacts as $submission)
                    <li>{{ $submission->invitation?->participant?->tiers?->displayName() ?? '—' }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</body>
</html>
