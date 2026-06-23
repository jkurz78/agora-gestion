@extends('questionnaire.repondant.layout')

@section('content')
    {{-- Barre de progression --}}
    <div class="mb-4">
        <div class="d-flex justify-content-between text-muted small mb-1">
            <span>Question {{ $page }} sur {{ $total }}</span>
            <span>{{ round(($page / $total) * 100) }} %</span>
        </div>
        <div class="progress" style="height:6px">
            <div class="progress-bar" role="progressbar"
                 style="width:{{ round(($page / $total) * 100) }}%"
                 aria-valuenow="{{ $page }}" aria-valuemin="0" aria-valuemax="{{ $total }}"></div>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger py-2">{{ $errors->first('reponse') }}</div>
    @endif

    <form method="POST" action="{{ route('questionnaire.store', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="action" value="next">
        <input type="hidden" name="page" value="{{ $page }}">

        <div class="mb-4">
            <label class="form-label fw-semibold">
                {{ $question->libelle }}
                @if ($question->obligatoire)
                    <span class="text-danger" title="Obligatoire">*</span>
                @endif
            </label>

            @if ($question->aide)
                <p class="text-muted small mb-2">{{ $question->aide }}</p>
            @endif

            @php
                $fieldName = "q_{$question->id}";
                $oldValue  = old($fieldName, $answer?->{ $question->type->valueColumn() });
            @endphp

            @switch($question->type->value)
                @case('texte_court')
                    <input type="text"
                           class="form-control"
                           name="{{ $fieldName }}"
                           value="{{ $oldValue }}">
                    @break

                @case('texte_long')
                    <textarea class="form-control"
                              name="{{ $fieldName }}"
                              rows="4">{{ $oldValue }}</textarea>
                    @break

                @case('satisfaction')
                    @php
                        $labels = [
                            1 => 'Très insatisfait',
                            2 => 'Insatisfait',
                            3 => 'Neutre',
                            4 => 'Satisfait',
                            5 => 'Très satisfait',
                        ];
                    @endphp
                    <div class="d-flex gap-3 flex-wrap">
                        @foreach ($labels as $val => $lbl)
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="{{ $fieldName }}"
                                       id="{{ $fieldName }}_{{ $val }}"
                                       value="{{ $val }}"
                                       {{ (string) $oldValue === (string) $val ? 'checked' : '' }}>
                                <label class="form-check-label" for="{{ $fieldName }}_{{ $val }}">
                                    {{ $lbl }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @if ($question->config['commentaire'] ?? false)
                        <div class="mt-3">
                            <label class="form-label small text-muted" for="{{ $fieldName }}_commentaire">
                                {{ $question->config['commentaire_libelle'] ?? 'Un commentaire ? (optionnel)' }}
                            </label>
                            <textarea class="form-control" rows="2"
                                      id="{{ $fieldName }}_commentaire"
                                      name="{{ $fieldName }}_commentaire">{{ old("{$fieldName}_commentaire", $answer?->value_text) }}</textarea>
                        </div>
                    @endif
                    @break

                @case('ressenti')
                    <input type="range"
                           class="form-range"
                           name="{{ $fieldName }}"
                           min="0" max="100"
                           value="{{ $oldValue ?? 50 }}">
                    @break

                @case('case_a_cocher')
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               name="{{ $fieldName }}"
                               id="{{ $fieldName }}"
                               value="1"
                               {{ $oldValue ? 'checked' : '' }}>
                        <label class="form-check-label" for="{{ $fieldName }}">Oui</label>
                    </div>
                    @break

                @case('choix_unique')
                    @php $options = $question->options(); @endphp
                    @if (count($options) <= 5)
                        <div class="d-flex flex-column gap-2">
                            @foreach ($options as $opt)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio"
                                           name="{{ $fieldName }}"
                                           id="{{ $fieldName }}_{{ $loop->index }}"
                                           value="{{ $opt['valeur'] }}"
                                           {{ $oldValue === $opt['valeur'] ? 'checked' : '' }}>
                                    <label class="form-check-label" for="{{ $fieldName }}_{{ $loop->index }}">
                                        {{ $opt['libelle'] }}
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <select class="form-select" name="{{ $fieldName }}">
                            <option value="">— Choisir —</option>
                            @foreach ($options as $opt)
                                <option value="{{ $opt['valeur'] }}"
                                        {{ $oldValue === $opt['valeur'] ? 'selected' : '' }}>
                                    {{ $opt['libelle'] }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                    @break
            @endswitch
        </div>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">Suivant</button>
        </div>
    </form>
@endsection
