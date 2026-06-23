@extends('questionnaire.repondant.layout')

@section('content')
    <div class="text-center py-3">
        @if ($dejaRepondu ?? false)
            <i class="bi bi-patch-check-fill text-primary" style="font-size: 3rem;"></i>
            <h2 class="h4 mt-3 mb-2">Vous avez déjà répondu</h2>
            <p class="text-muted">Vos réponses à ce questionnaire ont déjà été enregistrées.</p>
        @else
            <i class="bi bi-slash-circle text-secondary" style="font-size: 3rem;"></i>
            <h2 class="h4 mt-3 mb-2">Questionnaire indisponible</h2>
            <p class="text-muted">Ce questionnaire n'est plus disponible ou n'a pas encore été ouvert.</p>
        @endif
    </div>
@endsection
