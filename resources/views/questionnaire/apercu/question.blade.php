@extends('questionnaire.repondant.layout')

@section('content')
    <div class="alert alert-warning py-2 mb-4">
        <strong>Mode aperçu</strong> — aucune réponse n'est enregistrée.
    </div>

    {{-- Barre de progression --}}
    @if ($afficher_progression ?? true)
    <div class="mb-4">
        <div class="d-flex justify-content-between text-muted small mb-1">
            <span>Page {{ $page }} sur {{ $total }}</span>
            <span>{{ round(($page / $total) * 100) }} %</span>
        </div>
        <div class="progress" style="height:6px">
            <div class="progress-bar" role="progressbar"
                 style="width:{{ round(($page / $total) * 100) }}%"
                 aria-valuenow="{{ $page }}" aria-valuemin="0" aria-valuemax="{{ $total }}"></div>
        </div>
    </div>
    @endif

    <form method="POST" action="{{ $postUrl }}">
        @csrf
        <input type="hidden" name="page" value="{{ $page }}">

        @foreach ($ecran as $question)
            @if ($question->type === \App\Enums\TypeQuestion::Information)
                @include('questionnaire.repondant.partials.champ-information', [
                    'question' => $question,
                ])
            @else
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
                        $oldValue  = old($fieldName, $oldValues[$question->id] ?? null);
                    @endphp

                    @include('questionnaire.repondant.partials.champ', [
                        'question'  => $question,
                        'fieldName' => $fieldName,
                        'oldValue'  => $oldValue,
                        'answer'    => null,
                    ])

                    @error("q_{$question->id}")
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>
            @endif
        @endforeach

        <div class="d-flex {{ ($autoriser_retour ?? true) ? 'justify-content-between' : 'justify-content-end' }}">
            @if ($autoriser_retour ?? true)
            <button type="submit" name="action" value="prev" class="btn btn-outline-secondary" formnovalidate>← Précédent</button>
            @endif
            <button type="submit" name="action" value="next" class="btn btn-primary">Suivant →</button>
        </div>
    </form>
@endsection
