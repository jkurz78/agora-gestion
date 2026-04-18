<div>
    <div class="d-flex justify-content-between align-items-baseline mb-3">
        <div>
            <h2 class="mb-0">{{ $association->nom }}</h2>
            <code class="text-muted">{{ $association->slug }}</code>
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
</div>
