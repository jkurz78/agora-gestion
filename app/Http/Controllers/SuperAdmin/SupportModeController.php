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

        SuperAdminAccessLog::create([
            'user_id' => $user->id,
            'association_id' => $association->id,
            'action' => 'enter_support_mode',
            'payload' => ['ip' => $request->ip()],
            'created_at' => now(),
        ]);

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
            SuperAdminAccessLog::create([
                'user_id' => $user->id,
                'association_id' => $associationId,
                'action' => 'exit_support_mode',
                'payload' => ['ip' => $request->ip()],
                'created_at' => now(),
            ]);
        }

        $request->session()->forget(['support_mode', 'support_association_id', 'current_association_id']);

        return redirect()->route('super-admin.associations.index');
    }
}
