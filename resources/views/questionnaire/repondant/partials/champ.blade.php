{{--
    Partial partagé : rendu du champ pour une question du questionnaire.

    Variables attendues :
      $question  — QuestionnaireTemplateQuestion|QuestionnaireCampaignQuestion
      $fieldName — string  ex. "q_42"
      $oldValue  — mixed   valeur précédente (old() ou answer, peut être null)
      $answer    — ?QuestionnaireAnswer  réponse existante (null en mode aperçu)
--}}
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
