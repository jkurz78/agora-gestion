@extends('questionnaire.repondant.layout')

@section('content')
    <div class="text-center py-3">
        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
        <h2 class="h4 mt-3 mb-2">Merci pour vos réponses !</h2>
        @if ($campagne->remerciement)
            <p class="text-muted">{{ $campagne->remerciement }}</p>
        @else
            <p class="text-muted">Vos réponses ont bien été enregistrées.</p>
        @endif
    </div>
@endsection
