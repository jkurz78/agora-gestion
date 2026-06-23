@extends('questionnaire.repondant.layout')

@section('content')
    <h1 class="h4 mb-3">{{ $titre ?? $campagne->titre_affiche }}</h1>

    @if (!empty($introHtml))
        <div class="mb-4">{!! $introHtml !!}</div>
    @elseif ($campagne->intro)
        <div class="mb-4">{!! nl2br(e($campagne->intro)) !!}</div>
    @endif

    <form method="POST" action="{{ route('questionnaire.store', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="action" value="start">
        <button type="submit" class="btn btn-primary">Commencer</button>
    </form>
@endsection
