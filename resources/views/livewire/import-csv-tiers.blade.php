{{-- resources/views/livewire/import-csv-tiers.blade.php --}}
<div>
    <button wire:click="togglePanel" type="button" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-upload"></i> Importer des tiers
    </button>

    @if ($showPanel)
    <div class="position-fixed top-0 start-0 w-100 h-100"
         style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto"
         wire:click.self="togglePanel">
        <div class="container py-4">
            <div class="card mb-4" style="max-width:{{ $phase === 'preview' ? '900px' : '600px' }};margin:auto">

                {{-- ============================================================ --}}
                {{-- PHASE: UPLOAD --}}
                {{-- ============================================================ --}}
                @if ($phase === 'upload')
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-people me-2"></i>Importer des tiers</h5>
                        <button wire:click="togglePanel" type="button" class="btn-close"></button>
                    </div>
                    <div class="card-body">
                        @if (! empty($parseErrors))
                            <div class="alert alert-danger mb-3">
                                <strong><i class="bi bi-x-circle-fill"></i> {{ count($parseErrors) }} erreur(s) detectee(s)</strong>
                                <table class="table table-sm mt-2 mb-0">
                                    <thead><tr><th>Ligne</th><th>Erreur</th></tr></thead>
                                    <tbody>
                                        @foreach ($parseErrors as $error)
                                            <tr>
                                                <td>{{ $error['line'] }}</td>
                                                <td>{{ $error['message'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <form wire:submit="analyzeFile">
                            <div class="mb-3">
                                <label class="form-label">Fichier CSV ou XLSX</label>
                                <input type="file" wire:model="importFile"
                                       class="form-control @error('importFile') is-invalid @enderror"
                                       accept=".csv,.txt,.xlsx">
                                @error('importFile') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div wire:loading wire:target="importFile" class="form-text text-muted">
                                    <span class="spinner-border spinner-border-sm" role="status"></span>
                                    Chargement du fichier...
                                </div>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <button type="submit" class="btn btn-primary btn-sm"
                                        wire:loading.attr="disabled" wire:target="importFile,analyzeFile">
                                    <span wire:loading.remove wire:target="analyzeFile">
                                        <i class="bi bi-search"></i> Analyser le fichier
                                    </span>
                                    <span wire:loading wire:target="analyzeFile">
                                        <span class="spinner-border spinner-border-sm" role="status"></span>
                                        Analyse en cours...
                                    </span>
                                </button>
                            </div>
                        </form>

                        <hr>
                        <p class="text-muted small mb-1">
                            <i class="bi bi-info-circle"></i>
                            Le fichier doit contenir au minimum les colonnes <strong>nom</strong> ou <strong>entreprise</strong>.
                            Colonnes reconnues : nom, prenom, entreprise, email, telephone, adresse_ligne1, code_postal, ville, pays, pour_depenses, pour_recettes.
                        </p>
                        <div class="mt-2">
                            <a href="{{ route('compta.tiers.template.csv') }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark-text"></i> Modele CSV
                            </a>
                            <a href="{{ route('compta.tiers.template.xlsx') }}" class="btn btn-sm btn-outline-secondary ms-1">
                                <i class="bi bi-file-earmark-excel"></i> Modele Excel
                            </a>
                        </div>
                    </div>

                {{-- ============================================================ --}}
                {{-- PHASE: PREVIEW --}}
                {{-- ============================================================ --}}
                @elseif ($phase === 'preview')
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-eye me-2"></i>Apercu de l'import
                            <small class="text-muted ms-2">{{ $originalFilename }}</small>
                        </h5>
                        <button wire:click="cancel" type="button" class="btn-close"></button>
                    </div>
                    <div class="card-body p-0">
                        @if (! empty($parseErrors))
                            <div class="alert alert-danger m-3 mb-0">
                                <strong><i class="bi bi-x-circle-fill"></i> Erreur</strong>
                                @foreach ($parseErrors as $error)
                                    <div>{{ $error['message'] }}</div>
                                @endforeach
                            </div>
                        @endif

                        <div class="table-responsive" style="max-height:400px;overflow-y:auto">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880;position:sticky;top:0;z-index:1">
                                    <tr>
                                        <th>Ligne</th>
                                        <th>Type</th>
                                        <th>Nom / Entreprise</th>
                                        <th>Email</th>
                                        <th>Ville</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rows as $index => $row)
                                        <tr wire:key="row-{{ $index }}">
                                            <td>{{ $row['line'] }}</td>
                                            <td>
                                                @if (($row['type'] ?? 'particulier') === 'entreprise')
                                                    <span class="badge text-bg-secondary">Entreprise</span>
                                                @else
                                                    <span class="badge text-bg-light text-dark">Particulier</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if (($row['type'] ?? 'particulier') === 'entreprise')
                                                    {{ $row['entreprise'] ?? '' }}
                                                    @if (! empty($row['nom']) || ! empty($row['prenom']))
                                                        <div class="text-muted small">{{ trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')) }}</div>
                                                    @endif
                                                @else
                                                    {{ trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')) }}
                                                @endif
                                            </td>
                                            <td>{{ $row['email'] ?? '' }}</td>
                                            <td>{{ $row['ville'] ?? '' }}</td>
                                            <td>
                                                @switch($row['status'])
                                                    @case('new')
                                                        <span class="badge text-bg-success">Nouveau</span>
                                                        @break
                                                    @case('enrichment')
                                                        <span class="badge text-bg-info">Enrichissement</span>
                                                        @break
                                                    @case('identical')
                                                        <span class="badge text-bg-secondary">Identique</span>
                                                        @break
                                                    @case('conflict')
                                                        <span class="badge text-bg-warning">Conflit</span>
                                                        @break
                                                    @case('conflict_resolved_merge')
                                                        <span class="badge text-bg-success">Resolu (fusion)</span>
                                                        @break
                                                    @case('conflict_resolved_new')
                                                        <span class="badge text-bg-success">Nouveau tiers</span>
                                                        @break
                                                @endswitch
                                            </td>
                                            <td>
                                                @if ($row['status'] === 'conflict')
                                                    @if (! empty($row['matched_candidates']) && count($row['matched_candidates']) > 1 && empty($row['selected_candidate_id']))
                                                        {{-- Homonymes: show candidate selection --}}
                                                        <div class="d-flex gap-1 align-items-center">
                                                            <select class="form-select form-select-sm" style="width:auto;min-width:120px"
                                                                    wire:change="selectCandidate({{ $index }}, $event.target.value)">
                                                                <option value="">Choisir...</option>
                                                                @foreach ($row['matched_candidates'] as $candidateId)
                                                                    <option value="{{ $candidateId }}">
                                                                        {{ $row['candidate_labels'][$candidateId] ?? 'Tiers #'.$candidateId }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    @else
                                                        <button class="btn btn-sm btn-outline-warning"
                                                                wire:click="resolveConflict({{ $index }})">
                                                            <i class="bi bi-arrow-left-right"></i> Resoudre
                                                        </button>
                                                    @endif
                                                @endif

                                                @if (! empty($row['warnings']))
                                                    <span class="text-warning ms-1" title="{{ implode(' | ', $row['warnings']) }}"
                                                          data-bs-toggle="tooltip">
                                                        <i class="bi bi-exclamation-triangle"></i>
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Summary bar --}}
                        <div class="d-flex justify-content-between align-items-center p-3 border-top">
                            <div>
                                @php
                                    $countNew = collect($rows)->whereIn('status', ['new', 'conflict_resolved_new'])->count();
                                    $countEnrich = collect($rows)->where('status', 'enrichment')->count();
                                    $countIdentical = collect($rows)->where('status', 'identical')->count();
                                    $countConflict = collect($rows)->where('status', 'conflict')->count();
                                    $countResolved = collect($rows)->where('status', 'conflict_resolved_merge')->count();
                                @endphp
                                <span class="badge text-bg-success me-1">{{ $countNew }} nouveau(x)</span>
                                @if ($countEnrich > 0)
                                    <span class="badge text-bg-info me-1">{{ $countEnrich }} enrichissement(s)</span>
                                @endif
                                @if ($countIdentical > 0)
                                    <span class="badge text-bg-secondary me-1">{{ $countIdentical }} identique(s)</span>
                                @endif
                                @if ($countResolved > 0)
                                    <span class="badge text-bg-success me-1">{{ $countResolved }} resolu(s)</span>
                                @endif
                                @if ($countConflict > 0)
                                    <span class="badge text-bg-warning me-1">{{ $countConflict }} conflit(s) a resoudre</span>
                                @endif
                            </div>
                            <div class="d-flex gap-2">
                                <button wire:click="cancel" class="btn btn-secondary btn-sm">Annuler</button>
                                <button wire:click="confirmImport" class="btn btn-success btn-sm"
                                        @if($this->hasUnresolvedConflicts()) disabled @endif
                                        wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="confirmImport">
                                        <i class="bi bi-check-circle"></i> Confirmer l'import
                                    </span>
                                    <span wire:loading wire:target="confirmImport">
                                        <span class="spinner-border spinner-border-sm" role="status"></span>
                                        Import en cours...
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

                {{-- ============================================================ --}}
                {{-- PHASE: DONE --}}
                {{-- ============================================================ --}}
                @elseif ($phase === 'done' && $reportData)
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-check-circle text-success me-2"></i>Import termine</h5>
                        <button wire:click="togglePanel" type="button" class="btn-close"></button>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-success">
                            <strong>{{ $reportData['total'] }} tiers traites</strong> :
                            {{ $reportData['created'] }} cree(s),
                            {{ $reportData['enriched'] }} enrichi(s),
                            {{ $reportData['resolvedMerge'] }} resolu(s) par fusion,
                            {{ $reportData['resolvedNew'] }} cree(s) manuellement.
                        </div>

                        @if (! empty($reportData['lines']))
                            <div style="max-height:400px;overflow-y:auto">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                        <tr>
                                            <th>Ligne</th>
                                            <th>Entreprise</th>
                                            <th>Nom</th>
                                            <th>Prenom</th>
                                            <th>Decision</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($reportData['lines'] as $line)
                                            <tr>
                                                <td>{{ $line['line'] }}</td>
                                                <td>{{ $line['entreprise'] ?? '—' }}</td>
                                                <td>{{ $line['nom'] ?? '—' }}</td>
                                                <td>{{ $line['prenom'] ?? '—' }}</td>
                                                <td>{{ $line['decision'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button wire:click="downloadReport" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-download"></i> Telecharger le rapport
                            </button>
                            <button wire:click="togglePanel" class="btn btn-primary btn-sm">Fermer</button>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>

    {{-- Merge modal for conflict resolution --}}
    <livewire:tiers-merge-modal />
    @endif
</div>
