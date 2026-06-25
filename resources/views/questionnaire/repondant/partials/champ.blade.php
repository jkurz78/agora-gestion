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
                          name="{{ $fieldName }}_commentaire">{{ old("{$fieldName}_commentaire", $oldCommentaire ?? $answer?->value_text) }}</textarea>
            </div>
        @endif
        @break

    @case('ressenti')
        @php
            $ressFieldId = preg_replace('/[^a-z0-9_-]/i', '_', $fieldName);
            $ressLabelG  = $question->config['label_gauche'] ?? null;
            $ressLabelD  = $question->config['label_droite'] ?? null;
            $ressHasVal  = ($oldValue !== null && $oldValue !== '');
            $ressInitPct = $ressHasVal ? (int) $oldValue : null;
        @endphp

        <style>
            .q-ress-wrap_{{ $ressFieldId }} {
                user-select: none;
            }
            .q-ress-track-row_{{ $ressFieldId }} {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }
            .q-ress-end_{{ $ressFieldId }} {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.2rem;
                flex-shrink: 0;
            }
            .q-ress-end-label_{{ $ressFieldId }} {
                font-size: 0.75rem;
                color: #6c757d;
                text-align: center;
                max-width: 72px;
                line-height: 1.2;
            }
            .q-ress-track-outer_{{ $ressFieldId }} {
                flex: 1;
                position: relative;
                height: 40px;
                cursor: pointer;
            }
            .q-ress-track_{{ $ressFieldId }} {
                position: absolute;
                top: 50%;
                left: 0;
                right: 0;
                height: 6px;
                transform: translateY(-50%);
                background: #dee2e6;
                border-radius: 3px;
            }
            .q-ress-marker_{{ $ressFieldId }} {
                position: absolute;
                top: 50%;
                transform: translate(-50%, -50%);
                width: 4px;
                height: 28px;
                background: #3d5473;
                border-radius: 2px;
                display: none;
            }
            .q-ress-prompt_{{ $ressFieldId }} {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                white-space: nowrap;
                font-size: 0.8rem;
                color: #6c757d;
                pointer-events: none;
            }
        </style>

        <div class="q-ress-wrap_{{ $ressFieldId }}">
            <div class="q-ress-track-row_{{ $ressFieldId }}">
                {{-- Extrémité gauche --}}
                <div class="q-ress-end_{{ $ressFieldId }}">
                    @if ($ressLabelG)
                        <span class="q-ress-end-label_{{ $ressFieldId }}">{{ $ressLabelG }}</span>
                    @else
                        {{-- Smiley rouge fâché --}}
                        <svg width="36" height="36" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="50" cy="50" r="46" fill="#e53935"/>
                            <circle cx="36" cy="40" r="5" fill="white"/>
                            <circle cx="64" cy="40" r="5" fill="white"/>
                            <path d="M 38,68 C 42,60 58,60 62,68" fill="none" stroke="white" stroke-width="4" stroke-linecap="round"/>
                            <line x1="30" y1="28" x2="42" y2="34" stroke="white" stroke-width="3" stroke-linecap="round"/>
                            <line x1="70" y1="28" x2="58" y2="34" stroke="white" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    @endif
                </div>

                {{-- Piste interactive --}}
                <div class="q-ress-track-outer_{{ $ressFieldId }}"
                     id="q-ress-outer_{{ $ressFieldId }}"
                     role="slider"
                     aria-valuemin="0"
                     aria-valuemax="100"
                     aria-valuenow="{{ $ressHasVal ? $ressInitPct : '' }}"
                     aria-label="{{ $question->libelle }}"
                     tabindex="0">
                    <div class="q-ress-track_{{ $ressFieldId }}"></div>
                    <div class="q-ress-marker_{{ $ressFieldId }}"
                         id="q-ress-marker_{{ $ressFieldId }}"
                         style="{{ $ressHasVal ? 'display:block; left:'.($ressInitPct).'%' : '' }}"></div>
                    <span class="q-ress-prompt_{{ $ressFieldId }}"
                          id="q-ress-prompt_{{ $ressFieldId }}"
                          style="{{ $ressHasVal ? 'display:none' : '' }}">
                        Placez le curseur selon votre ressenti
                    </span>
                </div>

                {{-- Extrémité droite --}}
                <div class="q-ress-end_{{ $ressFieldId }}">
                    @if ($ressLabelD)
                        <span class="q-ress-end-label_{{ $ressFieldId }}">{{ $ressLabelD }}</span>
                    @else
                        {{-- Smiley vert souriant --}}
                        <svg width="36" height="36" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="50" cy="50" r="46" fill="#43a047"/>
                            <circle cx="36" cy="40" r="5" fill="white"/>
                            <circle cx="64" cy="40" r="5" fill="white"/>
                            <path d="M 38,62 C 42,70 58,70 62,62" fill="none" stroke="white" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                    @endif
                </div>
            </div>
        </div>

        {{-- Champ caché — vide tant que non positionné --}}
        <input type="hidden"
               name="{{ $fieldName }}"
               id="q-ress-hidden_{{ $ressFieldId }}"
               value="{{ $ressHasVal ? $ressInitPct : '' }}">

        <script>
        (function () {
            var outer  = document.getElementById('q-ress-outer_{{ $ressFieldId }}');
            var marker = document.getElementById('q-ress-marker_{{ $ressFieldId }}');
            var prompt = document.getElementById('q-ress-prompt_{{ $ressFieldId }}');
            var hidden = document.getElementById('q-ress-hidden_{{ $ressFieldId }}');

            if (!outer) return;

            function pctFromEvent(e) {
                var rect = outer.getBoundingClientRect();
                var clientX = e.touches ? e.touches[0].clientX : e.clientX;
                var raw = (clientX - rect.left) / rect.width;
                return Math.max(0, Math.min(1, raw));
            }

            function applyPct(pct) {
                var val = Math.round(pct * 100);
                marker.style.left = (pct * 100) + '%';
                marker.style.display = 'block';
                if (prompt) prompt.style.display = 'none';
                hidden.value = val;
                outer.setAttribute('aria-valuenow', val);
            }

            var dragging = false;

            outer.addEventListener('mousedown', function (e) {
                dragging = true;
                applyPct(pctFromEvent(e));
                e.preventDefault();
            });
            document.addEventListener('mousemove', function (e) {
                if (dragging) applyPct(pctFromEvent(e));
            });
            document.addEventListener('mouseup', function () {
                dragging = false;
            });

            outer.addEventListener('touchstart', function (e) {
                applyPct(pctFromEvent(e));
                e.preventDefault();
            }, { passive: false });
            outer.addEventListener('touchmove', function (e) {
                applyPct(pctFromEvent(e));
                e.preventDefault();
            }, { passive: false });

            // Accessibilité clavier : flèches gauche/droite (pas de % affiché — aveugle)
            outer.addEventListener('keydown', function (e) {
                var cur = hidden.value !== '' ? parseInt(hidden.value, 10) : 50;
                if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                    applyPct(Math.max(0, cur - 1) / 100);
                    e.preventDefault();
                } else if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
                    applyPct(Math.min(100, cur + 1) / 100);
                    e.preventDefault();
                } else if (e.key === 'Home') {
                    applyPct(0);
                    e.preventDefault();
                } else if (e.key === 'End') {
                    applyPct(1);
                    e.preventDefault();
                }
            });
        })();
        </script>
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
