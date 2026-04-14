{{-- resources/views/livewire/import-participants.blade.php --}}
<div>
    <button wire:click="togglePanel" type="button" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-upload"></i> Importer des participants
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
                        <h5 class="mb-0"><i class="bi bi-people me-2"></i>Importer des participants</h5>
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

                        <div class="mb-3">
                            <label class="form-label">Fichier CSV ou XLSX</label>
                            <input type="file" wire:model="importFile"
                                   class="form-control @error('importFile') is-invalid @enderror"
                                   accept=".csv,.xlsx">
                            @error('importFile') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div wire:loading wire:target="importFile,analyzeFile" class="form-text text-muted mt-2">
                                <span class="spinner-border spinner-border-sm" role="status"></span>
                                Analyse en cours...
                            </div>
                        </div>

                        <hr>
                        <p class="text-muted small mb-1">
                            <i class="bi bi-info-circle"></i>
                            Colonnes reconnues : <strong>nom</strong>, <strong>prenom</strong>, <strong>email</strong>,
                            <strong>telephone</strong>, <strong>adresse_ligne1</strong>, <strong>code_postal</strong>,
                            <strong>ville</strong>, <strong>date_inscription</strong>, <strong>notes</strong>.
                            @if ($operation->typeOperation?->formulaire_parcours_therapeutique)
                                <br>Ce type d'opération inclut également des colonnes médicales :
                                <strong>date_naissance</strong>, <strong>sexe</strong>,
                                <strong>poids_kg</strong>, <strong>taille_cm</strong>,
                                <strong>nom_jeune_fille</strong>, <strong>nationalite</strong>.
                            @endif
                        </p>
                        <div class="mt-2">
                            <a href="{{ route('operations.participants.import-template', $operation) }}"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark-excel"></i> Telecharger le modele XLSX
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
                        @if ($this->hasConflicts())
                            <div class="alert alert-danger m-3 mb-0">
                                <i class="bi bi-x-octagon-fill me-1"></i>
                                <strong>Des conflits ont ete detectes.</strong>
                                L'import ne peut pas etre realise. Corrigez le fichier et reimportez-le.
                            </div>
                        @endif

                        @if (! empty($parseErrors))
                            <div class="alert alert-danger m-3 mb-0">
                                <strong><i class="bi bi-x-circle-fill"></i> Erreur</strong>
                                @foreach ($parseErrors as $error)
                                    <div>{{ $error['message'] }}</div>
                                @endforeach
                            </div>
                        @endif

                        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880;position:sticky;top:0;z-index:1">
                                    <tr>
                                        <th>Ligne</th>
                                        <th>Nom</th>
                                        <th>Prenom</th>
                                        <th>Email</th>
                                        <th>Statut</th>
                                        <th>Decision</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $index => $row)
                                        <tr wire:key="row-{{ $index }}">
                                            <td>{{ $row['line'] ?? ($index + 2) }}</td>
                                            <td>{{ $row['nom'] ?? '' }}</td>
                                            <td>{{ $row['prenom'] ?? '' }}</td>
                                            <td>{{ $row['email'] ?? '' }}</td>
                                            <td>
                                                @switch($row['status'] ?? '')
                                                    @case('new')
                                                        <span class="badge bg-success">Nouveau tiers</span>
                                                        @break
                                                    @case('matched')
                                                        <span class="badge bg-info text-dark">Tiers existant</span>
                                                        @break
                                                    @case('already_participant')
                                                        <span class="badge bg-secondary">Deja inscrit</span>
                                                        @break
                                                    @case('conflict')
                                                        <span class="badge bg-danger">Conflit</span>
                                                        @break
                                                    @default
                                                        <span class="badge bg-light text-dark">{{ $row['status'] ?? '' }}</span>
                                                @endswitch
                                            </td>
                                            <td>
                                                {{ $row['decision_log'] ?? '' }}
                                                @if (! empty($row['warnings']))
                                                    @foreach ($row['warnings'] as $warning)
                                                        <div class="text-warning small">
                                                            <i class="bi bi-exclamation-triangle"></i> {{ $warning }}
                                                        </div>
                                                    @endforeach
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
                                    $countNew       = collect($rows)->where('status', 'new')->count();
                                    $countMatched   = collect($rows)->where('status', 'matched')->count();
                                    $countSkipped   = collect($rows)->where('status', 'already_participant')->count();
                                    $countConflict  = collect($rows)->where('status', 'conflict')->count();
                                @endphp
                                @if ($countNew > 0)
                                    <span class="badge bg-success me-1">{{ $countNew }} nouveau(x)</span>
                                @endif
                                @if ($countMatched > 0)
                                    <span class="badge bg-info text-dark me-1">{{ $countMatched }} existant(s)</span>
                                @endif
                                @if ($countSkipped > 0)
                                    <span class="badge bg-secondary me-1">{{ $countSkipped }} deja inscrit(s)</span>
                                @endif
                                @if ($countConflict > 0)
                                    <span class="badge bg-danger me-1">{{ $countConflict }} conflit(s)</span>
                                @endif
                            </div>
                            <div class="d-flex gap-2">
                                <button wire:click="cancel" type="button" class="btn btn-secondary btn-sm">
                                    Annuler
                                </button>
                                <button wire:click="confirmImport" type="button"
                                        class="btn btn-success btn-sm"
                                        @if ($this->hasConflicts()) disabled @endif
                                        wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="confirmImport">
                                        <i class="bi bi-check-circle"></i> Importer
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
                {{-- PHASE: IMPORTING --}}
                {{-- ============================================================ --}}
                @elseif ($phase === 'importing')
                    <div class="card-body text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status"
                             wire:loading wire:target="confirmImport">
                            <span class="visually-hidden">Chargement...</span>
                        </div>
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Chargement...</span>
                        </div>
                        <p class="text-muted mb-0">Import en cours...</p>
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
                            <strong>{{ $reportData['total'] }} ligne(s) traitee(s)</strong> :
                            {{ $reportData['created'] }} nouveau(x) tiers cree(s),
                            {{ $reportData['linked'] }} tiers existant(s) lie(s),
                            {{ $reportData['skipped'] }} ignore(s) (deja inscrit).
                        </div>

                        @if (! empty($reportData['lines']))
                            <div style="max-height:360px;overflow-y:auto">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                        <tr>
                                            <th>Ligne</th>
                                            <th>Nom</th>
                                            <th>Prenom</th>
                                            <th>Decision</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($reportData['lines'] as $line)
                                            <tr>
                                                <td>{{ $line['line'] }}</td>
                                                <td>{{ $line['nom'] ?? '—' }}</td>
                                                <td>{{ $line['prenom'] ?? '—' }}</td>
                                                <td>{{ $line['decision'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="d-flex justify-content-end mt-3">
                            <button wire:click="togglePanel" type="button" class="btn btn-primary btn-sm">
                                Fermer
                            </button>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>
    @endif
</div>
