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

            {{-- Body (plain textarea — TinyMCE will replace in a later step) --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Corps</label>
                <textarea class="form-control" rows="6" wire:model="corps" placeholder="Corps du message..."></textarea>
                <div class="form-text small mt-1">
                    Variables :
                    @foreach($messageVariables as $var => $desc)
                        <code title="{{ $desc }}">{{ $var }}</code>
                    @endforeach
                </div>
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
