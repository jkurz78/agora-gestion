<div>
    {{-- Flash success --}}
    @if (session('super-admin.success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('super-admin.success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-baseline mb-3">
        <div>
            <h2 class="mb-0">{{ $association->nom }}</h2>
            <span class="d-inline-flex align-items-center gap-2">
                <code class="text-muted">{{ $association->slug }}</code>
                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        wire:click="openSlugEditor">
                    Modifier
                </button>
            </span>
        </div>
        <a href="{{ route('super-admin.associations.index') }}" class="btn btn-link">← Retour à la liste</a>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link {{ $tab === 'info' ? 'active' : '' }}" wire:click.prevent="$set('tab', 'info')" href="#">Infos</a></li>
        <li class="nav-item"><a class="nav-link {{ $tab === 'users' ? 'active' : '' }}" wire:click.prevent="$set('tab', 'users')" href="#">Utilisateurs ({{ $users->count() }})</a></li>
        <li class="nav-item"><a class="nav-link {{ $tab === 'logs' ? 'active' : '' }}" wire:click.prevent="$set('tab', 'logs')" href="#">Logs support ({{ $logs->count() }})</a></li>
    </ul>

    @if ($tab === 'info')
        <dl class="row">
            <dt class="col-sm-3">Forme juridique</dt><dd class="col-sm-9">{{ $association->forme_juridique ?: '—' }}</dd>
            <dt class="col-sm-3">Statut</dt><dd class="col-sm-9"><span class="badge bg-secondary">{{ $association->statut }}</span></dd>
            <dt class="col-sm-3">Onboarding</dt><dd class="col-sm-9">{{ $association->wizard_completed_at?->format('d/m/Y H:i') ?? 'En cours' }}</dd>
            <dt class="col-sm-3">Créée</dt><dd class="col-sm-9">{{ $association->created_at?->format('d/m/Y H:i') }}</dd>
        </dl>

        <hr>
        <h5>Actions</h5>
        @if ($association->statut === 'actif')
            <button wire:click="suspend" wire:confirm="Suspendre {{ $association->nom }} ? Les users ne pourront plus y accéder." class="btn btn-warning">Suspendre</button>
        @elseif ($association->statut === 'suspendu')
            <button wire:click="reactivate" wire:confirm="Réactiver {{ $association->nom }} ?" class="btn btn-success">Réactiver</button>
            <button wire:click="archive" wire:confirm="ARCHIVAGE IRRÉVERSIBLE. Continuer ?" class="btn btn-outline-danger ms-2">Archiver</button>
        @else
            <p class="text-muted small">Association archivée, aucune action disponible.</p>
        @endif

        @error('statut')<div class="alert alert-danger mt-2">{{ $message }}</div>@enderror
    @elseif ($tab === 'users')
        <table class="table">
            <thead><tr><th>Email</th><th>Rôle</th><th>Invité/rejoint</th><th>Révoqué</th></tr></thead>
            <tbody>
                @forelse ($users as $u)
                    <tr class="{{ $u->pivot->revoked_at ? 'text-muted' : '' }}">
                        <td>{{ $u->email }}</td>
                        <td>{{ $u->pivot->role }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($u->pivot->joined_at)->format('d/m/Y') }}</td>
                        <td>{!! $u->pivot->revoked_at ? '<span class="badge bg-warning">révoqué</span>' : '—' !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted text-center py-4">Aucun utilisateur.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <div data-logs-count="{{ $logs->count() }}">
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Super-admin</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse ($logs as $l)
                        <tr>
                            <td>{{ $l->created_at->format('d/m/Y H:i:s') }}</td>
                            <td>{{ $l->user?->email ?? '—' }}</td>
                            <td><code>{{ $l->action }}</code></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted text-center py-4">Aucune entrée.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- Modale modification du slug --}}
    @if ($editingSlug)
        <div class="modal fade show d-block"
             id="slugEditModal"
             tabindex="-1"
             role="dialog"
             aria-labelledby="slugEditModalLabel"
             aria-modal="true"
             style="background:rgba(0,0,0,.5)">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="slugEditModalLabel">Modifier le slug</h5>
                        <button type="button"
                                class="btn-close"
                                wire:click="cancelSlugEdit"
                                aria-label="Fermer"></button>
                    </div>
                    <form wire:submit.prevent="saveSlug">
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                ⚠ Changer le slug modifie l'URL du portail (/portail/{slug}/...). Les magic-links OTP déjà envoyés ne fonctionneront plus. Les justificatifs stockés ne sont pas affectés (chemins par ID numérique).
                            </div>
                            <div class="mb-3">
                                <label for="newSlugInput" class="form-label">Nouveau slug</label>
                                <input type="text"
                                       id="newSlugInput"
                                       wire:model="newSlug"
                                       class="form-control @error('newSlug') is-invalid @enderror"
                                       placeholder="ex: mon-association">
                                @error('newSlug')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">Format : lettres minuscules, chiffres, tirets uniquement, 80 caractères max.</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button"
                                    class="btn btn-outline-secondary"
                                    wire:click="cancelSlugEdit"
                                    data-bs-dismiss="modal">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="btn btn-primary"
                                    wire:loading.attr="disabled">
                                <span wire:loading wire:target="saveSlug" class="spinner-border spinner-border-sm me-1"></span>
                                Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
