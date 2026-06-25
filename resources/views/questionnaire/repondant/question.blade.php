@extends('questionnaire.repondant.layout')

@section('content')
    {{-- Barre de progression --}}
    @if ($campagne->afficher_progression)
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
    @endif

    @if ($errors->any())
        <div class="alert alert-danger py-2">{{ $errors->first('reponse') }}</div>
    @endif

    <form method="POST" action="{{ route('questionnaire.store', ['token' => $token]) }}">
        @csrf
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

            @include('questionnaire.repondant.partials.champ', [
                'question'  => $question,
                'fieldName' => $fieldName,
                'oldValue'  => $oldValue,
                'answer'    => $answer,
            ])
        </div>

        <div class="d-flex {{ $campagne->autoriser_retour ? 'justify-content-between' : 'justify-content-end' }}">
            @if ($campagne->autoriser_retour)
            <button type="submit" name="action" value="prev" class="btn btn-outline-secondary" formnovalidate>
                ← Précédent
            </button>
            @endif
            <button type="submit" name="action" value="next" class="btn btn-primary">
                Suivant →
            </button>
        </div>
    </form>
@endsection
