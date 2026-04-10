<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible mb-4">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button type="button"
                    class="nav-link {{ $tab === 'configuration' ? 'active' : '' }}"
                    wire:click="changerOnglet('configuration')">
                Configuration IMAP
            </button>
        </li>
        <li class="nav-item">
            <button type="button"
                    class="nav-link {{ $tab === 'expediteurs' ? 'active' : '' }}"
                    wire:click="changerOnglet('expediteurs')">
                Expéditeurs autorisés ({{ $expediteurs->count() }})
            </button>
        </li>
    </ul>

    @if ($tab === 'configuration')
        <div class="pt-2">
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox"
                           id="enabled" wire:click="toggleEnabled"
                           @checked($enabled)>
                    <label class="form-check-label" for="enabled">
                        Ingestion active
                        @if ($enabled)
                            <span class="badge bg-success ms-1">ACTIVE</span>
                        @else
                            <span class="badge bg-secondary ms-1">DÉSACTIVÉE</span>
                        @endif
                    </label>
                </div>

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Hôte IMAP</label>
                        <input type="text" class="form-control @error('imapHost') is-invalid @enderror"
                               wire:model="imapHost" placeholder="mail.exemple.fr">
                        @error('imapHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-control @error('imapPort') is-invalid @enderror"
                               wire:model="imapPort">
                        @error('imapPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Chiffrement</label>
                        <select class="form-select @error('imapEncryption') is-invalid @enderror"
                                wire:model="imapEncryption">
                            <option value="ssl">SSL</option>
                            <option value="tls">TLS</option>
                            <option value="starttls">STARTTLS</option>
                            <option value="none">Aucun</option>
                        </select>
                        @error('imapEncryption') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Utilisateur</label>
                        <input type="text" class="form-control @error('imapUsername') is-invalid @enderror"
                               wire:model="imapUsername" placeholder="emargement@exemple.fr"
                               autocomplete="off">
                        @error('imapUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            Mot de passe
                            @if ($passwordDejaEnregistre)
                                <span class="badge bg-info ms-1">déjà enregistré</span>
                            @endif
                        </label>
                        <input type="password" class="form-control @error('imapPassword') is-invalid @enderror"
                               wire:model="imapPassword"
                               autocomplete="new-password"
                               placeholder="{{ $passwordDejaEnregistre ? '●●●●●●●● (laisser vide pour conserver)' : '' }}">
                        <div class="form-text text-muted">
                            Chiffré en base de données.
                            @if ($passwordDejaEnregistre) Laisser vide pour conserver la valeur actuelle. @endif
                        </div>
                        @error('imapPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Dossier traité</label>
                        <input type="text" class="form-control @error('processedFolder') is-invalid @enderror"
                               wire:model="processedFolder">
                        @error('processedFolder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Dossier erreurs</label>
                        <input type="text" class="form-control @error('errorsFolder') is-invalid @enderror"
                               wire:model="errorsFolder">
                        @error('errorsFolder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Max par run</label>
                        <input type="number" class="form-control @error('maxPerRun') is-invalid @enderror"
                               wire:model="maxPerRun">
                        @error('maxPerRun') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="button" class="btn btn-primary" wire:click="sauvegarder"
                            wire:loading.attr="disabled" wire:target="sauvegarder">
                        <span wire:loading.remove wire:target="sauvegarder">Enregistrer</span>
                        <span wire:loading wire:target="sauvegarder">
                            <span class="spinner-border spinner-border-sm" role="status"></span> Enregistrement…
                        </span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" wire:click="testerConnexion"
                            wire:loading.attr="disabled" wire:target="testerConnexion">
                        <span wire:loading.remove wire:target="testerConnexion">Tester la connexion</span>
                        <span wire:loading wire:target="testerConnexion">
                            <span class="spinner-border spinner-border-sm" role="status"></span> Test en cours…
                        </span>
                    </button>
                </div>

                @if ($testResult !== null)
                    <div class="mt-3 alert {{ $testResult['success'] ? 'alert-success' : 'alert-danger' }} mb-0">
                        @if ($testResult['success'])
                            <i class="bi bi-check-circle-fill"></i>
                            Connexion OK — {{ $testResult['folderCount'] }} dossiers détectés.
                            @if ($testResult['inboxUnseen'] !== null)
                                INBOX : {{ $testResult['inboxUnseen'] }} message(s) non lu(s).
                            @endif
                            @if ($testResult['processedCreated'])
                                <br>Dossier « {{ $processedFolder }} » créé automatiquement.
                            @endif
                            @if ($testResult['errorsCreated'])
                                <br>Dossier « {{ $errorsFolder }} » créé automatiquement.
                            @endif
                        @else
                            <i class="bi bi-x-circle-fill"></i>
                            Échec : {{ $testResult['error'] }}
                        @endif
                    </div>
                @endif
        </div>
    @endif

    @if ($tab === 'expediteurs')
        <div class="pt-2">
                <table class="table">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Email</th>
                            <th>Libellé</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($expediteurs as $exp)
                            <tr>
                                <td>{{ $exp->email }}</td>
                                <td>{{ $exp->label }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="supprimerExpediteur({{ $exp->id }})"
                                            wire:confirm="Supprimer cette adresse ?">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted text-center">Aucun expéditeur autorisé.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="row g-2 mt-2">
                    <div class="col-md-5">
                        <input type="email" class="form-control @error('nouveauEmail') is-invalid @enderror"
                               wire:model="nouveauEmail" placeholder="copieur@tondomaine.fr">
                        @error('nouveauEmail') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-5">
                        <input type="text" class="form-control @error('nouveauLabel') is-invalid @enderror"
                               wire:model="nouveauLabel" placeholder="Libellé (optionnel)">
                        @error('nouveauLabel') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-primary w-100" wire:click="ajouterExpediteur">
                            <i class="bi bi-plus-lg"></i> Ajouter
                        </button>
                    </div>
                </div>
        </div>
    @endif
</div>

