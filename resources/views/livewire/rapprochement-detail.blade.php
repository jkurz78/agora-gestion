<div>
    {{-- Flash messages --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- En-tête --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h4 class="mb-1">{{ $rapprochement->compte->nom }}</h4>
            @if ($rapprochement->isEnCours())
                <div class="d-flex align-items-center gap-2 mt-1">
                    <label class="text-muted small mb-0">Relevé du</label>
                    <input type="date"
                           wire:change="updateDateFin($event.target.value)"
                           value="{{ $rapprochement->date_fin->format('Y-m-d') }}"
                           class="form-control form-control-sm" style="width:auto"
                           {{ $exerciceCloture || ! $this->canEdit ? 'disabled' : '' }}>
                    <span class="badge bg-warning text-dark ms-1"><i class="bi bi-pencil"></i> En cours</span>
                </div>
                @error('date_fin') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            @else
                <span class="text-muted">Relevé du {{ $rapprochement->date_fin->format('d/m/Y') }}</span>
                <span class="badge bg-secondary ms-2"><i class="bi bi-lock"></i> Verrouillé</span>
            @endif
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('banques.rapprochement.pdf', $rapprochement) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-download"></i> Télécharger PDF
            </a>
            <a href="{{ route('banques.rapprochement.pdf', $rapprochement) }}?mode=inline" target="_blank" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-file-pdf"></i> Ouvrir PDF
            </a>
            <a href="{{ route('banques.rapprochement.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>

    {{-- Bandeau de soldes --}}
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde ouverture</div>
                    <div class="fw-bold">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde fin (relevé)</div>
                    @if ($rapprochement->isEnCours())
                        <input type="number" step="0.01"
                               wire:change="updateSoldeFin($event.target.value)"
                               value="{{ number_format((float) $rapprochement->solde_fin, 2, '.', '') }}"
                               class="form-control form-control-sm text-center fw-bold" style="width:auto;margin:auto"
                               {{ $exerciceCloture || ! $this->canEdit ? 'disabled' : '' }}>
                        @error('solde_fin') <div class="text-danger" style="font-size:.75rem">{{ $message }}</div> @enderror
                    @else
                        <div class="fw-bold">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde pointé</div>
                    <div class="fw-bold">{{ number_format($soldePointage, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-{{ $ecart == 0 ? 'success' : 'danger' }}">
                <div class="card-body py-2">
                    <div class="text-muted small">Écart</div>
                    <div class="fw-bold {{ $ecart == 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($ecart, 2, ',', ' ') }} €
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Débits pointés</div>
                    <div class="fw-bold text-danger">{{ number_format($totalDebitPointe, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Crédits pointés</div>
                    <div class="fw-bold text-success">{{ number_format($totalCreditPointe, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    @if ($rapprochement->isEnCours() && ! $exerciceCloture && $this->canEdit)
        <div class="d-flex gap-2 mb-4">
            <a href="{{ route('banques.rapprochement.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-floppy"></i> Enregistrer et quitter
            </a>
            <button wire:click="supprimer"
                    wire:confirm="Supprimer ce rapprochement ? Toutes les écritures pointées seront dépointées."
                    class="btn btn-outline-danger">
                <i class="bi bi-trash"></i> Supprimer
            </button>
            @if ($ecart == 0)
                <button wire:click="verrouiller"
                        wire:confirm="Verrouiller ce rapprochement ? Cette action est irréversible. Les champs Date, Montant et Compte bancaire des écritures pointées ne pourront plus être modifiés."
                        class="btn btn-danger">
                    <i class="bi bi-lock"></i> Verrouiller
                </button>
            @else
                <button class="btn btn-danger" disabled
                        title="L'écart doit être nul pour verrouiller.">
                    <i class="bi bi-lock"></i> Verrouiller (écart non nul)
                </button>
            @endif
        </div>
    @endif

    {{-- Table des transactions --}}
    @if ($rapprochement->isEnCours() && ! $exerciceCloture && $this->canEdit)
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="masquerPointees"
                   wire:model.live="masquerPointees">
            <label class="form-check-label small text-muted" for="masquerPointees">
                Masquer les écritures pointées
            </label>
        </div>
    @endif
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Libellé</th>
                    <th>Tiers</th>
                    <th>Réf.</th>
                    <th class="text-end">Débit</th>
                    <th class="text-end">Crédit</th>
                    <th class="text-center">Pointé</th>
                </tr>
            </thead>
            <tbody style="color:#555">
                @forelse ($transactions as $tx)
                    <tr wire:key="{{ $tx['type'] }}-{{ $tx['id'] }}" class="{{ $tx['pointe'] ? 'table-success' : '' }}">
                        <td class="text-muted small">{{ $tx['id'] }}</td>
                        <td class="text-nowrap small">{{ $tx['date']->format('d/m/Y') }}</td>
                        <td>
                            @switch($tx['type'])
                                @case('depense') <span class="badge bg-danger" style="font-size:.7rem">Dépense</span> @break
                                @case('recette') <span class="badge bg-success" style="font-size:.7rem">Recette</span> @break
                                @case('virement_source') <span class="badge bg-secondary" style="font-size:.7rem">Virement ↑</span> @break
                                @case('virement_destination') <span class="badge bg-secondary" style="font-size:.7rem">Virement ↓</span> @break
                            @endswitch
                        </td>
                        <td class="small">{{ $tx['label'] }}</td>
                        <td class="small text-muted">{{ $tx['tiers'] ?? '—' }}</td>
                        <td class="text-muted small">{{ $tx['reference'] ?? '—' }}</td>
                        <td class="text-end text-danger fw-semibold small text-nowrap">
                            @if ($tx['montant_signe'] < 0)
                                {{ number_format(abs($tx['montant_signe']), 2, ',', ' ') }} €
                            @endif
                        </td>
                        <td class="text-end text-success fw-semibold small text-nowrap">
                            @if ($tx['montant_signe'] > 0)
                                {{ number_format($tx['montant_signe'], 2, ',', ' ') }} €
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($rapprochement->isEnCours() && ! $exerciceCloture && $this->canEdit)
                                <input type="checkbox"
                                       wire:click="toggle('{{ $tx['type'] }}', {{ $tx['id'] }})"
                                       {{ $tx['pointe'] ? 'checked' : '' }}
                                       class="form-check-input">
                            @else
                                <input type="checkbox"
                                       {{ $tx['pointe'] ? 'checked' : '' }}
                                       disabled
                                       class="form-check-input">
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted">
                            Aucune transaction disponible pour ce compte.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
