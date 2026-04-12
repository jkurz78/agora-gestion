{{-- resources/views/livewire/communication-tiers.blade.php --}}
<div id="tiers-communication">
    @if (session('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Email-from warning --}}
    @if(! $emailFrom)
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Adresse d'expédition non configurée.
            <a href="{{ route('parametres.association') }}">Configurer dans les paramètres</a>
        </div>
    @endif

    <div class="row g-3">
        {{-- ── Panneau de filtres ── --}}
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-header py-2" style="background:#3d5473;color:#fff;font-size:.85rem;font-weight:600;">
                    <i class="bi bi-funnel me-1"></i> Filtres
                </div>
                <div class="card-body p-3">

                    {{-- Recherche texte --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Recherche</label>
                        <input type="text"
                               wire:model.live.debounce.300ms="search"
                               class="form-control form-control-sm"
                               placeholder="Nom, email…">
                    </div>

                    {{-- Mode ET / OU --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Combinaison des filtres</label>
                        <div class="btn-group btn-group-sm w-100" role="group">
                            <input type="radio" class="btn-check" wire:model.live="modeFiltres" value="et" id="mode-et">
                            <label class="btn btn-outline-secondary" for="mode-et">ET</label>
                            <input type="radio" class="btn-check" wire:model.live="modeFiltres" value="ou" id="mode-ou">
                            <label class="btn btn-outline-secondary" for="mode-ou">OU</label>
                        </div>
                    </div>

                    <hr class="my-2">

                    {{-- Fournisseurs --}}
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox"
                               wire:model.live="filtreFournisseurs" id="filtreFourn">
                        <label class="form-check-label small" for="filtreFourn">Fournisseurs</label>
                    </div>

                    {{-- Clients --}}
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox"
                               wire:model.live="filtreClients" id="filtreClients">
                        <label class="form-check-label small" for="filtreClients">Clients / payeurs</label>
                    </div>

                    <hr class="my-2">

                    {{-- Donateurs --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Donateurs</label>
                        <select wire:model.live="filtreDonateurs" class="form-select form-select-sm">
                            <option value="">Non filtré</option>
                            <option value="exercice">Exercice en cours</option>
                            <option value="tous">Tous exercices</option>
                        </select>
                    </div>

                    {{-- Adhérents --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Adhérents</label>
                        <select wire:model.live="filtreAdherents" class="form-select form-select-sm">
                            <option value="">Non filtré</option>
                            <option value="exercice">Exercice en cours</option>
                            <option value="tous">Tous exercices</option>
                        </select>
                    </div>

                    <hr class="my-2">

                    {{-- Participants --}}
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Participants</label>
                        <select wire:model.live="filtreParticipantsScope" class="form-select form-select-sm mb-2">
                            <option value="">Non filtré</option>
                            <option value="exercice">Exercice en cours</option>
                            <option value="tous">Tous exercices</option>
                        </select>

                        @if ($filtreParticipantsScope !== null)
                            <label class="form-label small text-muted mb-1">Types d'opération</label>
                            @foreach ($typesOperation as $typeOp)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           wire:model.live="filtreTypeOperationIds"
                                           value="{{ $typeOp->id }}"
                                           id="typeop-{{ $typeOp->id }}">
                                    <label class="form-check-label small" for="typeop-{{ $typeOp->id }}">
                                        {{ $typeOp->nom }}
                                    </label>
                                </div>
                            @endforeach
                        @endif
                    </div>

                </div>
            </div>
        </div>

        {{-- ── Liste des tiers ── --}}
        <div class="col-md-9">

            {{-- Barre de comptage + actions --}}
            <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
                <span class="small text-muted">
                    <strong>{{ $tiersList->count() }}</strong> tiers filtrés —
                    <strong>{{ $emailCount }}</strong> avec email valide
                </span>

                <div class="ms-auto d-flex gap-2 align-items-center">
                    @if (count($selectedTiersIds) > 0)
                        <span class="badge bg-primary">{{ count($selectedTiersIds) }} sélectionné(s)</span>
                    @endif

                    <button class="btn btn-sm btn-outline-secondary"
                            wire:click="toggleSelectAll">
                        @if ($selectAll)
                            <i class="bi bi-square me-1"></i> Désélectionner tout
                        @else
                            <i class="bi bi-check-all me-1"></i> Tout sélectionner
                        @endif
                    </button>
                </div>
            </div>

            {{-- Tableau --}}
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle" style="font-size:.82rem;">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" class="form-check-input"
                                       wire:model.live="selectAll"
                                       wire:click="toggleSelectAll">
                            </th>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Email</th>
                            <th>Ville</th>
                            <th class="text-center">Dép.</th>
                            <th class="text-center">Rec.</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tiersList as $tiers)
                            @php
                                $hasEmail = ! empty($tiers->getRawOriginal('email'));
                                $optout   = $tiers->email_optout;
                                $canSelect = $hasEmail && ! $optout;
                                $rowClass  = $optout ? 'text-muted' : '';
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td>
                                    @if ($canSelect)
                                        <input type="checkbox" class="form-check-input"
                                               wire:model.live="selectedTiersIds"
                                               value="{{ $tiers->id }}">
                                    @else
                                        <input type="checkbox" class="form-check-input" disabled>
                                    @endif
                                </td>
                                <td>
                                    @if ($tiers->type === 'entreprise')
                                        <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                                        {{ $tiers->entreprise }}
                                    @else
                                        {{ $tiers->nom }}
                                    @endif
                                </td>
                                <td>{{ $tiers->prenom ?? '—' }}</td>
                                <td>
                                    @if ($optout)
                                        <span class="badge bg-secondary">Désinscrit</span>
                                        @if ($hasEmail)
                                            <span class="text-muted ms-1" style="font-size:.78rem;">
                                                {{ $tiers->getRawOriginal('email') }}
                                            </span>
                                        @endif
                                    @elseif (! $hasEmail)
                                        <span class="badge bg-warning text-dark">Pas d'email</span>
                                    @else
                                        {{ $tiers->getRawOriginal('email') }}
                                    @endif
                                </td>
                                <td>{{ $tiers->ville ?? '—' }}</td>
                                <td class="text-center">
                                    @if ($tiers->pour_depenses)
                                        <i class="bi bi-check-lg text-success"></i>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($tiers->pour_recettes)
                                        <i class="bi bi-check-lg text-success"></i>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('tiers.transactions', $tiers->id) }}"
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Voir les transactions">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    Aucun tiers ne correspond aux filtres.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    {{-- ── Section composition ── --}}
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header py-2" style="background:#3d5473;color:#fff;font-size:.85rem;font-weight:600;">
                    <i class="bi bi-envelope-paper me-1"></i> Composer un message
                </div>
                <div class="card-body">

                    {{-- Template selector --}}
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Partir d'un modèle…</label>
                            <select class="form-select form-select-sm" wire:model="selectedTemplateId" wire:change="loadTemplate">
                                <option value="">— Composition libre —</option>
                                @foreach($templates as $tpl)
                                    <option value="{{ $tpl->id }}">{{ $tpl->nom }}</option>
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
                                            'Tiers' => ['{prenom}' => 'Prénom', '{nom}' => 'Nom', '{email}' => 'Email'],
                                            'Association' => ['{association}' => "Nom de l'association", '{lien_desinscription}' => 'Lien de désinscription'],
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
                                                           const input = document.getElementById('tiers-objet-input');
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
                        <input type="text" class="form-control form-control-sm" wire:model.live.debounce.300ms="objet"
                               placeholder="Objet du message" id="tiers-objet-input">
                    </div>

                    {{-- Body — TinyMCE --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Corps</label>
                        <div wire:ignore
                             x-data="tiersCommunicationTinymce()"
                             x-init="init()">
                            <textarea x-ref="editor">{!! $corps !!}</textarea>
                        </div>
                    </div>

                    {{-- Save as template --}}
                    <div class="mb-3">
                        @if($showSaveTemplate)
                            <div class="border rounded p-2 bg-light">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-7">
                                        <label class="form-label small">Nom du modèle</label>
                                        <input type="text" class="form-control form-control-sm @error('templateNom') is-invalid @enderror"
                                               wire:model="templateNom" placeholder="Ex: Newsletter mensuelle">
                                        @error('templateNom')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-5 d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-primary"
                                                onclick="window.tiersSyncAndSaveTemplate()">
                                            <i class="bi bi-check-lg"></i> Enregistrer
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                wire:click="$set('showSaveTemplate', false)">
                                            Annuler
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        wire:click="$set('showSaveTemplate', true)">
                                    <i class="bi bi-bookmark-plus me-1"></i>Enregistrer comme modèle
                                </button>
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

                    {{-- Action buttons --}}
                    @php
                        $canSend = count($selectedTiersIds) > 0 && $objet !== '' && $corps !== '';
                    @endphp
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                onclick="window.tiersSyncAndShowPreview()"
                                {{ $objet === '' ? 'disabled' : '' }}>
                            <i class="bi bi-eye me-1"></i>Aperçu
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick="window.tiersSyncAndShowTestModal()"
                                {{ count($selectedTiersIds) === 0 ? 'disabled' : '' }}>
                            <i class="bi bi-send me-1"></i>Envoyer un test
                        </button>
                        <button type="button" class="btn btn-sm btn-primary"
                                onclick="window.tiersSyncAndShowConfirmSend()"
                                {{ ! $canSend ? 'disabled' : '' }}>
                            <i class="bi bi-envelope-paper me-1"></i>Envoyer à {{ count($selectedTiersIds) }} tiers
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </div>

    {{-- ── Historique des campagnes ── --}}
    @if ($campagnes->isNotEmpty())
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header py-2" style="background:#3d5473;color:#fff;font-size:.85rem;font-weight:600;">
                    <i class="bi bi-clock-history me-1"></i> Historique des campagnes
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <th>Date</th>
                                <th>Objet</th>
                                <th class="text-end">Dest.</th>
                                <th class="text-end">Erreurs</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($campagnes as $campagne)
                            <tr>
                                <td class="text-nowrap">{{ $campagne->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $campagne->objet }}</td>
                                <td class="text-end">{{ $campagne->nb_destinataires }}</td>
                                <td class="text-end">
                                    @if ($campagne->nb_erreurs > 0)
                                        <span class="badge bg-danger">{{ $campagne->nb_erreurs }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary"
                                            wire:click="reutiliserCampagne({{ $campagne->id }})"
                                            title="Réutiliser ce modèle">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary"
                                            wire:click="toggleCampagne({{ $campagne->id }})"
                                            title="Voir les détails">
                                        <i class="bi bi-chevron-{{ $expandedCampagneId === $campagne->id ? 'up' : 'down' }}"></i>
                                    </button>
                                </td>
                            </tr>
                            @if ($expandedCampagneId === $campagne->id)
                            <tr>
                                <td colspan="5" class="bg-light">
                                    <div class="p-2">
                                        <strong>Corps :</strong>
                                        <div class="border rounded p-2 mt-1 bg-white" style="font-size:.85rem;max-height:200px;overflow:auto;">
                                            {!! $campagne->corps !!}
                                        </div>
                                        @if (is_array($campagne->pieces_jointes) && count($campagne->pieces_jointes))
                                        <div class="mt-2">
                                            <strong>Pièces jointes :</strong>
                                            @foreach ($campagne->pieces_jointes as $idx => $pj)
                                            <button class="btn btn-sm btn-link p-0 ms-2"
                                                    wire:click="telechargerPieceJointe({{ $campagne->id }}, {{ $idx }})">
                                                <i class="bi bi-paperclip"></i> {{ $pj['nom'] }}
                                            </button>
                                            @endforeach
                                        </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Test email modal ── --}}
    @if($showTestModal)
    <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
         style="background:rgba(0,0,0,.3);z-index:2100"
         wire:click.self="$set('showTestModal', false)">
        <div class="bg-white rounded-3 shadow p-4" style="max-width:400px;width:100%">
            <h6 class="mb-3"><i class="bi bi-envelope me-1"></i> Envoyer un email de test</h6>
            <p class="small text-muted mb-2">
                Variables substituées pour le 1er tiers sélectionné.
            </p>
            <div class="mb-3">
                <label class="form-label small">Adresse destinataire</label>
                <input type="email" wire:model="testEmail"
                       class="form-control form-control-sm @error('testEmail') is-invalid @enderror"
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

    {{-- ── Confirm send modal ── --}}
    @if($showConfirmSend)
    <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
         style="background:rgba(0,0,0,.3);z-index:2100"
         wire:click.self="$set('showConfirmSend', false)">
        <div class="bg-white rounded-3 shadow p-4" style="max-width:400px;width:100%">
            <h6 class="mb-3"><i class="bi bi-envelope-paper me-1"></i> Confirmer l'envoi</h6>
            <p class="mb-3">
                Envoyer ce message à <strong>{{ count($selectedTiersIds) }}</strong> tiers ?
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

    {{-- ── Preview modal ── --}}
    @if($showPreview)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2100"
             wire:click.self="$set('showPreview', false)">
            <div class="bg-white rounded-3 shadow" style="max-width:700px;width:95%;max-height:80vh;display:flex;flex-direction:column">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-eye me-1"></i> Aperçu du message</h6>
                    <button type="button" class="btn-close" wire:click="$set('showPreview', false)"></button>
                </div>
                <div class="p-3 border-bottom bg-light">
                    <strong class="small">Objet :</strong> {{ $objet }}
                </div>
                <div class="p-3" style="overflow-y:auto;flex:1">
                    {!! $corps !!}
                </div>
            </div>
        </div>
    @endif

</div>

@assets
<script>
    // --- Alpine component for TinyMCE (registered once, before Alpine init) ---
    function _tiersMsgStripVarSpans(html) {
        return html.replace(/<span class="mce-variable[^"]*">(\{[^}]+\})<\/span>/g, '$1');
    }

    window._tiersMsgVariableGroups = [
        {
            title: 'Tiers',
            items: [
                { token: '{prenom}', label: 'Prénom' },
                { token: '{nom}', label: 'Nom' },
                { token: '{email}', label: 'Email' },
            ]
        },
        {
            title: 'Association',
            items: [
                { token: '{association}', label: "Nom de l'association" },
                { token: '{lien_optout}', label: 'URL désinscription (pour href)' },
                { token: '{lien_desinscription}', label: 'Lien désinscription cliquable' },
            ]
        },
    ];

    // Flat lookup for wrap/strip
    window._tiersMsgVariables = {};
    window._tiersMsgVariableGroups.forEach(function(group) {
        group.items.forEach(function(item) {
            window._tiersMsgVariables[item.token] = item.label;
        });
    });

    function _tiersMsgWrapVars(html) {
        Object.keys(window._tiersMsgVariables).forEach(function(v) {
            var escaped = v.replace(/[{}]/g, '\\$&');
            var regex = new RegExp('(?!<span[^>]*>)' + escaped + '(?!</span>)', 'g');
            html = html.replace(regex, '<span class="mce-variable mce-noneditable">' + v + '</span>');
        });
        return html;
    }

    // Register before Alpine scans x-data attributes
    function _tiersRegisterAlpineComponent() {
        Alpine.data('tiersCommunicationTinymce', () => ({
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

                // Build nested menu items from variable groups
                const menuItems = window._tiersMsgVariableGroups.map(function(group) {
                    return {
                        type: 'nestedmenuitem',
                        text: group.title,
                        getSubmenuItems: function() {
                            return group.items.map(function(item) {
                                return {
                                    type: 'menuitem',
                                    text: item.token + ' — ' + item.label,
                                    onAction: function() {
                                        if (self.editor) {
                                            self.editor.insertContent('<span class="mce-variable mce-noneditable">' + item.token + '</span>&nbsp;');
                                        }
                                    },
                                };
                            });
                        },
                    };
                });

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
                    automatic_uploads: false,
                    images_upload_handler: function(blobInfo) {
                        return new Promise(function(resolve) {
                            var reader = new FileReader();
                            reader.onload = function() { resolve(reader.result); };
                            reader.readAsDataURL(blobInfo.blob());
                        });
                    },
                    file_picker_types: 'image',
                    file_picker_callback: function(callback, value, meta) {
                        if (meta.filetype === 'image') {
                            var input = document.createElement('input');
                            input.setAttribute('type', 'file');
                            input.setAttribute('accept', 'image/*');
                            input.addEventListener('change', function() {
                                var file = this.files[0];
                                var reader = new FileReader();
                                reader.onload = function() {
                                    callback(reader.result, { alt: file.name, title: file.name });
                                };
                                reader.readAsDataURL(file);
                            });
                            input.click();
                        }
                    },
                    content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; } .mce-variable { background: #f3edff; border: 1px solid #d4c5f9; border-radius: 3px; padding: 1px 3px; font-family: monospace; font-size: 12px; color: #7c3aed; display: inline-block; } table { border-collapse: collapse; } td, th { border: 1px solid #ccc; padding: 6px; }',
                    setup: function(editor) {
                        self.editor = editor;

                        editor.ui.registry.addMenuButton('variablesButton', {
                            text: 'Variables',
                            fetch: function(callback) { callback(menuItems); },
                        });

                        // "Insérer" button — logo, lien opt-out
                        editor.ui.registry.addMenuButton('insertElementButton', {
                            text: 'Insérer',
                            icon: 'table-insert-row-after',
                            fetch: function(callback) {
                                callback([
                                    { type: 'menuitem', text: 'Logo association', onAction: function() { _tiersInsertElement(editor, 'logo'); } },
                                    { type: 'separator' },
                                    { type: 'menuitem', text: 'Bloc lien de désinscription', onAction: function() { _tiersInsertElement(editor, 'lien_optout'); } },
                                ]);
                            },
                        });

                        editor.on('init', function() {
                            editor.setContent(_tiersMsgWrapVars(editor.getContent()));
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
                return this.editor ? _tiersMsgStripVarSpans(this.editor.getContent()) : '';
            },

            setContent(html) {
                if (this.editor) {
                    this.editor.setContent(_tiersMsgWrapVars(html));
                }
            },
        }));
    }

    // Register: try immediately if Alpine is ready, otherwise defer to alpine:init
    let _tiersAlpineRegistered = false;
    function _tiersEnsureRegistered() {
        if (_tiersAlpineRegistered) return;
        _tiersAlpineRegistered = true;
        _tiersRegisterAlpineComponent();
    }
    if (typeof Alpine !== 'undefined') {
        _tiersEnsureRegistered();
    }
    document.addEventListener('alpine:init', _tiersEnsureRegistered, { once: true });

    // --- Helper: get Alpine data from element ---
    function _tiersMsgGetAlpineData(el) {
        if (!el) return null;
        if (el._x_dataStack && el._x_dataStack[0]) return el._x_dataStack[0];
        if (el.__x && el.__x.$data) return el.__x.$data;
        return null;
    }

    // --- Helper: find Livewire component ---
    function _tiersMsgGetWire() {
        // The root div#tiers-communication is the Livewire component root
        const el = document.getElementById('tiers-communication');
        if (!el) return null;
        // Try wire:id on the element itself, then walk up
        let wireEl = el;
        while (wireEl) {
            const wireId = wireEl.getAttribute('wire:id');
            if (wireId) return Livewire.find(wireId);
            wireEl = wireEl.parentElement;
        }
        return null;
    }

    // --- Sync TinyMCE -> Livewire ---
    function _tiersMsgSyncEditor() {
        const el = document.querySelector('[x-data="tiersCommunicationTinymce()"]');
        const data = _tiersMsgGetAlpineData(el);
        const wire = _tiersMsgGetWire();
        if (data && data.getContent && wire) {
            wire.set('corps', data.getContent());
        }
    }

    // --- Insert element: call Livewire, inject HTML into TinyMCE ---
    let _tiersInsertElementCache = null;
    function _tiersInsertElement(editor, key) {
        if (_tiersInsertElementCache) {
            const html = _tiersInsertElementCache[key];
            if (html) editor.insertContent(html);
            return;
        }
        const wire = _tiersMsgGetWire();
        if (!wire) return;
        wire.call('getInsertableElements').then(function(elements) {
            _tiersInsertElementCache = elements;
            setTimeout(function() { _tiersInsertElementCache = null; }, 30000);
            const html = elements[key];
            if (html) editor.insertContent(html);
        });
    }

    window.tiersSyncMessageEditor = _tiersMsgSyncEditor;

    // Sync TinyMCE content into Livewire then trigger action — batched in a single request
    function _tiersSyncAndDo(action) {
        const el = document.querySelector('[x-data="tiersCommunicationTinymce()"]');
        const data = _tiersMsgGetAlpineData(el);
        const wire = _tiersMsgGetWire();
        if (!wire) {
            console.error('[CommunicationTiers] Livewire component not found');
            return;
        }
        if (data && data.getContent) {
            const content = data.getContent();
            wire.set('corps', content);
        }
        action(wire);
    }

    window.tiersSyncAndSaveTemplate = function() { _tiersSyncAndDo(w => w.call('saveAsTemplate')); };
    window.tiersSyncAndShowTestModal = function() { _tiersSyncAndDo(w => w.set('showTestModal', true)); };
    window.tiersSyncAndShowConfirmSend = function() { _tiersSyncAndDo(w => w.set('showConfirmSend', true)); };
    window.tiersSyncAndShowPreview = function() { _tiersSyncAndDo(w => w.set('showPreview', true)); };
</script>
@endassets

@script
<script>
    $wire.on('template-loaded', (eventData) => {
        const el = document.querySelector('[x-data="tiersCommunicationTinymce()"]');
        const data = _tiersMsgGetAlpineData(el);
        if (data && data.setContent) {
            const corps = Array.isArray(eventData) ? eventData[0].corps : eventData.corps;
            data.setContent(corps || '');
        }
    });
</script>
@endscript
