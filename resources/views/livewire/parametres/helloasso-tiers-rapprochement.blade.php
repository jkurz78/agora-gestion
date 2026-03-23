<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-people me-1"></i> Rapprochement des tiers HelloAsso</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label">Exercice</label>
                    <select wire:model="exercice" class="form-select form-select-sm">
                        @foreach($exercices as $ex)
                            <option value="{{ $ex }}">{{ $ex }}/{{ $ex + 1 }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button wire:click="fetchTiers" class="btn btn-sm btn-primary" wire:loading.attr="disabled">
                        <span wire:loading wire:target="fetchTiers" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="bi bi-cloud-download me-1" wire:loading.remove wire:target="fetchTiers"></i>
                        Récupérer les tiers HelloAsso
                    </button>
                </div>
            </div>

            @if($erreur)
                <div class="alert alert-danger">{{ $erreur }}</div>
            @endif

            @if($fetched)
                {{-- Summary --}}
                <div class="alert alert-info">
                    <strong>{{ count($linked) }}</strong> tiers déjà liés,
                    <strong>{{ count($unlinked) }}</strong> tiers à rapprocher
                </div>

                {{-- Unlinked persons --}}
                @if(count($unlinked) > 0)
                    <h6 class="mt-3">Tiers à rapprocher</h6>
                    <table class="table table-sm table-hover">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th>Personne HelloAsso</th>
                                <th>Email</th>
                                <th>Correspondance suggérée</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unlinked as $person)
                                <tr wire:key="unlinked-{{ $person['email'] }}">
                                    <td class="small">{{ $person['firstName'] }} {{ $person['lastName'] }}</td>
                                    <td class="small text-muted">{{ $person['email'] }}</td>
                                    <td>
                                        @if(count($person['suggestions']) > 0)
                                            @foreach($person['suggestions'] as $sug)
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <span class="badge text-bg-{{ $sug['match_type'] === 'email' ? 'success' : 'warning' }}">
                                                        {{ $sug['match_type'] === 'email' ? 'Email' : 'Nom' }}
                                                    </span>
                                                    <span class="small">{{ $sug['tiers_name'] }}</span>
                                                    <button wire:click="associer('{{ $person['email'] }}', {{ $sug['tiers_id'] }})"
                                                            class="btn btn-sm btn-outline-success py-0 px-1">
                                                        <i class="bi bi-link-45deg"></i> Associer
                                                    </button>
                                                </div>
                                            @endforeach
                                        @else
                                            <span class="text-muted small">Aucune correspondance</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <div class="d-flex gap-1">
                                                <button wire:click="creer('{{ $person['email'] }}')"
                                                        class="btn btn-sm btn-outline-primary py-0 px-1"
                                                        title="Créer un nouveau tiers">
                                                    <i class="bi bi-person-plus"></i> Créer
                                                </button>
                                                <button wire:click="ignorer('{{ $person['email'] }}')"
                                                        class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                        title="Ignorer pour cette session">
                                                    <i class="bi bi-x-lg"></i> Ignorer
                                                </button>
                                            </div>
                                            {{-- Recherche tiers existant --}}
                                            <div class="input-group input-group-sm mt-1">
                                                <input type="text"
                                                       wire:model.defer="recherche.{{ $person['email'] }}"
                                                       wire:keydown.enter="rechercherTiers('{{ $person['email'] }}')"
                                                       class="form-control form-control-sm"
                                                       placeholder="Chercher un tiers existant…">
                                                <button wire:click="rechercherTiers('{{ $person['email'] }}')"
                                                        class="btn btn-outline-secondary btn-sm" type="button">
                                                    <i class="bi bi-search"></i>
                                                </button>
                                            </div>
                                            @if(!empty($resultatsRecherche[$person['email']]))
                                                <div class="list-group list-group-flush small">
                                                    @foreach($resultatsRecherche[$person['email']] as $res)
                                                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1 px-2">
                                                            <span>{{ $res['name'] }}</span>
                                                            <button wire:click="associer('{{ $person['email'] }}', {{ $res['id'] }})"
                                                                    class="btn btn-sm btn-outline-success py-0 px-1">
                                                                <i class="bi bi-link-45deg"></i>
                                                            </button>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif(count($linked) > 0)
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-1"></i>
                        Tous les tiers HelloAsso sont rapprochés.
                    </div>
                @else
                    <div class="alert alert-warning">
                        Aucune commande trouvée sur cet exercice.
                    </div>
                @endif

                {{-- Linked persons --}}
                @if(count($linked) > 0)
                    <details class="mt-3">
                        <summary class="fw-semibold small text-muted">{{ count($linked) }} tiers déjà liés</summary>
                        <table class="table table-sm mt-2">
                            <thead>
                                <tr>
                                    <th class="small">Personne HelloAsso</th>
                                    <th class="small">Tiers SVS</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($linked as $l)
                                    <tr>
                                        <td class="small">{{ $l['firstName'] }} {{ $l['lastName'] }} ({{ $l['email'] }})</td>
                                        <td class="small">{{ $l['tiers_name'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            @endif
        </div>
    </div>
</div>
