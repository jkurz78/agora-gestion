<div id="operation-communication">
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
                <div class="d-flex align-items-center gap-2 mb-1">
                    <label class="form-label small fw-semibold mb-0">Objet</label>
                    <div x-data="{ openGroup: null }" @click.outside="openGroup = null" class="position-relative">
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:11px" @click="openGroup = openGroup ? null : 'root'">
                            <i class="bi bi-braces me-1"></i>Variables
                        </button>
                        <div class="dropdown-menu" :class="openGroup && 'show'" style="min-width:160px;font-size:12px">
                            @php
                                $varGroups = [
                                    'Participant' => ['{prenom}' => 'Prénom', '{nom}' => 'Nom', '{email_participant}' => 'Email'],
                                    'Opération' => ['{operation}' => 'Nom', '{type_operation}' => 'Type', '{date_debut}' => 'Date début', '{date_fin}' => 'Date fin', '{nb_seances}' => 'Nb séances'],
                                    'Association' => ['{association}' => 'Nom'],
                                    'Séances' => ['{date_prochaine_seance}' => 'Date prochaine', '{numero_prochaine_seance}' => 'N° prochaine', '{titre_prochaine_seance}' => 'Titre prochaine', '{jours_avant_prochaine_seance}' => 'Jours avant prochaine', '{date_precedente_seance}' => 'Date précédente', '{numero_precedente_seance}' => 'N° précédente', '{titre_precedente_seance}' => 'Titre précédente', '{nb_seances_effectuees}' => 'Nb effectuées', '{nb_seances_restantes}' => 'Nb restantes'],
                                ];
                            @endphp
                            @foreach($varGroups as $groupName => $vars)
                                <div class="position-relative" @mouseenter="openGroup = '{{ $groupName }}'" @mouseleave="openGroup = openGroup === '{{ $groupName }}' ? 'root' : openGroup">
                                    <a class="dropdown-item d-flex justify-content-between" href="#">
                                        {{ $groupName }} <i class="bi bi-chevron-right"></i>
                                    </a>
                                    <div class="dropdown-menu" :class="openGroup === '{{ $groupName }}' && 'show'"
                                         style="position:absolute;left:100%;top:0;min-width:260px">
                                        @foreach($vars as $var => $desc)
                                            <a class="dropdown-item" href="#"
                                               @click.prevent="
                                                   const input = document.getElementById('objet-input');
                                                   const pos = input.selectionStart || input.value.length;
                                                   const val = input.value;
                                                   input.value = val.substring(0, pos) + '{{ $var }}' + val.substring(pos);
                                                   input.dispatchEvent(new Event('input'));
                                                   openGroup = null;
                                                   input.focus();
                                               ">
                                                <code class="text-primary">{{ $var }}</code> <span class="text-muted">{{ $desc }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <input type="text" class="form-control form-control-sm" wire:model="objet" placeholder="Objet du message" id="objet-input">
            </div>

            {{-- Body — TinyMCE --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Corps</label>
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
                                        onclick="window.syncAndSaveTemplate()">
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
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="openSaveAsTemplate">
                            <i class="bi bi-bookmark-plus me-1"></i>{{ $selectedTemplateId ? 'Enregistrer comme variante' : 'Enregistrer comme modèle' }}
                        </button>
                        @if($selectedTemplateId)
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="window.syncAndUpdateTemplate()">
                                <i class="bi bi-pencil me-1"></i>Mettre à jour « {{ $templates->flatten()->firstWhere('id', $selectedTemplateId)?->nom }} »
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    wire:click="deleteTemplate"
                                    wire:confirm="Supprimer ce modèle ?">
                                <i class="bi bi-trash"></i>
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
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <label class="form-label small fw-semibold mb-0">
                        Participants
                        <span class="badge bg-secondary ms-1">{{ count($selectedParticipants) }} sélectionné{{ count($selectedParticipants) > 1 ? 's' : '' }}</span>
                        @if($sansEmailCount > 0)
                            <span class="badge bg-warning text-dark ms-1">{{ $sansEmailCount }} sans email</span>
                        @endif
                    </label>
                    @if($seances->count() > 0)
                        <select class="form-select form-select-sm" wire:model.live="filtreSeanceId" style="width:auto;min-width:200px">
                            <option value="">Tous les participants</option>
                            @foreach($seances as $seance)
                                <option value="{{ $seance->id }}">
                                    Séance {{ $seance->numero }}{{ $seance->date ? ' — '.$seance->date->format('d/m/Y') : '' }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>
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
                            @forelse($participants as $participant)
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
                            @empty
                                <tr><td colspan="3" class="text-muted small text-center py-2"><em>Aucun participant.</em></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Encadrants --}}
            @if($encadrants->count() > 0)
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <label class="form-label small fw-semibold mb-0">
                        Encadrants
                        <span class="badge bg-secondary ms-1">{{ count($selectedEncadrants) }} sélectionné{{ count($selectedEncadrants) > 1 ? 's' : '' }}</span>
                        @if($encadrantsSansEmailCount > 0)
                            <span class="badge bg-warning text-dark ms-1">{{ $encadrantsSansEmailCount }} sans email</span>
                        @endif
                    </label>
                </div>
                <div class="border rounded" style="max-height: 250px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880" class="table-dark">
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input"
                                           wire:click="toggleSelectAllEncadrants"
                                           @checked(count($selectedEncadrants) === $encadrantsWithEmailCount && $encadrantsWithEmailCount > 0)>
                                </th>
                                <th>Nom</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($encadrants as $encadrant)
                                @php $hasEmail = !empty($encadrant->email); @endphp
                                <tr class="{{ !$hasEmail ? 'text-muted' : '' }}">
                                    <td>
                                        @if($hasEmail)
                                            <input type="checkbox" class="form-check-input"
                                                   value="{{ $encadrant->id }}"
                                                   wire:model="selectedEncadrants">
                                        @else
                                            <input type="checkbox" class="form-check-input" disabled>
                                        @endif
                                    </td>
                                    <td>{{ $encadrant->displayName() }}</td>
                                    <td>
                                        @if($hasEmail)
                                            {{ $encadrant->email }}
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
            @endif

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
                        onclick="window.syncAndShowPreview()">
                    <i class="bi bi-eye me-1"></i>Aperçu
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary"
                        onclick="window.syncAndShowTestModal()">
                    <i class="bi bi-send me-1"></i>Envoyer un test
                </button>
                @php $totalDestinataires = count($selectedParticipants) + count($selectedEncadrants); @endphp
                <button type="button" class="btn btn-sm btn-primary"
                        onclick="window.syncAndShowConfirmSend()"
                        {{ $totalDestinataires === 0 ? 'disabled' : '' }}>
                    <i class="bi bi-envelope-paper me-1"></i>Envoyer à {{ $totalDestinataires }} destinataire{{ $totalDestinataires > 1 ? 's' : '' }}
                </button>
            </div>
        </div>
    </div>

    {{-- Campaign history --}}
    <div class="card mt-3">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i> Historique des envois</h6>
        </div>
        <div class="card-body">
            @if($campagnes->isEmpty())
                <p class="text-muted small mb-0">Aucune campagne d'envoi pour cette opération.</p>
            @else
                <div class="list-group list-group-flush">
                    @foreach($campagnes as $campagne)
                        <div class="list-group-item px-0">
                            <div class="d-flex align-items-center justify-content-between"
                                 style="cursor:pointer"
                                 wire:click="toggleCampagne({{ $campagne->id }})">
                                <div>
                                    <i class="bi bi-chevron-{{ $expandedCampagneId === $campagne->id ? 'down' : 'right' }} me-1 small"></i>
                                    <strong class="small">{{ $campagne->objet }}</strong>
                                    <span class="text-muted small ms-2">
                                        {{ $campagne->created_at->format('d/m/Y H:i') }}
                                        @if($campagne->envoyePar)
                                            — {{ $campagne->envoyePar->name }}
                                        @endif
                                    </span>
                                </div>
                                <div>
                                    @php
                                        $nbOuverts = $campagne->emailLogs()->whereHas('opens')->count();
                                    @endphp
                                    <span class="badge bg-success">{{ $campagne->nb_destinataires - $campagne->nb_erreurs }} envoyé(s)</span>
                                    @if($nbOuverts > 0)
                                        <span class="badge bg-info">{{ $nbOuverts }} ouvert(s)</span>
                                    @endif
                                    @if($campagne->nb_erreurs > 0)
                                        <span class="badge bg-danger">{{ $campagne->nb_erreurs }} erreur(s)</span>
                                    @endif
                                </div>
                            </div>

                            @if($expandedCampagneId === $campagne->id)
                                <div class="mt-2">
                                    {{-- Message preview --}}
                                    <div class="border rounded p-2 mb-2 bg-light small">
                                        <div class="mb-1"><strong>Objet :</strong> {{ $campagne->objet }}</div>
                                        <div class="text-muted" style="max-height:100px;overflow-y:auto">{!! \Illuminate\Support\Str::limit(strip_tags($campagne->corps), 300) !!}</div>
                                    </div>

                                    {{-- Attachments --}}
                                    @if(!empty($campagne->pieces_jointes))
                                        <div class="mb-2 small">
                                            <strong>Pièces jointes :</strong>
                                            @foreach($campagne->pieces_jointes as $index => $pj)
                                                <a href="#" wire:click.prevent="telechargerPieceJointe({{ $campagne->id }}, {{ $index }})" class="me-2">
                                                    <i class="bi bi-paperclip"></i> {{ $pj['nom'] }}
                                                    <span class="text-muted">({{ number_format($pj['taille'] / 1024, 0) }} Ko)</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Reuse button --}}
                                    <div class="mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                wire:click="reutiliserCampagne({{ $campagne->id }})">
                                            <i class="bi bi-arrow-repeat me-1"></i>Réutiliser ce message
                                        </button>
                                    </div>

                                    {{-- Recipients table --}}
                                    <table class="table table-sm table-bordered mb-0 small">
                                        <thead>
                                            <tr style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880" class="table-dark">
                                                <th>Destinataire</th>
                                                <th>Email</th>
                                                <th>Statut</th>
                                                <th>Ouvert</th>
                                                <th>Détail</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($campagne->emailLogs()->with('opens')->orderBy('created_at')->get() as $log)
                                                <tr>
                                                    <td>{{ $log->destinataire_nom }}</td>
                                                    <td>{{ $log->destinataire_email }}</td>
                                                    <td>
                                                        @if($log->statut === 'envoye')
                                                            <span class="badge bg-success">Envoyé</span>
                                                        @else
                                                            <span class="badge bg-danger">Erreur</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if($log->opens->isNotEmpty())
                                                            @php $firstOpen = $log->opens->sortBy('opened_at')->first(); @endphp
                                                            <span class="text-success">
                                                                <i class="bi bi-eye-fill me-1"></i>{{ $firstOpen->opened_at->format('d/m H:i') }}
                                                                @if($log->opens->count() > 1)
                                                                    <span class="text-muted">({{ $log->opens->count() }}x)</span>
                                                                @endif
                                                            </span>
                                                        @else
                                                            <span class="text-muted">—</span>
                                                        @endif
                                                    </td>
                                                    <td class="text-muted">{{ $log->erreur_message ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
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
            @php $totalDestinataires = count($selectedParticipants) + count($selectedEncadrants); @endphp
            <h6 class="mb-3"><i class="bi bi-envelope-paper me-1"></i> Confirmer l'envoi</h6>
            <p class="mb-3">
                Envoyer ce message à <strong>{{ $totalDestinataires }}</strong> destinataire{{ $totalDestinataires > 1 ? 's' : '' }}
                @if(count($selectedParticipants) > 0 && count($selectedEncadrants) > 0)
                    ({{ count($selectedParticipants) }} participant{{ count($selectedParticipants) > 1 ? 's' : '' }}, {{ count($selectedEncadrants) }} encadrant{{ count($selectedEncadrants) > 1 ? 's' : '' }})
                @endif
                ?
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

    {{-- Preview modal --}}
    @if($showPreview)
        @php $preview = $this->getPreviewHtml(); @endphp
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2100"
             wire:click.self="$set('showPreview', false)">
            <div class="bg-white rounded-3 shadow" style="max-width:700px;width:95%;max-height:80vh;display:flex;flex-direction:column">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0"><i class="bi bi-eye me-1"></i> Aperçu du message</h6>
                        @if(isset($preview['participant']))
                            <small class="text-muted">Variables substituées pour : {{ $preview['participant'] }}</small>
                        @endif
                    </div>
                    <button type="button" class="btn-close" wire:click="$set('showPreview', false)"></button>
                </div>
                <div class="p-3 border-bottom bg-light">
                    <strong class="small">Objet :</strong> {{ $preview['objet'] }}
                </div>
                <div class="p-3" style="overflow-y:auto;flex:1">
                    {!! $preview['corps'] !!}
                </div>
            </div>
        </div>
    @endif

</div>

@assets
<script>
    // --- Alpine component for TinyMCE (registered once, before Alpine init) ---
    function _msgStripVarSpans(html) {
        return html.replace(/<span class="mce-variable[^"]*">(\{[^}]+\})<\/span>/g, '$1');
    }

    window._msgVariableGroups = {
        'Participant': {
            '{prenom}': 'Prénom',
            '{nom}': 'Nom',
            '{email_participant}': 'Email',
        },
        'Opération': {
            '{operation}': 'Nom',
            '{type_operation}': 'Type',
            '{logo_operation}': 'Logo opération',
            '{date_debut}': 'Date début',
            '{date_fin}': 'Date fin',
            '{nb_seances}': 'Nb séances',
        },
        'Séances': {
            '{date_prochaine_seance}': 'Date prochaine',
            '{numero_prochaine_seance}': 'N° prochaine',
            '{titre_prochaine_seance}': 'Titre prochaine',
            '{jours_avant_prochaine_seance}': 'Jours avant prochaine',
            '{date_precedente_seance}': 'Date précédente',
            '{numero_precedente_seance}': 'N° précédente',
            '{titre_precedente_seance}': 'Titre précédente',
            '{nb_seances_effectuees}': 'Nb effectuées',
            '{nb_seances_restantes}': 'Nb restantes',
            '{table_seances}': 'Tableau toutes séances',
            '{table_seances_a_venir}': 'Tableau séances à venir',
        },
        'Association': {
            '{association}': 'Nom',
            '{logo}': 'Logo',
        },
    };

    // Flat version for wrapping/stripping
    window._msgVariables = {};
    Object.values(window._msgVariableGroups).forEach(group => {
        Object.assign(window._msgVariables, group);
    });

    function _msgWrapVars(html) {
        Object.keys(window._msgVariables).forEach(v => {
            const escaped = v.replace(/[{}]/g, '\\$&');
            const regex = new RegExp('(?!<span[^>]*>)' + escaped + '(?!</span>)', 'g');
            html = html.replace(regex, '<span class="mce-variable mce-noneditable">' + v + '</span>');
        });
        return html;
    }

    if (typeof Alpine !== 'undefined' && !Alpine.components?.messageTinymce) {
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

                // Build nested menu items from groups
                const menuItems = Object.entries(window._msgVariableGroups).map(([groupName, vars]) => ({
                    type: 'nestedmenuitem',
                    text: groupName,
                    getSubmenuItems: () => Object.entries(vars).map(([key, label]) => ({
                        type: 'menuitem',
                        text: key + ' — ' + label,
                        onAction: () => {
                            if (self.editor) {
                                self.editor.insertContent('<span class="mce-variable mce-noneditable">' + key + '</span>&nbsp;');
                            }
                        },
                    })),
                }));

                tinymce.init({
                    target: textarea,
                    language: 'fr_FR',
                    language_url: '/vendor/tinymce/langs/fr_FR.js',
                    height: 400,
                    menubar: 'edit insert format table',
                    statusbar: true,
                    promotion: false,
                    plugins: 'lists link noneditable table image media code fullscreen',
                    toolbar: [
                        'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor',
                        'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table image media link | variablesButton insertElementButton | code fullscreen',
                    ],
                    noneditable_class: 'mce-variable',
                    block_formats: 'Paragraphe=p; Titre 1=h1; Titre 2=h2; Titre 3=h3; Titre 4=h4',
                    font_family_formats: 'Arial=arial,helvetica,sans-serif; Georgia=georgia,serif; Courier New=courier new,courier,monospace; Trebuchet MS=trebuchet ms,geneva; Verdana=verdana,geneva',
                    font_size_formats: '10px 12px 14px 16px 18px 20px 24px 28px 32px 36px',
                    table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
                    table_default_styles: { 'border-collapse': 'collapse', 'width': '100%' },
                    table_default_attributes: { border: '1', cellpadding: '6', cellspacing: '0' },
                    image_title: true,
                    image_caption: true,
                    image_advtab: true,
                    image_class_list: [
                        { title: 'En ligne (défaut)', value: '' },
                        { title: 'Flottante à gauche', value: 'img-float-left' },
                        { title: 'Flottante à droite', value: 'img-float-right' },
                        { title: 'Centrée (bloc)', value: 'img-center' },
                    ],
                    automatic_uploads: false,
                    images_upload_handler: function (blobInfo) {
                        return new Promise(function (resolve) {
                            var reader = new FileReader();
                            reader.onload = function () { resolve(reader.result); };
                            reader.readAsDataURL(blobInfo.blob());
                        });
                    },
                    file_picker_types: 'image',
                    file_picker_callback: function (callback, value, meta) {
                        if (meta.filetype === 'image') {
                            var input = document.createElement('input');
                            input.setAttribute('type', 'file');
                            input.setAttribute('accept', 'image/*');
                            input.addEventListener('change', function () {
                                var file = this.files[0];
                                var reader = new FileReader();
                                reader.onload = function () {
                                    callback(reader.result, { alt: file.name, title: file.name });
                                };
                                reader.readAsDataURL(file);
                            });
                            input.click();
                        }
                    },
                    content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } .mce-variable { background: #f3edff; border: 1px solid #d4c5f9; border-radius: 3px; padding: 1px 3px; font-family: monospace; font-size: 12px; color: #7c3aed; display: inline-block; } table { border-collapse: collapse; } td, th { border: 1px solid #ccc; padding: 6px; } .img-float-left { float: left; margin: 0 16px 12px 0; } .img-float-right { float: right; margin: 0 0 12px 16px; } .img-center { display: block; margin: 12px auto; } img { max-width: 100%; height: auto; }',
                    setup: function (editor) {
                        self.editor = editor;

                        editor.ui.registry.addMenuButton('variablesButton', {
                            text: 'Variables',
                            fetch: function (callback) { callback(menuItems); },
                        });

                        // "Insérer élément" button — fetches real data from Livewire
                        editor.ui.registry.addMenuButton('insertElementButton', {
                            text: 'Insérer',
                            icon: 'table-insert-row-after',
                            fetch: function (callback) {
                                callback([
                                    { type: 'menuitem', text: 'Logo association', onAction: function () { insertElement(editor, 'logo'); } },
                                    { type: 'menuitem', text: 'Logo opération', onAction: function () { insertElement(editor, 'logo_operation'); } },
                                    { type: 'separator' },
                                    { type: 'menuitem', text: 'Tableau — toutes les séances', onAction: function () { insertElement(editor, 'table_seances'); } },
                                    { type: 'menuitem', text: 'Tableau — séances à venir', onAction: function () { insertElement(editor, 'table_seances_a_venir'); } },
                                    { type: 'separator' },
                                    { type: 'menuitem', text: 'Bloc infos opération', onAction: function () { insertElement(editor, 'bloc_infos'); } },
                                ]);
                            },
                        });

                        // Preserve aspect ratio on image resize
                        editor.on('ObjectResized', function (e) {
                            if (e.target.nodeName === 'IMG') {
                                e.target.style.height = 'auto';
                                e.target.removeAttribute('height');
                            }
                        });

                        editor.on('init', function () {
                            editor.setContent(_msgWrapVars(editor.getContent()));
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
                return this.editor ? _msgStripVarSpans(this.editor.getContent()) : '';
            },

            setContent(html) {
                if (this.editor) {
                    this.editor.setContent(_msgWrapVars(html));
                }
            },
        }));
    }

    // --- Helper: get Alpine data from element ---
    function _msgGetAlpineData(el) {
        if (!el) return null;
        if (el._x_dataStack && el._x_dataStack[0]) return el._x_dataStack[0];
        if (el.__x && el.__x.$data) return el.__x.$data;
        return null;
    }

    // --- Helper: find Livewire component ---
    function _msgGetWire() {
        const el = document.getElementById('operation-communication');
        if (!el) return null;
        const wireId = el.closest('[wire\\:id]')?.getAttribute('wire:id') || el.getAttribute('wire:id');
        return wireId ? Livewire.find(wireId) : null;
    }

    // --- Insert element: call Livewire, inject HTML into TinyMCE ---
    let _insertElementCache = null;
    function insertElement(editor, key) {
        if (_insertElementCache) {
            const html = _insertElementCache[key];
            if (html) editor.insertContent(html);
            return;
        }
        const wire = _msgGetWire();
        if (!wire) return;
        wire.call('getInsertableElements').then(function (elements) {
            _insertElementCache = elements;
            // Invalidate cache after 30s (data may change)
            setTimeout(function () { _insertElementCache = null; }, 30000);
            const html = elements[key];
            if (html) editor.insertContent(html);
        });
    }

    // --- Sync TinyMCE -> Livewire ---
    function _msgSyncEditor() {
        const el = document.querySelector('[x-data="messageTinymce()"]');
        const data = _msgGetAlpineData(el);
        const wire = _msgGetWire();
        if (data && data.getContent && wire) {
            wire.set('corps', data.getContent());
        }
    }

    window.syncMessageEditor = _msgSyncEditor;
    window.syncAndSaveTemplate = function() { _msgSyncEditor(); setTimeout(() => { const w = _msgGetWire(); if (w) w.saveAsTemplate(); }, 150); };
    window.syncAndUpdateTemplate = function() { _msgSyncEditor(); setTimeout(() => { const w = _msgGetWire(); if (w) w.updateTemplate(); }, 150); };
    window.syncAndShowTestModal = function() { _msgSyncEditor(); setTimeout(() => { const w = _msgGetWire(); if (w) w.set('showTestModal', true); }, 150); };
    window.syncAndShowConfirmSend = function() { _msgSyncEditor(); setTimeout(() => { const w = _msgGetWire(); if (w) w.set('showConfirmSend', true); }, 150); };
    window.syncAndShowPreview = function() { _msgSyncEditor(); setTimeout(() => { const w = _msgGetWire(); if (w) w.set('showPreview', true); }, 150); };
</script>
@endassets

@script
<script>
    $wire.on('template-loaded', (eventData) => {
        const el = document.querySelector('[x-data="messageTinymce()"]');
        const data = _msgGetAlpineData(el);
        if (data && data.setContent) {
            const corps = Array.isArray(eventData) ? eventData[0].corps : eventData.corps;
            data.setContent(corps);
        }
    });
</script>
@endscript
