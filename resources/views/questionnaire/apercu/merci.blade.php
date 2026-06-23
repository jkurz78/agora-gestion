@extends('questionnaire.repondant.layout')

@section('content')
    <div class="alert alert-warning py-2 mb-4">
        <strong>Mode aperçu</strong> — aucune réponse n'est enregistrée.
    </div>

    <div class="text-center py-3">
        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
        <h2 class="h4 mt-3 mb-2">{{ $titre }}</h2>
        @if (!empty($remerciementHtml))
            <div class="text-muted">{!! $remerciementHtml !!}</div>
        @else
            <p class="text-muted">Vos réponses ont bien été enregistrées.</p>
        @endif
    </div>

    <div class="text-center mt-4">
        <a href="{{ $retour }}" class="btn btn-secondary">Retour</a>
    </div>
@endsection
