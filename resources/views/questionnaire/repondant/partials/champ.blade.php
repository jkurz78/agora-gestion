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
            $satisLabels = [
                1 => 'Très insatisfait',
                2 => 'Insatisfait',
                3 => 'Neutre',
                4 => 'Satisfait',
                5 => 'Très satisfait',
            ];
            // Couleurs rouge → orange → jaune → vert clair → vert
            $satisColors = [
                1 => '#e53935',
                2 => '#fb8c00',
                3 => '#fdd835',
                4 => '#7cb342',
                5 => '#43a047',
            ];
            // Bouche : cy_start, cy_end pour l'arc (frown=bas, flat=milieu, smile=haut)
            // Arc cubique : M x1,y C cx1,cy cx2,cy x2,y
            // 1=froncement, 3=plat, 5=sourire
            $satisMouth = [
                1 => 'M 38,68 C 42,60 58,60 62,68',   // bouche triste
                2 => 'M 38,66 C 42,62 58,62 62,66',   // légèrement triste
                3 => 'M 38,64 C 42,64 58,64 62,64',   // neutre (ligne plate)
                4 => 'M 38,64 C 42,68 58,68 62,64',   // légèrement souriant
                5 => 'M 38,62 C 42,70 58,70 62,62',   // grand sourire
            ];
            $satisFieldId = preg_replace('/[^a-z0-9_-]/i', '_', $fieldName);
        @endphp

        <style>
            .q-satis-group { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-start; }
            .q-satis-item { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; }
            .q-satis-item input[type="radio"] {
                position: absolute;
                width: 1px; height: 1px;
                opacity: 0;
                pointer-events: none;
            }
            .q-satis-label {
                cursor: pointer;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.35rem;
            }
            .q-satis-svg {
                width: 56px; height: 56px;
                opacity: 0.45;
                transition: transform 0.15s ease, opacity 0.15s ease;
                border-radius: 50%;
            }
            .q-satis-label:hover .q-satis-svg,
            .q-satis-label:focus-within .q-satis-svg {
                opacity: 0.75;
                transform: scale(1.1);
            }
            .q-satis-item input[type="radio"]:checked + .q-satis-label .q-satis-svg {
                opacity: 1;
                transform: scale(1.25);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
            .q-satis-caption {
                font-size: 0.7rem;
                color: #6c757d;
                text-align: center;
                max-width: 64px;
                line-height: 1.2;
            }
            .q-satis-item input[type="radio"]:checked ~ .q-satis-caption {
                font-weight: 600;
                color: #343a40;
            }
        </style>

        <div class="q-satis-group" role="radiogroup">
            @foreach ($satisLabels as $val => $lbl)
                @php $color = $satisColors[$val]; $mouth = $satisMouth[$val]; @endphp
                <div class="q-satis-item">
                    <input type="radio"
                           name="{{ $fieldName }}"
                           id="{{ $satisFieldId }}_{{ $val }}"
                           value="{{ $val }}"
                           {{ (string) $oldValue === (string) $val ? 'checked' : '' }}>
                    <label class="q-satis-label" for="{{ $satisFieldId }}_{{ $val }}" title="{{ $lbl }}">
                        <svg class="q-satis-svg"
                             viewBox="0 0 100 100"
                             xmlns="http://www.w3.org/2000/svg"
                             aria-hidden="true"
                             focusable="false">
                            {{-- Cercle du visage --}}
                            <circle cx="50" cy="50" r="46" fill="{{ $color }}" stroke="{{ $color }}" stroke-width="2"/>
                            {{-- Œil gauche --}}
                            <circle cx="36" cy="40" r="5" fill="white"/>
                            {{-- Œil droit --}}
                            <circle cx="64" cy="40" r="5" fill="white"/>
                            {{-- Bouche --}}
                            <path d="{{ $mouth }}" fill="none" stroke="white" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                    </label>
                    <span class="q-satis-caption">{{ $lbl }}</span>
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
