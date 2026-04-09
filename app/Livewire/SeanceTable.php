<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\StatutPresence;
use App\Models\Operation;
use App\Models\Presence;
use App\Models\Seance;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

final class SeanceTable extends Component
{
    public Operation $operation;

    public bool $showProches = false;

    #[On('feuille-updated')]
    public function refreshTable(): void
    {
        // Force re-render — les données sont rechargées dans render()
    }

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        $this->initSeances();
    }

    private function initSeances(): void
    {
        $existingCount = Seance::where('operation_id', $this->operation->id)->count();
        $nombreSeances = $this->operation->nombre_seances ?? 0;

        if ($existingCount === 0 && $nombreSeances > 0) {
            for ($i = 1; $i <= $nombreSeances; $i++) {
                $date = null;
                if ($i === 1 && $this->operation->date_debut) {
                    $date = $this->operation->date_debut->toDateString();
                } elseif ($i === $nombreSeances && $this->operation->date_fin) {
                    $date = $this->operation->date_fin->toDateString();
                }

                Seance::create([
                    'operation_id' => $this->operation->id,
                    'numero' => $i,
                    'date' => $date,
                ]);
            }
        }
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function addSeance(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $maxNumero = Seance::where('operation_id', $this->operation->id)->max('numero') ?? 0;
        Seance::create([
            'operation_id' => $this->operation->id,
            'numero' => $maxNumero + 1,
        ]);

        $this->operation->update(['nombre_seances' => $maxNumero + 1]);
    }

    public function removeSeance(int $seanceId): void
    {
        if (! $this->canEdit) {
            return;
        }

        Seance::where('id', $seanceId)
            ->where('operation_id', $this->operation->id)
            ->delete();
    }

    public function updateSeanceField(int $seanceId, string $field, ?string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        $allowed = ['titre', 'date'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);
        $seance->update([$field => $value !== '' ? $value : null]);
    }

    public function updatePresence(int $seanceId, int $participantId, string $field, ?string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (! Auth::user()?->peut_voir_donnees_sensibles) {
            return;
        }

        $allowed = ['statut', 'kine', 'commentaire'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        // Verify seance belongs to this operation
        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        // Verrouillage partiel : si la feuille signée est attachée, le statut devient RO.
        // kine et commentaire restent éditables.
        if ($field === 'statut' && $seance->feuille_signee_path !== null) {
            return;
        }

        Presence::updateOrCreate(
            ['seance_id' => $seance->id, 'participant_id' => $participantId],
            [$field => $value !== '' ? $value : null]
        );
    }

    public function render(): View
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        if ($this->showProches) {
            $now = now();
            $withDates = $seances->filter(fn ($s) => $s->date !== null);
            $before = $withDates->filter(fn ($s) => $s->date->lte($now))->sortByDesc('date')->take(2);
            $after = $withDates->filter(fn ($s) => $s->date->gt($now))->sortBy('date')->take(2);
            $withoutDates = $seances->filter(fn ($s) => $s->date === null);
            $seances = $before->merge($after)->merge($withoutDates)->sortBy('numero')->values();
        }

        $participants = $this->operation->participants()
            ->with('tiers')
            ->get()
            ->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? '')))
            ->values();

        // Load all presences for this operation's seances in one query
        $seanceIds = $seances->pluck('id');
        $presences = Presence::whereIn('seance_id', $seanceIds)->get();

        // Index by "seanceId-participantId" for quick lookup
        $presenceMap = [];
        foreach ($presences as $p) {
            $presenceMap[$p->seance_id.'-'.$p->participant_id] = $p;
        }

        return view('livewire.seance-table', [
            'seances' => $seances,
            'participants' => $participants,
            'presenceMap' => $presenceMap,
            'statuts' => StatutPresence::cases(),
        ]);
    }
}
