<?php

declare(strict_types=1);

namespace App\Livewire\SuperAdmin;

use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Rules\ReservedSlug;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class AssociationDetail extends Component
{
    public Association $association;

    public string $tab = 'info';

    public bool $editingSlug = false;

    public string $newSlug = '';

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function suspend(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if ($this->association->statut !== 'actif') {
            $this->addError('statut', "Transition impossible depuis '{$this->association->statut}'.");

            return;
        }

        DB::transaction(function () {
            $this->association->update(['statut' => 'suspendu']);
            $this->logTransition('suspend');
        });
    }

    public function reactivate(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if ($this->association->statut !== 'suspendu') {
            $this->addError('statut', "Transition impossible depuis '{$this->association->statut}'.");

            return;
        }

        DB::transaction(function () {
            $this->association->update(['statut' => 'actif']);
            $this->logTransition('reactivate');
        });
    }

    public function archive(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        if ($this->association->statut !== 'suspendu') {
            $this->addError('statut', 'Seule une asso suspendue peut être archivée.');

            return;
        }

        DB::transaction(function () {
            $this->association->update(['statut' => 'archive']);
            $this->logTransition('archive');
        });
    }

    public function openSlugEditor(): void
    {
        $this->editingSlug = true;
        $this->newSlug = $this->association->slug ?? '';
    }

    public function cancelSlugEdit(): void
    {
        $this->editingSlug = false;
        $this->newSlug = '';
        $this->resetErrorBag();
    }

    public function saveSlug(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $this->validate(
            [
                'newSlug' => [
                    'required',
                    'string',
                    'regex:/^[a-z0-9-]+$/',
                    'max:80',
                    Rule::unique('association', 'slug')->ignore($this->association->id),
                    new ReservedSlug,
                ],
            ],
            [
                'newSlug.required' => 'Le slug est obligatoire.',
                'newSlug.string' => 'Le slug doit être une chaîne de caractères.',
                'newSlug.regex' => 'Le slug ne peut contenir que des lettres minuscules, des chiffres et des tirets.',
                'newSlug.max' => 'Le slug ne peut pas dépasser 80 caractères.',
                'newSlug.unique' => 'Ce slug est déjà utilisé par une autre association.',
            ],
        );

        if ($this->newSlug === $this->association->slug) {
            $this->editingSlug = false;

            return;
        }

        DB::transaction(function () {
            $oldSlug = $this->association->slug;
            $this->association->allowSlugChange = true;
            $this->association->update(['slug' => $this->newSlug]);
            $this->logTransition('update_slug', ['old_slug' => $oldSlug, 'new_slug' => $this->newSlug]);
        });

        $this->editingSlug = false;
        $this->newSlug = '';
        session()->flash('super-admin.success', 'Slug mis à jour.');
    }

    private function logTransition(string $action, ?array $payload = null): void
    {
        SuperAdminAccessLog::create([
            'user_id' => auth()->id(),
            'association_id' => $this->association->id,
            'action' => $action,
            'payload' => $payload ?? ['new_statut' => $this->association->statut],
            'created_at' => now(),
        ]);
    }

    public function render(): View
    {
        $users = $this->association->users()
            ->withPivot(['role', 'joined_at', 'revoked_at'])
            ->orderByPivot('joined_at', 'desc')
            ->get();

        $logs = SuperAdminAccessLog::query()
            ->where('association_id', $this->association->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('livewire.super-admin.association-detail', [
            'association' => $this->association,
            'users' => $users,
            'logs' => $logs,
        ]);
    }
}
