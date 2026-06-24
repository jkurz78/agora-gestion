<div>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <a href="{{ route('questionnaires.modeles.index') }}" class="btn btn-sm btn-link px-0">&larr; Retour aux modèles</a>
        <a href="{{ route('questionnaires.modeles.apercu', $template) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
            Prévisualiser
        </a>
    </div>
    <h1 class="h4">{{ $template->titre_interne }} — Textes</h1>

    @if (session('textes_ok'))
        <div class="alert alert-success py-2 mb-3">Textes enregistrés.</div>
    @endif

    <div class="card mb-4">
        <div class="card-header fw-semibold">Textes du questionnaire</div>
        <div class="card-body">

            {{-- Titre affiché --}}
            <div class="mb-4">
                <label class="form-label fw-semibold">Titre affiché au répondant</label>
                <input type="text" class="form-control" wire:model="titreAffiche"
                       placeholder="Titre visible par le répondant">
                <div class="form-text text-muted">
                    Variables utilisables : {prenom} {nom} {operation} {association}
                </div>
            </div>

            {{-- Éditeur Introduction --}}
            <div class="mb-4">
                <label class="form-label fw-semibold">Introduction (page d'accueil du répondant)</label>
                @include('partials.tinymce-rich-editor', [
                    'id'      => 'q-textes-intro',
                    'model'   => 'intro',
                    'content' => $intro,
                    'height'  => 320,
                    'groups'  => [
                        'Participant'  => ['{prenom}' => 'Prénom', '{nom}' => 'Nom', '{civilite}' => 'Civilité', '{politesse}' => 'Politesse'],
                        'Opération'    => ['{operation}' => 'Opération', '{type_operation}' => 'Type opération', '{date_debut}' => 'Date début', '{date_fin}' => 'Date fin', '{nb_seances}' => 'Nb séances'],
                        'Association'  => ['{association}' => 'Association'],
                    ],
                ])
            </div>

            {{-- Éditeur Remerciement --}}
            <div class="mb-4">
                <label class="form-label fw-semibold">Message de remerciement (page finale)</label>
                @include('partials.tinymce-rich-editor', [
                    'id'      => 'q-textes-merci',
                    'model'   => 'remerciement',
                    'content' => $remerciement,
                    'height'  => 320,
                    'groups'  => [
                        'Participant'  => ['{prenom}' => 'Prénom', '{nom}' => 'Nom', '{civilite}' => 'Civilité', '{politesse}' => 'Politesse'],
                        'Opération'    => ['{operation}' => 'Opération', '{type_operation}' => 'Type opération', '{date_debut}' => 'Date début', '{date_fin}' => 'Date fin', '{nb_seances}' => 'Nb séances'],
                        'Association'  => ['{association}' => 'Association'],
                    ],
                ])
            </div>

            <button type="button" class="btn btn-primary"
                    onclick="qTextesSyncAndSave()">
                Enregistrer les textes
            </button>
        </div>
    </div>
</div>

@script
<script>
    // Flush both editors into Livewire before calling enregistrer().
    // Flush both editors into Livewire before calling enregistrer().
    // Keys match window['__qgSync_' + editorId] set in the tinymce-rich-editor partial.
    window.qTextesSyncAndSave = function () {
        var syncIntro = window['__qgSync_q-textes-intro'];
        var syncMerci = window['__qgSync_q-textes-merci'];
        if (typeof syncIntro === 'function') { syncIntro(); }
        if (typeof syncMerci === 'function') { syncMerci(); }
        setTimeout(function () { $wire.call('enregistrer'); }, 150);
    };
</script>
@endscript
