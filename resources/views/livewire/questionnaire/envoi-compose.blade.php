<div>
    @if (session('envoi_ok'))
        <div class="alert alert-success py-2 mb-3">{{ session('envoi_ok') }}</div>
    @endif

    {{-- Objet --}}
    <div class="mb-3">
        <label class="form-label fw-semibold">Objet de l'email</label>
        <input type="text" class="form-control" wire:model="objet" placeholder="Ex : Votre avis nous intéresse">
        @error('objet') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    </div>

    {{-- Corps TinyMCE --}}
    <div class="mb-3">
        <label class="form-label fw-semibold">Corps de l'email</label>
        @include('partials.tinymce-rich-editor', [
            'id'          => 'q-envoi-corps',
            'model'       => 'corps',
            'content'     => $corps,
            'height'      => 320,
            'groups'      => [
                'Participant' => ['{prenom}' => 'Prénom', '{nom}' => 'Nom', '{email_participant}' => 'Email'],
                'Politesse'   => ['{civilite}' => 'Civilité', '{politesse}' => 'Politesse', '{civilite_nom}' => 'M. NOM', '{politesse_nom}' => 'Monsieur NOM', '{salutation}' => 'Salutation'],
                'Opération'   => ['{operation}' => 'Opération', '{type_operation}' => 'Type', '{date_debut}' => 'Date début', '{date_fin}' => 'Date fin', '{nb_seances}' => 'Nb séances', '{association}' => 'Association'],
                'Lien'        => ['{lien_questionnaire}' => 'Lien du questionnaire'],
            ],
            'insertItems' => [
                "Logo de l'association" => '{logo}',
                'Tableau des séances'   => '{table_seances}',
            ],
        ])
        @error('corps') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    </div>

    {{-- Sélection des participants --}}
    @if ($participants->isNotEmpty())
        <div class="mb-3">
            <label class="form-label fw-semibold">Destinataires</label>
            <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                @foreach ($participants as $p)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               id="envoi-part-{{ $p->id }}"
                               wire:model="selectedParticipants"
                               value="{{ $p->id }}">
                        <label class="form-check-label" for="envoi-part-{{ $p->id }}">
                            {{ $p->tiers?->displayName() ?? '—' }}
                            @if (! $p->tiers?->email)
                                <span class="text-muted small">(sans email)</span>
                            @endif
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Actions --}}
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-primary"
                onclick="envoiSyncAndCall('envoyer')">
            Envoyer les invitations
        </button>
        <button type="button" class="btn btn-outline-warning"
                onclick="envoiSyncAndCall('relancer')">
            Relancer les non-répondants
        </button>
    </div>
</div>

@script
<script>
    window.envoiSyncAndCall = function (action) {
        var syncCorps = window['__qgSync_q-envoi-corps'];
        if (typeof syncCorps === 'function') { syncCorps(); }
        setTimeout(function () { $wire.call(action); }, 150);
    };
</script>
@endscript
