{{--
    Sous-partiel partagé : groupe de smileys satisfaction (radios 1-5).

    Variables attendues :
      $question  — QuestionnaireTemplateQuestion|QuestionnaireCampaignQuestion
      $fieldName — string  ex. "q_42"
      $oldValue  — mixed   valeur précédente (peut être null)
--}}
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
    // Bouche : arc cubique M x1,y C cx1,cy cx2,cy x2,y
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
    .q-satis-group { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-start; justify-content: center; }
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
