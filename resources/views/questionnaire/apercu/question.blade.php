@extends('questionnaire.repondant.layout')

@section('content')
    <div class="alert alert-warning py-2 mb-4">
        <strong>Mode aperçu</strong> — aucune réponse n'est enregistrée.
    </div>

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
            $oldValue  = null;
        @endphp

        @include('questionnaire.repondant.partials.champ', [
            'question'  => $question,
            'fieldName' => $fieldName,
            'oldValue'  => null,
            'answer'    => null,
        ])
    </div>

    <div class="d-flex justify-content-between">
        @php
            $precedent = $page > 1 ? $base . '?page=' . ($page - 1) : $base . '?page=0';
            $suivant = $page < $total ? $base . '?page=' . ($page + 1) : $base . '?page=consentement';
        @endphp
        <a href="{{ $precedent }}" class="btn btn-outline-secondary">← Précédent</a>
        <a href="{{ $suivant }}" class="btn btn-primary">Suivant →</a>
    </div>
@endsection
