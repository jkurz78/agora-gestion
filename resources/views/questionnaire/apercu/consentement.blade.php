@extends('questionnaire.repondant.layout')

@section('content')
    <div class="alert alert-warning py-2 mb-4">
        <strong>Mode aperçu</strong> — aucune réponse n'est enregistrée.
    </div>

    <h2 class="h5 mb-3">Dernière étape</h2>

    <p class="text-muted mb-4">
        Vos réponses sont confidentielles et non nominatives. Elles ne seront utilisées qu'à des fins
        d'amélioration de nos activités.
    </p>

    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox"
               id="accepte_contact_preview"
               value="1" disabled>
        <label class="form-check-label text-muted" for="accepte_contact_preview">
            J'accepte que l'association me contacte à propos de mes réponses (facultatif).
        </label>
    </div>

    <div class="d-flex {{ ($autoriser_retour ?? true) ? 'justify-content-between' : 'justify-content-end' }}">
        @if ($autoriser_retour ?? true)
        <a href="{{ $base }}?page={{ $total }}" class="btn btn-outline-secondary">← Précédent</a>
        @endif
        <a href="{{ $base }}?page=merci" class="btn btn-success">Terminer</a>
    </div>
@endsection
