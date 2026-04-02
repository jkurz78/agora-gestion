<div>
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">Gestion des operations</h1>
        <button class="btn btn-sm text-white" style="background-color:#A9014F"
                wire:click="openCreateModal">
            <i class="bi bi-plus-lg me-1"></i> Nouvelle operation
        </button>
    </div>

    {{-- Filters --}}
    <div class="d-flex align-items-center gap-3 mb-3">
        <div>
            <select class="form-select form-select-sm" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $exerciceService->label($year) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <select class="form-select form-select-sm" wire:model.live="filterTypeId">
                <option value="">Tous les types</option>
                @foreach($typeOperations as $type)
                    <option value="{{ $type->id }}">{{ $type->nom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <select class="form-select form-select-sm" wire:model.live="filterPeriode">
                <option value="">Toutes les périodes</option>
                <option value="futur">À venir</option>
                <option value="en_cours">En cours</option>
                <option value="termine">Terminées</option>
            </select>
        </div>
        <div class="text-muted small">
            {{ $operations->count() }} operation{{ $operations->count() > 1 ? 's' : '' }}
        </div>
    </div>

    {{-- Table --}}
    @if($operations->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
            Aucune operation pour cet exercice
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Type</th>
                        <th>Operation</th>
                        <th>Periode</th>
                        <th class="text-center">Participants</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($operations as $op)
                        @php
                            $now = \Carbon\Carbon::now();
                            $debut = $op->date_debut;
                            $fin = $op->date_fin;
                            $contextLabel = null;
                            $contextColor = '#6c757d';

                            if ($debut && $fin) {
                                if ($now->lt($debut)) {
                                    $days = (int) $now->diffInDays($debut);
                                    $contextLabel = "Debute dans {$days} jour" . ($days > 1 ? 's' : '');
                                    $contextColor = '#198754';
                                } elseif ($now->lte($fin)) {
                                    $days = (int) $debut->diffInDays($now);
                                    $contextLabel = "En cours depuis {$days} jour" . ($days > 1 ? 's' : '');
                                    $contextColor = '#0d6efd';
                                } else {
                                    $daysSinceEnd = (int) $fin->diffInDays($now);
                                    if ($daysSinceEnd > 60) {
                                        $months = (int) $fin->diffInMonths($now);
                                        $contextLabel = "Terminee depuis {$months} mois";
                                    } else {
                                        $contextLabel = "Terminee depuis {$daysSinceEnd} jour" . ($daysSinceEnd > 1 ? 's' : '');
                                    }
                                }
                            }

                            // Badge colors by sous-categorie
                            $sousCatNom = $op->typeOperation?->sousCategorie?->nom ?? '';
                            if (str_contains($sousCatNom, 'Parcours')) {
                                $badgeBg = '#e8f0fe';
                                $badgeText = '#1a56db';
                            } elseif (str_contains($sousCatNom, 'Formation')) {
                                $badgeBg = '#fce8f0';
                                $badgeText = '#A9014F';
                            } else {
                                $badgeBg = '#f0f0f0';
                                $badgeText = '#555';
                            }

                            $isCloturee = $op->statut === \App\Enums\StatutOperation::Cloturee;
                        @endphp
                        <tr style="cursor:pointer;{{ $isCloturee ? 'opacity:0.5;' : '' }}"
                            onclick="window.location='{{ route('gestion.operations.show', $op) }}'">
                            <td data-sort="{{ $op->typeOperation?->nom ?? '' }}">
                                <span class="badge rounded-pill px-2 py-1 small"
                                      style="background-color:{{ $badgeBg }};color:{{ $badgeText }}">
                                    {{ $op->typeOperation?->nom ?? 'Sans type' }}
                                </span>
                            </td>
                            <td data-sort="{{ $op->nom }}">
                                <span class="fw-semibold">{{ $op->nom }}</span>
                            </td>
                            <td data-sort="{{ $debut?->format('Y-m-d') ?? '' }}">
                                @if($contextLabel)
                                    <small style="color:{{ $contextColor }}">{{ $contextLabel }}</small><br>
                                @endif
                                <small class="text-muted">
                                    @if($debut && $fin)
                                        {{ $debut->format('d/m/Y') }} &rarr; {{ $fin->format('d/m/Y') }}
                                    @else
                                        &mdash;
                                    @endif
                                </small>
                            </td>
                            <td class="text-center" data-sort="{{ $op->participants_count }}">
                                @if($op->participants_count > 0)
                                    <span class="badge bg-secondary rounded-pill">{{ $op->participants_count }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click.stop="openEditModal({{ $op->id }})"
                                        title="Modifier l'opération">
                                    <i class="bi bi-gear"></i> Modifier
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Create Modal --}}
    @if($showCreateModal)
        @include('livewire.partials.operation-form-modal', ['title' => 'Nouvelle operation'])
    @endif

    {{-- Edit Modal --}}
    @if($showEditModal)
        @include('livewire.partials.operation-form-modal', ['title' => 'Modifier l\'operation'])
    @endif
</div>
