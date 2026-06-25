@extends('questionnaire.repondant.layout')

@section('content')
    <div class="alert alert-warning py-2 mb-4">
        <strong>Mode aperçu</strong> — aucune réponse n'est enregistrée.
    </div>

    <h1 class="h4 mb-3">{{ $titre }}</h1>

    @if (!empty($introHtml))
        <div class="mb-4">{!! $introHtml !!}</div>
    @endif

    <div class="d-flex justify-content-end">
        <a href="{{ $base }}?page=1" class="btn btn-primary">Commencer</a>
    </div>
@endsection
