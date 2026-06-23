@extends('questionnaire.repondant.layout')

@section('content')
    <h2 class="h5 mb-3">Dernière étape</h2>

    <p class="text-muted mb-4">
        Vos réponses sont confidentielles et non nominatives. Elles ne seront utilisées qu'à des fins
        d'amélioration de nos activités.
    </p>

    <form method="POST" action="{{ route('questionnaire.store', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="action" value="finish">

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox"
                   name="accepte_contact"
                   id="accepte_contact"
                   value="1">
            <label class="form-check-label" for="accepte_contact">
                J'accepte que l'association me contacte à propos de mes réponses (facultatif).
            </label>
        </div>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-success">Envoyer mes réponses</button>
        </div>
    </form>
@endsection
