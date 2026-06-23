<div>
    <a href="{{ route('questionnaires.modeles.index') }}" class="btn btn-sm btn-link px-0 mb-2">&larr; Modèles</a>
    <h1 class="h4">{{ $template->titre_interne }}</h1>

    {{-- Section : Messages du questionnaire --}}
    <div class="card mb-4">
        <div class="card-header fw-semibold">Messages du questionnaire</div>
        <div class="card-body">

            @if (session('messages_ok'))
                <div class="alert alert-success py-2 mb-3">Messages enregistrés.</div>
            @endif

            {{-- Liste des variables disponibles pour l'insertion --}}
            <div class="mb-2 d-flex align-items-center gap-2 flex-wrap" id="q-var-buttons">
                <span class="small text-muted fw-semibold me-1">Insérer :</span>
                @foreach (['{prenom}', '{nom}', '{operation}', '{type_operation}', '{association}', '{date_debut}', '{date_fin}', '{nb_seances}'] as $var)
                    <button type="button" class="btn btn-sm btn-outline-secondary font-monospace py-0"
                            onclick="qInsertVariable('{{ $var }}')" title="Insérer {{ $var }}">{{ $var }}</button>
                @endforeach
            </div>

            {{-- Éditeur Intro --}}
            <div class="mb-3">
                <label class="form-label fw-semibold">Introduction (page d'accueil du répondant)</label>
                <div wire:ignore
                     x-data="qEditorIntro()"
                     x-init="init()">
                    <textarea id="q-editor-intro" x-ref="editor">{!! $intro !!}</textarea>
                </div>
            </div>

            {{-- Éditeur Remerciement --}}
            <div class="mb-3">
                <label class="form-label fw-semibold">Message de remerciement (page finale)</label>
                <div wire:ignore
                     x-data="qEditorMerci()"
                     x-init="init()">
                    <textarea id="q-editor-merci" x-ref="editor">{!! $remerciement !!}</textarea>
                </div>
            </div>

            <button type="button" class="btn btn-primary"
                    onclick="qSyncAndSave()">
                Enregistrer les messages
            </button>
        </div>
    </div>

    <table class="table align-middle">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr><th style="width:60px">#</th><th>Question</th><th>Type</th><th class="text-center">Obligatoire</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($questions as $q)
                <tr>
                    <td>{{ $q->ordre }}</td>
                    <td>{{ $q->libelle }}@if($q->aDesOptions()) <span class="text-muted small">({{ count($q->options()) }} options)</span>@endif</td>
                    <td>{{ $q->type->label() }}</td>
                    <td class="text-center">{{ $q->obligatoire ? 'Oui' : 'Non' }}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" wire:click="monter({{ $q->id }})">↑</button>
                        <button class="btn btn-sm btn-outline-secondary" wire:click="descendre({{ $q->id }})">↓</button>
                        <button class="btn btn-sm btn-outline-danger" wire:click="supprimerQuestion({{ $q->id }})" wire:confirm="Supprimer cette question ?">×</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-3">Aucune question.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="card">
        <div class="card-body">
            <h2 class="h6">Ajouter une question</h2>
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="text" class="form-control" placeholder="Libellé" wire:model="libelle">
                    @error('libelle') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <select class="form-select" wire:model.live="type">
                        @foreach ($types as $t)
                            <option value="{{ $t['value'] }}">{{ $t['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 form-check d-flex align-items-center ms-2">
                    <input type="checkbox" class="form-check-input me-1" wire:model="obligatoire" id="obl">
                    <label class="form-check-label" for="obl">Obligatoire</label>
                </div>
                <div class="col-md-12">
                    <input type="text" class="form-control" placeholder="Aide (optionnelle)" wire:model="aide">
                </div>
                @if ($type === 'choix_unique')
                    <div class="col-md-12">
                        <label class="form-label small text-muted">Options (une par ligne)</label>
                        <textarea class="form-control" rows="3" wire:model="optionsBrut"></textarea>
                    </div>
                @endif
                @if ($type === 'satisfaction')
                    <div class="col-md-12 d-flex align-items-center gap-3 flex-wrap">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" wire:model.live="commentaire" id="commentaire_toggle">
                            <label class="form-check-label" for="commentaire_toggle">Commentaire optionnel</label>
                        </div>
                        @if ($commentaire)
                            <input type="text" class="form-control flex-grow-1"
                                   placeholder="Un commentaire ? (optionnel)"
                                   wire:model="commentaireLibelle">
                        @endif
                    </div>
                @endif
                <div class="col-12">
                    <button class="btn btn-primary" wire:click="ajouterQuestion">Ajouter</button>
                </div>
            </div>
        </div>
    </div>
</div>

@assets
<script>
    // ---- Questionnaire message editors (TinyMCE + Alpine) ----
    // Two distinct editors on the same page: intro (qEditorIntro) and merci (qEditorMerci).
    // Pattern mirrors operation-communication.blade.php: wire:ignore + Alpine + tinymce.init.

    function _qStripVarSpans(html) {
        return html.replace(
            /<span\b[^>]*\bq-mce-var\b[^>]*>(\{[^}]+\})<\/span>/g,
            function (_match, token) { return token; }
        );
    }

    var _qVariables = ['{prenom}', '{nom}', '{operation}', '{type_operation}', '{association}', '{date_debut}', '{date_fin}', '{nb_seances}'];

    function _qWrapVars(html) {
        _qVariables.forEach(function (v) {
            var escaped = v.replace(/[{}]/g, '\\$&');
            var regex = new RegExp('(?!<span[^>]*>)' + escaped + '(?![^<]*<\\/span>)', 'g');
            html = html.replace(regex, '<span class="q-mce-var mce-noneditable">' + v + '</span>');
        });
        return html;
    }

    function _qMakeEditorComponent(wireProperty) {
        return {
            editor: null,

            init() {
                this.$nextTick(() => this.setup());
                this.$cleanup(() => this.destroy());
            },

            setup() {
                if (typeof tinymce === 'undefined') {
                    setTimeout(() => this.setup(), 300);
                    return;
                }

                var textarea = this.$refs.editor;
                if (!textarea) return;

                var self = this;

                // Variable insertion menu items
                var menuItems = _qVariables.map(function (v) {
                    return {
                        type: 'menuitem',
                        text: v,
                        onAction: function () {
                            if (self.editor) {
                                self.editor.insertContent('<span class="q-mce-var mce-noneditable">' + v + '</span>&nbsp;');
                            }
                        },
                    };
                });

                tinymce.init({
                    target: textarea,
                    language: 'fr_FR',
                    language_url: '/vendor/tinymce/langs/fr_FR.js',
                    height: 280,
                    menubar: false,
                    statusbar: false,
                    promotion: false,
                    plugins: 'lists link noneditable',
                    toolbar: 'undo redo | bold italic underline | bullist numlist | link | variablesBtn',
                    noneditable_class: 'q-mce-var',
                    content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } .q-mce-var { background: #f3edff; border: 1px solid #d4c5f9; border-radius: 3px; padding: 1px 3px; font-family: monospace; font-size: 12px; color: #7c3aed; display: inline-block; }',
                    setup: function (editor) {
                        self.editor = editor;

                        editor.ui.registry.addMenuButton('variablesBtn', {
                            text: 'Variables',
                            fetch: function (callback) { callback(menuItems); },
                        });

                        editor.on('init', function () {
                            editor.setContent(_qWrapVars(editor.getContent()));
                        });

                        // Sync to Livewire on every content change
                        editor.on('change input', function () {
                            var content = _qStripVarSpans(editor.getContent());
                            var wireEl = editor.targetElm.closest('[wire\\:id]');
                            if (wireEl) {
                                Livewire.find(wireEl.getAttribute('wire:id')).set(wireProperty, content);
                            }
                        });
                    },
                });
            },

            destroy() {
                if (this.editor) {
                    try { tinymce.remove(this.editor); } catch (e) {}
                    this.editor = null;
                }
            },

            getContent() {
                return this.editor ? _qStripVarSpans(this.editor.getContent()) : '';
            },
        };
    }

    if (typeof Alpine !== 'undefined') {
        if (!Alpine.components?.qEditorIntro) {
            Alpine.data('qEditorIntro', () => _qMakeEditorComponent('intro'));
        }
        if (!Alpine.components?.qEditorMerci) {
            Alpine.data('qEditorMerci', () => _qMakeEditorComponent('remerciement'));
        }
    }

    // ---- Helper: find the Livewire wire:id on the modele-editor component ----
    function _qGetWire() {
        var el = document.getElementById('q-editor-intro');
        if (!el) el = document.getElementById('q-editor-merci');
        var root = el && el.closest('[wire\\:id]');
        return root ? Livewire.find(root.getAttribute('wire:id')) : null;
    }

    // ---- Insert variable at cursor in whichever editor is active ----
    function qInsertVariable(v) {
        if (typeof tinymce === 'undefined') return;
        var active = tinymce.activeEditor;
        if (active) {
            active.insertContent('<span class="q-mce-var mce-noneditable">' + v + '</span>&nbsp;');
        }
    }

    // ---- Sync both editors then call enregistrerMessages ----
    function qSyncAndSave() {
        if (typeof tinymce === 'undefined') {
            var wire = _qGetWire();
            if (wire) wire.call('enregistrerMessages');
            return;
        }

        // Sync intro
        var introEl = document.getElementById('q-editor-intro');
        var introRoot = introEl && introEl.closest('[wire\\:id]');
        if (introRoot) {
            var introEditor = tinymce.get(introEl.id);
            if (introEditor) {
                Livewire.find(introRoot.getAttribute('wire:id')).set('intro', _qStripVarSpans(introEditor.getContent()));
            }
        }

        // Sync remerciement
        var merciEl = document.getElementById('q-editor-merci');
        var merciRoot = merciEl && merciEl.closest('[wire\\:id]');
        if (merciRoot) {
            var merciEditor = tinymce.get(merciEl.id);
            if (merciEditor) {
                Livewire.find(merciRoot.getAttribute('wire:id')).set('remerciement', _qStripVarSpans(merciEditor.getContent()));
            }
        }

        // Wait for Livewire to settle, then call the action
        setTimeout(function () {
            var wire = _qGetWire();
            if (wire) wire.call('enregistrerMessages');
        }, 150);
    }
</script>
@endassets
