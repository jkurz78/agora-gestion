<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SupportModeController extends Controller
{
    public function enter(Request $request, Association $association): RedirectResponse
    {
        $user = $request->user();

        $this->logAccess($user->id, $association->id, 'enter_support_mode', $request);

        $request->session()->put([
            'support_mode' => true,
            'support_association_id' => $association->id,
            'current_association_id' => $association->id,
        ]);

        return redirect('/dashboard');
    }

    public function exit(Request $request): RedirectResponse
    {
        $user = $request->user();
        $associationId = $request->session()->get('support_association_id');

        if ($associationId !== null) {
            $this->logAccess($user->id, (int) $associationId, 'exit_support_mode', $request);
        }

        $request->session()->forget(['support_mode', 'support_association_id', 'current_association_id']);

        return redirect()->route('super-admin.associations.index');
    }

    private function logAccess(int $userId, int $associationId, string $action, Request $request): void
    {
        SuperAdminAccessLog::create([
            'user_id' => $userId,
            'association_id' => $associationId,
            'action' => $action,
            'payload' => ['ip' => $request->ip()],
            'created_at' => now(),
        ]);
    }
}
