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

    {{-- Inserter variables --}}
    <div class="mb-2 d-flex align-items-center gap-2 flex-wrap" id="envoi-var-buttons">
        <span class="small text-muted fw-semibold me-1">Insérer :</span>
        @foreach (['{prenom}', '{nom}', '{operation}', '{type_operation}', '{association}', '{date_debut}', '{date_fin}', '{nb_seances}', '{lien_questionnaire}'] as $var)
            <button type="button" class="btn btn-sm btn-outline-secondary font-monospace py-0"
                    onclick="envoiInsertVariable('{{ $var }}')" title="Insérer {{ $var }}">{{ $var }}</button>
        @endforeach
    </div>

    {{-- Corps TinyMCE --}}
    <div class="mb-3">
        <label class="form-label fw-semibold">Corps de l'email</label>
        <div wire:ignore>
            <textarea id="envoi-corps-editor" rows="12" style="width:100%">{!! $corps !!}</textarea>
        </div>
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

@assets
<script>
    function envoiInsertVariable(v) {
        if (typeof tinymce === 'undefined') return;
        var active = tinymce.activeEditor;
        if (active) {
            active.insertContent(v);
        }
    }
</script>
@endassets

@script
<script>
    var _envoiEditorId = 'envoi-corps-editor';

    function _envoiInitEditor() {
        if (typeof tinymce === 'undefined') {
            setTimeout(_envoiInitEditor, 200);
            return;
        }

        if (tinymce.get(_envoiEditorId)) return;

        var textarea = document.getElementById(_envoiEditorId);
        if (!textarea) return;

        tinymce.init({
            target: textarea,
            language: 'fr_FR',
            language_url: '/vendor/tinymce/langs/fr_FR.js',
            height: 320,
            menubar: false,
            statusbar: false,
            promotion: false,
            plugins: 'lists link',
            toolbar: 'undo redo | bold italic underline | bullist numlist | link',
            setup: function (editor) {
                editor.on('change input', function () {
                    $wire.set('corps', editor.getContent());
                });
            },
        });
    }

    _envoiInitEditor();

    window.envoiSyncAndCall = function (action) {
        var editor = tinymce.get(_envoiEditorId);
        if (editor) {
            $wire.set('corps', editor.getContent());
        }
        setTimeout(function () { $wire.call(action); }, 150);
    };
</script>
@endscript
