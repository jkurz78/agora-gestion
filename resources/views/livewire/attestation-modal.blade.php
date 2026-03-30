<div>
    {{-- Seance Modal --}}
    @if($showModal && $mode === 'seance')
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000" wire:click.self="$set('showModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:550px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h6 class="fw-bold mb-3">{{ $modalTitle }}</h6>

                @if(!$hasCachet)
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i> Cachet et signature non configuré dans les paramètres.
                    </div>
                @endif

                @if(count($presentParticipants) === 0)
                    <p class="text-muted text-center py-3">Aucun participant présent à cette séance.</p>
                @else
                    <div class="list-group list-group-flush mb-3">
                        @foreach($presentParticipants as $index => $p)
                            <label class="list-group-item d-flex align-items-center gap-2 {{ !$p['email'] ? 'text-muted' : '' }}">
                                <input type="checkbox" class="form-check-input"
                                       wire:click="toggleParticipant({{ $p['id'] }})"
                                       {{ $p['checked'] ? 'checked' : '' }}>
                                <span>{{ $p['nom'] }} {{ $p['prenom'] }}</span>
                                @if(!$p['email'])
                                    <small class="text-muted ms-auto fst-italic">pas d'email</small>
                                @else
                                    <small class="text-muted ms-auto">{{ $p['email'] }}</small>
                                @endif
                            </label>
                        @endforeach
                    </div>
                @endif

                @if($resultMessage)
                    <div class="alert alert-{{ $resultType }} py-2 small mb-3">{{ $resultMessage }}</div>
                @endif

                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Fermer</button>
                    @if(count($presentParticipants) > 0)
                        <button class="btn btn-sm btn-outline-primary"
                                onclick="
                                    var ids = @js(collect($presentParticipants)->where('checked', true)->pluck('id')->values());
                                    if (!ids.length) { alert('Aucun participant sélectionné.'); return; }
                                    window.open('{{ route('gestion.operations.seances.attestation-pdf', [$operation, $seanceId]) }}' + '?participants=' + ids.join(','), '_blank');
                                ">
                            <i class="bi bi-download me-1"></i> Télécharger PDF
                        </button>
                        <button class="btn btn-sm btn-primary" wire:click="envoyerParEmail"
                                {{ !$hasEmailFrom ? 'disabled' : '' }}
                                @if(!$hasEmailFrom) title="Email expéditeur non configuré sur le type d'opération" @endif>
                            <i class="bi bi-envelope me-1"></i> Envoyer par email
                            <span wire:loading wire:target="envoyerParEmail" class="spinner-border spinner-border-sm ms-1"></span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Recap Modal --}}
    @if($showModal && $mode === 'recap')
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000" wire:click.self="$set('showModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:550px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h6 class="fw-bold mb-3">{{ $modalTitle }}</h6>

                @if(!$hasCachet)
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i> Cachet et signature non configuré dans les paramètres.
                    </div>
                @endif

                @if(count($seancesPresent) === 0)
                    <p class="text-muted text-center py-3">Aucune présence enregistrée.</p>
                @else
                    <table class="table table-sm small mb-3">
                        <thead><tr><th>Séance</th><th>Date</th><th>Titre</th></tr></thead>
                        <tbody>
                            @foreach($seancesPresent as $s)
                                <tr><td>{{ $s['numero'] }}</td><td>{{ $s['date'] }}</td><td>{{ $s['titre'] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="small text-muted mb-3">{{ count($seancesPresent) }} séance(s) sur {{ $totalSeances }}</p>
                @endif

                @if(!$participantEmail)
                    <div class="alert alert-secondary py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i> Ce participant n'a pas d'adresse email.
                    </div>
                @endif

                @if($resultMessage)
                    <div class="alert alert-{{ $resultType }} py-2 small mb-3">{{ $resultMessage }}</div>
                @endif

                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Fermer</button>
                    @if(count($seancesPresent) > 0)
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('gestion.operations.participants.attestation-recap-pdf', [$operation, $participantId]) }}"
                           target="_blank">
                            <i class="bi bi-download me-1"></i> Télécharger PDF
                        </a>
                        <button class="btn btn-sm btn-primary" wire:click="envoyerParEmail"
                                {{ !$hasEmailFrom || !$participantEmail ? 'disabled' : '' }}>
                            <i class="bi bi-envelope me-1"></i> Envoyer par email
                            <span wire:loading wire:target="envoyerParEmail" class="spinner-border spinner-border-sm ms-1"></span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
