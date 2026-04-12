<div>
    {{-- Filters + action --}}
    <div class="d-flex align-items-center gap-3 mb-3">
        <div>
            <select class="form-select form-select-sm" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $exerciceService->label($year) }}</option>
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
            {{ $operations->count() }} opération{{ $operations->count() > 1 ? 's' : '' }}
        </div>
        @if($this->canEdit)
            <button class="btn btn-sm btn-primary ms-auto"
                    wire:click="openCreateModal">
                <i class="bi bi-plus-lg me-1"></i> Nouvelle opération
            </button>
        @endif
    </div>

    {{-- Vue hiérarchique --}}
    @if($operations->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
            Aucune opération pour cet exercice
        </div>
    @else
        @foreach($grouped as $sousCatNom => $typeGroups)
            @php
                // Badge couleur par sous-catégorie
                if (str_contains($sousCatNom, 'Parcours')) {
                    $catBg = '#e8f0fe'; $catText = '#1a56db';
                } elseif (str_contains($sousCatNom, 'Formation')) {
                    $catBg = '#fce8f0'; $catText = '#A9014F';
                } else {
                    $catBg = '#f0f0f0'; $catText = '#555';
                }
                $catCount = $typeGroups->flatten()->count();
            @endphp

            {{-- Niveau 1 : sous-catégorie --}}
            <div class="mb-4">
                <div class="d-flex align-items-center gap-2 mb-2 pb-1" style="border-bottom: 2px solid {{ $catBg }};">
                    <span class="badge rounded-pill px-2 py-1" style="background-color:{{ $catBg }}; color:{{ $catText }}; font-size: .8rem;">
                        {{ $sousCatNom }}
                    </span>
                    <span class="text-muted small">{{ $catCount }} opération{{ $catCount > 1 ? 's' : '' }}</span>
                </div>

                @foreach($typeGroups as $typeNom => $ops)
                    {{-- Niveau 2 : type d'opération --}}
                    <div class="ms-3 mb-3">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <i class="bi bi-collection text-muted" style="font-size: .75rem;"></i>
                            <span class="fw-semibold text-dark" style="font-size: .85rem;">{{ $typeNom }}</span>
                            <span class="text-muted small">({{ $ops->count() }})</span>
                        </div>

                        {{-- Niveau 3 : opérations --}}
                        <div class="ms-3">
                            @foreach($ops as $op)
                                @php
                                    $now = \Carbon\Carbon::now();
                                    $debut = $op->date_debut;
                                    $fin = $op->date_fin;
                                    $contextLabel = null;
                                    $contextColor = '#6c757d';

                                    if ($debut && $fin) {
                                        if ($now->lt($debut)) {
                                            $days = (int) $now->diffInDays($debut);
                                            $contextLabel = "Dans {$days} j";
                                            $contextColor = '#198754';
                                        } elseif ($now->lte($fin)) {
                                            $days = (int) $debut->diffInDays($now);
                                            $contextLabel = "En cours";
                                            $contextColor = '#0d6efd';
                                        } else {
                                            $daysSinceEnd = (int) $fin->diffInDays($now);
                                            $contextLabel = $daysSinceEnd > 60
                                                ? "Terminée il y a " . (int) $fin->diffInMonths($now) . " mois"
                                                : "Terminée il y a {$daysSinceEnd} j";
                                        }
                                    }

                                    $isCloturee = $op->statut === \App\Enums\StatutOperation::Cloturee;
                                @endphp
                                <div class="d-flex align-items-center gap-3 py-2 px-2 rounded {{ !$loop->last ? 'border-bottom' : '' }}"
                                     style="cursor:pointer;{{ $isCloturee ? 'opacity:0.5;' : '' }}"
                                     onclick="window.location='{{ route('operations.show', $op) }}';"
                                     onmouseover="this.style.background='rgba(114,34,129,.04)'"
                                     onmouseout="this.style.background='transparent'">

                                    <a href="{{ route('operations.show', $op) }}" class="fw-semibold text-decoration-none" style="color:#333; min-width:180px;">
                                        {{ $op->nom }}
                                    </a>

                                    <small class="text-muted" style="min-width:160px;">
                                        @if($debut && $fin)
                                            {{ $debut->format('d/m/Y') }} &rarr; {{ $fin->format('d/m/Y') }}
                                        @else
                                            &mdash;
                                        @endif
                                    </small>

                                    @if($contextLabel)
                                        <small class="text-nowrap" style="color:{{ $contextColor }}; min-width:100px;">{{ $contextLabel }}</small>
                                    @endif

                                    @if($op->participants_count > 0)
                                        <span class="badge bg-secondary rounded-pill">{{ $op->participants_count }} <i class="bi bi-people"></i></span>
                                    @endif

                                    @if($this->canEdit)
                                        <button class="btn btn-sm btn-outline-secondary ms-auto text-nowrap"
                                                wire:click.stop="openEditModal({{ $op->id }})"
                                                title="Modifier l'opération"
                                                onclick="event.stopPropagation()">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
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
