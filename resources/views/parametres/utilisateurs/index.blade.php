<x-app-layout>
    <h1 class="mb-4">Utilisateurs</h1>

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

    <div class="mb-3">
        <button class="btn btn-primary btn-sm" type="button"
                data-bs-toggle="collapse" data-bs-target="#addUserForm">
            <i class="bi bi-plus-lg"></i> Ajouter un utilisateur
        </button>
    </div>
    <div class="collapse mb-3" id="addUserForm">
        <div class="card card-body">
            <form action="{{ route($espace->value . '.parametres.utilisateurs.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
                           value="{{ old('nom') }}" required maxlength="100">
                    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           value="{{ old('email') }}" required maxlength="150">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mot de passe</label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           required>
                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">Confirmer</label>
                    <input type="password" name="password_confirmation" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-sm table-striped table-hover">
        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
            <tr><th>Nom</th><th>Email</th><th style="width:100px;"></th></tr>
        </thead>
        <tbody>
            @forelse ($utilisateurs as $utilisateur)
                <tr>
                    <td>{{ $utilisateur->nom }}</td>
                    <td>{{ $utilisateur->email }}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#editUser{{ $utilisateur->id }}"
                                    title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </button>
                            @if ($utilisateur->id !== auth()->id())
                                <form method="POST"
                                      action="{{ route($espace->value . '.parametres.utilisateurs.destroy', $utilisateur) }}"
                                      onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
                <tr class="collapse" id="editUser{{ $utilisateur->id }}">
                    <td colspan="3" class="bg-light">
                        <form action="{{ route($espace->value . '.parametres.utilisateurs.update', $utilisateur) }}"
                              method="POST" class="row g-2 align-items-end p-2">
                            @csrf @method('PUT')
                            <div class="col-md-3">
                                <label class="form-label">Nom</label>
                                <input type="text" name="nom" class="form-control"
                                       value="{{ $utilisateur->nom }}" required maxlength="100">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control"
                                       value="{{ $utilisateur->email }}" required maxlength="150">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Nouveau mdp <span class="text-muted">(opt.)</span></label>
                                <input type="password" name="password" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Confirmer</label>
                                <input type="password" name="password_confirmation" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-success w-100">Mettre à jour</button>
                            </div>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-muted">Aucun utilisateur.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($errors->hasAny(['nom', 'email', 'password']))
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var el = document.getElementById('addUserForm');
            if (el) { new bootstrap.Collapse(el, { toggle: false }).show(); }
        });
    </script>
    @endif
</x-app-layout>
