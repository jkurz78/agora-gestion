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
                <div class="alert alert-info">
                    <strong>{{ $linkedCount }}</strong> tiers déjà liés,
                    <strong>{{ $unlinkedCount }}</strong> tiers à rapprocher
                </div>

                @if(count($persons) > 0)
                    <table class="table table-sm table-hover">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th>Personne HelloAsso</th>
                                <th>Email</th>
                                <th>Correspond à</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($persons as $person)
                                <tr wire:key="person-{{ $person['email'] }}">
                                    <td class="small">{{ $person['firstName'] }} {{ $person['lastName'] }}</td>
                                    <td class="small text-muted">{{ $person['email'] }}</td>
                                    <td>
                                        @if($person['tiers_id'])
                                            <span class="badge text-bg-success"><i class="bi bi-check-lg me-1"></i>{{ $person['tiers_name'] }}</span>
                                        @else
                                            <livewire:tiers-autocomplete
                                                wire:model.live="selectedTiers.{{ $person['email'] }}"
                                                filtre="recettes"
                                                :key="'rapprochement-'.$person['email']"
                                            />
                                        @endif
                                    </td>
                                    <td>
                                        @if(!$person['tiers_id'])
                                            <div class="d-flex flex-column gap-1">
                                                @if(!empty($selectedTiers[$person['email']]))
                                                    <button wire:click="associer('{{ $person['email'] }}')"
                                                            class="btn btn-sm btn-outline-success py-0 px-2">
                                                        <i class="bi bi-link-45deg me-1"></i>Associer
                                                    </button>
                                                @endif
                                                <button wire:click="creer('{{ $person['email'] }}')"
                                                        class="btn btn-sm btn-outline-primary py-0 px-2"
                                                        title="Créer un nouveau tiers à partir des données HelloAsso">
                                                    <i class="bi bi-person-plus me-1"></i>Ajouter depuis HelloAsso
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="alert alert-warning">
                        Aucune commande trouvée sur cet exercice.
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
