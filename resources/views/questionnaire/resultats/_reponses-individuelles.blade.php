@php
    use App\Enums\TypeQuestion;
@endphp

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center"
         style="--bs-card-cap-bg:#3d5473; --bs-card-cap-color:#fff;">
        <span class="fw-semibold">
            <i class="bi bi-person-lines-fill me-1"></i>Réponses individuelles ({{ $submissions->count() }})
        </span>
    </div>
    <div class="card-body p-0">
        @if ($submissions->isEmpty())
            <p class="text-muted p-3 mb-0">Aucune réponse soumise.</p>
        @else
            <div class="accordion accordion-flush" id="accordionReponses">
                @foreach ($submissions as $sub)
                    @php
                        $collapseId = 'reponse-' . $sub->id;
                        $answersKeyed = $sub->answers->keyBy('campaign_question_id');
                        $nom = $sub->nomRepondant();
                        $sourceLabel = match($sub->source) {
                            'papier' => 'Papier (scan)',
                            'web' => 'En ligne',
                            default => $sub->source ?? 'En ligne',
                        };

                        $scan = $scanParSubmission[(int) $sub->id] ?? null;
                    @endphp
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading-{{ $collapseId }}">
                            <button class="accordion-button collapsed py-2" type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#{{ $collapseId }}"
                                    aria-expanded="false"
                                    aria-controls="{{ $collapseId }}">
                                <div class="d-flex justify-content-between align-items-center w-100 me-2">
                                    <span>
                                        @if ($campagne->anonymise && !$sub->accepte_contact)
                                            <i class="bi bi-shield-check text-info me-1"></i>Anonyme
                                        @else
                                            <i class="bi bi-person me-1"></i>{{ $nom }}
                                        @endif
                                    </span>
                                    <span class="d-flex gap-2 align-items-center">
                                        @if ($scan)
                                            <a href="{{ route('questionnaires.campagnes.scans.image', $scan) }}"
                                               target="_blank" class="badge bg-secondary text-decoration-none"
                                               style="position: relative; z-index: 2;"
                                               onclick="event.stopPropagation(); event.preventDefault(); window.open(this.href, '_blank');">
                                                <i class="bi bi-file-earmark-image me-1"></i>Voir le scan
                                            </a>
                                        @endif
                                        <span class="badge bg-light text-dark border">{{ $sourceLabel }}</span>
                                        <span class="text-muted small">{{ $sub->submitted_at?->format('d/m/Y H:i') }}</span>
                                    </span>
                                </div>
                            </button>
                        </h2>
                        <div id="{{ $collapseId }}" class="accordion-collapse collapse"
                             aria-labelledby="heading-{{ $collapseId }}"
                             data-bs-parent="#accordionReponses">
                            <div class="accordion-body p-0">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        @foreach ($questions as $q)
                                            @if ($q->type === TypeQuestion::Information)
                                                @continue
                                            @endif
                                            @php
                                                $answer = $answersKeyed->get((int) $q->id);
                                            @endphp
                                            <tr>
                                                <td class="text-muted small" style="width: 45%; vertical-align: top;">
                                                    {{ $q->libelle }}
                                                </td>
                                                <td>
                                                    @if ($answer === null)
                                                        <span class="text-muted fst-italic">—</span>
                                                    @else
                                                        @switch($q->type)
                                                            @case(TypeQuestion::Satisfaction)
                                                            @case(TypeQuestion::SatisfactionTexteLong)
                                                                @php
                                                                    $note = $answer->value_integer;
                                                                    $satisLabels = [1 => 'Très insatisfait', 2 => 'Insatisfait', 3 => 'Neutre', 4 => 'Satisfait', 5 => 'Très satisfait'];
                                                                    $satisColors = [1 => '#e53935', 2 => '#fb8c00', 3 => '#fdd835', 4 => '#7cb342', 5 => '#43a047'];
                                                                @endphp
                                                                @if ($note !== null)
                                                                    <span class="badge" style="background-color: {{ $satisColors[$note] ?? '#999' }}">
                                                                        {{ $note }}/5
                                                                    </span>
                                                                    <span class="small text-muted ms-1">{{ $satisLabels[$note] ?? '' }}</span>
                                                                @endif
                                                                @if ($answer->value_text)
                                                                    <div class="small fst-italic text-muted mt-1">&laquo; {{ $answer->value_text }} &raquo;</div>
                                                                @endif
                                                                @break

                                                            @case(TypeQuestion::Ressenti)
                                                                @if ($answer->value_integer !== null)
                                                                    <div class="d-flex align-items-center gap-2">
                                                                        <div class="progress flex-grow-1" style="height: 8px; max-width: 200px;">
                                                                            <div class="progress-bar" style="width: {{ $answer->value_integer }}%; background-color: #3d5473;"></div>
                                                                        </div>
                                                                        <span class="small fw-semibold">{{ $answer->value_integer }}/100</span>
                                                                    </div>
                                                                @endif
                                                                @break

                                                            @case(TypeQuestion::CaseACocher)
                                                                @if ($answer->value_boolean)
                                                                    <i class="bi bi-check-circle-fill text-success"></i> Oui
                                                                @else
                                                                    <i class="bi bi-x-circle text-danger"></i> Non
                                                                @endif
                                                                @break

                                                            @case(TypeQuestion::ChoixUnique)
                                                                @php
                                                                    $libelle = $q->libelleOption($answer->value_option ?? '');
                                                                @endphp
                                                                {{ $libelle ?? $answer->value_option ?? '—' }}
                                                                @break

                                                            @case(TypeQuestion::TexteCourt)
                                                            @case(TypeQuestion::TexteLong)
                                                                {{ $answer->value_text ?? '—' }}
                                                                @break
                                                        @endswitch
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
