<div>
    <div class="card mt-2">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0"><i class="bi bi-envelope me-1"></i> Nouveau message</h6>
        </div>
        <div class="card-body">
            {{-- Template selector --}}
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Partir d'un modèle…</label>
                    <select class="form-select form-select-sm" wire:model="selectedTemplateId" wire:change="loadTemplate">
                        <option value="">— Composition libre —</option>
                        @foreach($templates as $groupName => $groupTemplates)
                            <optgroup label="{{ $groupName }}">
                                @foreach($groupTemplates as $tpl)
                                    <option value="{{ $tpl->id }}">{{ $tpl->nom }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Subject --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Objet</label>
                <input type="text" class="form-control form-control-sm" wire:model="objet" placeholder="Objet du message">
            </div>

            {{-- Body — TinyMCE --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Corps</label>
                <style>.tox-tinymce-aux { z-index: 2100 !important; }</style>
                <div wire:ignore
                     x-data="messageTinymce()"
                     x-init="init()">
                    <textarea x-ref="editor">{!! $corps !!}</textarea>
                </div>

                {{-- Unresolved variables warning --}}
                @if(!empty($unresolvedVariables))
                    <div class="alert alert-warning py-1 px-2 mt-2 small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Variable{{ count($unresolvedVariables) > 1 ? 's' : '' }} sans valeur pour cette opération :
                        @foreach($unresolvedVariables as $var)
                            <code>{{ $var }}</code>{{ !$loop->last ? ', ' : '' }}
                        @endforeach
                    </div>
                @endif

                <div class="form-text small mt-1">
                    Variables :
                    @foreach($messageVariables as $var => $desc)
                        <code title="{{ $desc }}">{{ $var }}</code>
                    @endforeach
                </div>
            </div>

            {{-- Save as template --}}
            <div class="mb-3">
                @if(session()->has('message'))
                    <div class="alert alert-success py-1 small">{{ session('message') }}</div>
                @endif

                @if($showSaveTemplate)
                    <div class="border rounded p-2 bg-light">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label small">Nom du modèle</label>
                                <input type="text" class="form-control form-control-sm @error('templateNom') is-invalid @enderror"
                                       wire:model="templateNom" placeholder="Ex: Rappel séance J-2">
                                @error('templateNom')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Type d'opération <span class="text-muted">(optionnel)</span></label>
                                <select class="form-select form-select-sm" wire:model="templateTypeOperationId">
                                    <option value="">Modèle général</option>
                                    @foreach(\App\Models\TypeOperation::orderBy('nom')->get() as $to)
                                        <option value="{{ $to->id }}">{{ $to->nom }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="syncMessageEditor(); $wire.saveAsTemplate()">
                                    <i class="bi bi-check-lg"></i> Enregistrer
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showSaveTemplate', false)">
                                    Annuler
                                </button>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showSaveTemplate', true)">
                            <i class="bi bi-bookmark-plus me-1"></i>Enregistrer comme modèle
                        </button>
                        @if($selectedTemplateId)
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="syncMessageEditor(); $wire.updateTemplate()">
                                <i class="bi bi-pencil me-1"></i>Mettre à jour « {{ $templates->flatten()->firstWhere('id', $selectedTemplateId)?->nom }} »
                            </button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Participants --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">
                    Destinataires
                    <span class="badge bg-secondary ms-1">{{ count($selectedParticipants) }} sélectionné{{ count($selectedParticipants) > 1 ? 's' : '' }}</span>
                </label>
                <div class="border rounded" style="max-height: 250px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880" class="table-dark">
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input"
                                           wire:click="toggleSelectAll"
                                           @checked(count($selectedParticipants) === $participantsWithEmailCount && $participantsWithEmailCount > 0)>
                                </th>
                                <th>Nom</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($participants as $participant)
                                @php $hasEmail = !empty($participant->tiers?->email); @endphp
                                <tr class="{{ !$hasEmail ? 'text-muted' : '' }}">
                                    <td>
                                        @if($hasEmail)
                                            <input type="checkbox" class="form-check-input"
                                                   value="{{ $participant->id }}"
                                                   wire:model="selectedParticipants">
                                        @else
                                            <input type="checkbox" class="form-check-input" disabled>
                                        @endif
                                    </td>
                                    <td>{{ $participant->tiers?->displayName() ?? '—' }}</td>
                                    <td>
                                        @if($hasEmail)
                                            {{ $participant->tiers->email }}
                                        @else
                                            <em class="small">pas d'email</em>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Action buttons (placeholders — send functionality wired in later steps) --}}
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                    <i class="bi bi-send me-1"></i>Envoyer un test
                </button>
                <button type="button" class="btn btn-sm btn-primary" disabled>
                    <i class="bi bi-envelope-paper me-1"></i>Envoyer à {{ count($selectedParticipants) }} participant{{ count($selectedParticipants) > 1 ? 's' : '' }}
                </button>
            </div>
        </div>
    </div>

    {{-- Campaign history placeholder --}}
    <div class="card mt-3">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i> Historique des envois</h6>
        </div>
        <div class="card-body text-muted small">
            Aucune campagne d'envoi pour cette opération.
        </div>
    </div>
</div>

@script
<script>
    function stripVariableSpans(html) {
        return html.replace(/<span class="mce-variable[^"]*">(\{[^}]+\})<\/span>/g, '$1');
    }

    const messageVariables = {
        '{prenom}': 'Prénom', '{nom}': 'Nom', '{operation}': 'Opération',
        '{type_operation}': 'Type opération', '{date_debut}': 'Date début',
        '{date_fin}': 'Date fin', '{nb_seances}': 'Nb séances',
        '{date_prochaine_seance}': 'Date prochaine séance',
        '{date_precedente_seance}': 'Date précédente séance',
        '{numero_prochaine_seance}': 'N° prochaine séance',
        '{numero_precedente_seance}': 'N° précédente séance',
        '{logo}': 'Logo association',
        '{logo_operation}': 'Logo opération',
    };

    Alpine.data('messageTinymce', () => ({
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

            const textarea = this.$refs.editor;
            if (!textarea) return;

            const self = this;

            const menuItems = Object.entries(messageVariables).map(([key, label]) => ({
                type: 'menuitem',
                text: key + ' — ' + label,
                onAction: () => {
                    if (self.editor) {
                        self.editor.insertContent('<span class="mce-variable mce-noneditable">' + key + '</span>&nbsp;');
                    }
                },
            }));

            tinymce.init({
                target: textarea,
                language: 'fr_FR',
                language_url: '/vendor/tinymce/langs/fr_FR.js',
                height: 250,
                menubar: false,
                statusbar: false,
                plugins: 'lists link noneditable',
                toolbar: 'bold italic underline | bullist numlist | link | variablesButton',
                noneditable_class: 'mce-variable',
                content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } .mce-variable { background: #f3edff; border: 1px solid #d4c5f9; border-radius: 3px; padding: 1px 1px; font-family: monospace; font-size: 12px; color: #7c3aed; display: inline-block; }',
                setup: function (editor) {
                    self.editor = editor;

                    editor.ui.registry.addMenuButton('variablesButton', {
                        text: 'Variables',
                        fetch: function (callback) { callback(menuItems); },
                    });

                    // On init: convert {variable} text to styled spans
                    editor.on('init', function () {
                        let content = editor.getContent();
                        const allVarKeys = Object.keys(messageVariables);
                        allVarKeys.forEach(v => {
                            const escaped = v.replace(/[{}]/g, '\\$&');
                            const regex = new RegExp('(?!<span[^>]*>)' + escaped + '(?!</span>)', 'g');
                            content = content.replace(regex, '<span class="mce-variable mce-noneditable">' + v + '</span>');
                        });
                        editor.setContent(content);
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
            if (this.editor) {
                return stripVariableSpans(this.editor.getContent());
            }
            return '';
        },

        setContent(html) {
            if (this.editor) {
                // Convert variable placeholders to styled spans
                const allVarKeys = Object.keys(messageVariables);
                allVarKeys.forEach(v => {
                    const escaped = v.replace(/[{}]/g, '\\$&');
                    const regex = new RegExp('(?!<span[^>]*>)' + escaped + '(?!</span>)', 'g');
                    html = html.replace(regex, '<span class="mce-variable mce-noneditable">' + v + '</span>');
                });
                this.editor.setContent(html);
            }
        },
    }));

    // Sync TinyMCE content to Livewire before any action
    window.syncMessageEditor = function() {
        const el = document.querySelector('[x-data="messageTinymce()"]');
        if (el && el._x_dataStack) {
            const data = el._x_dataStack[0];
            if (data && data.getContent) {
                $wire.set('corps', data.getContent());
            }
        }
    };

    // Listen for template loaded event to update TinyMCE content
    $wire.on('template-loaded', (data) => {
        const el = document.querySelector('[x-data="messageTinymce()"]');
        if (el && el.__x) {
            el.__x.$data.setContent(data[0].corps);
        }
    });
</script>
@endscript
