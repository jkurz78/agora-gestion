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

            {{-- File attachments --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Pièces jointes <span class="text-muted">(max 5 fichiers, 10 Mo au total)</span></label>
                <input type="file" class="form-control form-control-sm" wire:model="emailAttachments" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                @error('emailAttachments.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                @error('emailAttachments') <div class="text-danger small mt-1">{{ $message }}</div> @enderror

                @if(count($emailAttachments) > 0)
                    <div class="mt-2">
                        @foreach($emailAttachments as $index => $attachment)
                            <span class="badge bg-light text-dark border me-1 mb-1">
                                <i class="bi bi-paperclip"></i> {{ $attachment->getClientOriginalName() }}
                                <small class="text-muted">({{ number_format($attachment->getSize() / 1024, 0) }} Ko)</small>
                                <button type="button" class="btn-close btn-close-sm ms-1" style="font-size:0.5em"
                                        wire:click="removeAttachment({{ $index }})"></button>
                            </span>
                        @endforeach
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

            {{-- Progress display during send --}}
            @if($envoiEnCours)
                <div class="alert alert-info py-2 mb-3">
                    <div class="d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        <span>Envoi en cours : {{ $envoiProgression }} / {{ $envoiTotal }}</span>
                    </div>
                    <div class="progress mt-2" style="height: 6px;">
                        <div class="progress-bar" style="width: {{ $envoiTotal > 0 ? ($envoiProgression / $envoiTotal * 100) : 0 }}%"></div>
                    </div>
                </div>
            @endif

            @if($envoiResultat)
                <div class="alert alert-success py-2 mb-3 small">
                    <i class="bi bi-check-circle me-1"></i> {{ $envoiResultat }}
                </div>
            @endif

            @if(session()->has('message'))
                <div class="alert alert-success py-1 small mb-3">{{ session('message') }}</div>
            @endif

            @if(session()->has('error'))
                <div class="alert alert-danger py-1 small mb-3">{{ session('error') }}</div>
            @endif

            {{-- Action buttons --}}
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        onclick="syncMessageEditor(); setTimeout(() => $wire.set('showTestModal', true), 100)">
                    <i class="bi bi-send me-1"></i>Envoyer un test
                </button>
                <button type="button" class="btn btn-sm btn-primary"
                        onclick="syncMessageEditor(); setTimeout(() => $wire.set('showConfirmSend', true), 100)"
                        {{ count($selectedParticipants) === 0 ? 'disabled' : '' }}>
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

    {{-- Test email modal --}}
    @if($showTestModal)
    <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
         style="background:rgba(0,0,0,.3);z-index:2100"
         wire:click.self="$set('showTestModal', false)">
        <div class="bg-white rounded-3 shadow p-4" style="max-width:400px;width:100%">
            <h6 class="mb-3"><i class="bi bi-envelope me-1"></i> Envoyer un email de test</h6>
            <p class="small text-muted mb-2">
                Variables substituées pour le 1er participant sélectionné.
            </p>
            <div class="mb-3">
                <label class="form-label small">Adresse destinataire</label>
                <input type="email" wire:model="testEmail" class="form-control form-control-sm @error('testEmail') is-invalid @enderror"
                       placeholder="votre@email.fr">
                @error('testEmail')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="$set('showTestModal', false)">
                    Fermer
                </button>
                <button type="button" class="btn btn-sm btn-primary" wire:click="envoyerTest">
                    <span wire:loading.remove wire:target="envoyerTest">Envoyer</span>
                    <span wire:loading wire:target="envoyerTest">Envoi…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Confirm send modal --}}
    @if($showConfirmSend)
    <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
         style="background:rgba(0,0,0,.3);z-index:2100"
         wire:click.self="$set('showConfirmSend', false)">
        <div class="bg-white rounded-3 shadow p-4" style="max-width:400px;width:100%">
            <h6 class="mb-3"><i class="bi bi-envelope-paper me-1"></i> Confirmer l'envoi</h6>
            <p class="mb-3">
                Envoyer ce message à <strong>{{ count($selectedParticipants) }}</strong> participant{{ count($selectedParticipants) > 1 ? 's' : '' }} ?
            </p>
            @if(count($emailAttachments) > 0)
                <p class="small text-muted mb-3">
                    {{ count($emailAttachments) }} pièce(s) jointe(s)
                </p>
            @endif
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        wire:click="$set('showConfirmSend', false)">Annuler</button>
                <button type="button" class="btn btn-sm btn-primary" wire:click="envoyerMessages">
                    <span wire:loading.remove wire:target="envoyerMessages">Confirmer l'envoi</span>
                    <span wire:loading wire:target="envoyerMessages">
                        <span class="spinner-border spinner-border-sm me-1"></span>Envoi en cours…
                    </span>
                </button>
            </div>
        </div>
    </div>
    @endif
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
