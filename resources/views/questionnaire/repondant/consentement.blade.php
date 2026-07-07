@extends('questionnaire.repondant.layout')

@section('content')
    <h2 class="h5 mb-3">Dernière étape</h2>

    <p class="text-muted mb-4">
        Vos réponses sont confidentielles et non nominatives. Elles ne seront utilisées qu'à des fins
        d'amélioration de nos activités.
    </p>

    <form method="POST" action="{{ route(($bearer ?? false) ? 'questionnaire.bearer.store' : 'questionnaire.store', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="action" value="finish">

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox"
                   name="accepte_contact"
                   id="accepte_contact"
                   value="1"
                   onchange="document.getElementById('contact-nom-group').style.display = this.checked ? 'block' : 'none'">
            <label class="form-check-label" for="accepte_contact">
                J'accepte que mes réponses soient rattachées à mon nom pour que l'association puisse me recontacter (facultatif).
            </label>
        </div>
        @if ($bearer ?? false)
            <div id="contact-nom-group" class="mb-4" style="display:none;">
                <label for="contact_nom" class="form-label">Votre prénom et nom</label>
                <input type="text" class="form-control" name="contact_nom" id="contact_nom"
                       placeholder="Prénom Nom">
            </div>
        @endif

        <div class="d-flex {{ $campagne->autoriser_retour ? 'justify-content-between' : 'justify-content-end' }}">
            @if ($campagne->autoriser_retour)
            <a href="{{ route(($bearer ?? false) ? 'questionnaire.bearer.show' : 'questionnaire.show', ['token' => $token, 'page' => $total]) }}"
               class="btn btn-outline-secondary">← Précédent</a>
            @endif
            <button type="submit" class="btn btn-success">Envoyer mes réponses</button>
        </div>
    </form>
@endsection
