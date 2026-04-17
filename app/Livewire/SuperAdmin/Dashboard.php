<?php

declare(strict_types=1);

namespace App\Livewire\SuperAdmin;

use App\Models\Association;
use App\Models\AssociationUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Component;

final class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.super-admin.dashboard', [
            'kpiActifs' => Association::where('statut', 'actif')->count(),
            'kpiSuspendus' => Association::where('statut', 'suspendu')->count(),
            'kpiArchives' => Association::where('statut', 'archive')->count(),
            'kpiUsersParTenant' => AssociationUser::select('association_id', DB::raw('COUNT(*) as total'))
                ->groupBy('association_id')
                ->get(),
            'kpiStockageMo' => Cache::remember('super-admin.stockage_mo', 300, fn () => $this->computeStockageMo()),
            'kpiJobs' => DB::table('jobs')->count(),
            'kpiFailedJobs' => DB::table('failed_jobs')->count(),
        ]);
    }

    private function computeStockageMo(): int
    {
        $base = storage_path('app/associations');
        if (! is_dir($base)) {
            return 0;
        }
        $bytes = 0;
        try {
            /** @var \SplFileInfo $file */
            foreach (File::allFiles($base) as $file) {
                $bytes += $file->getSize();
            }
        } catch (\Throwable) {
            // Fail-soft: return bytes accumulated so far
        }

        return (int) round($bytes / 1024 / 1024);
    }
}
