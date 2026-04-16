<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SwitchAssociationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $validated = $request->validate([
            'association_id' => ['required', 'integer'],
        ]);

        $asso = $user->associations()
            ->where('association_id', $validated['association_id'])
            ->whereNull('association_user.revoked_at')
            ->first();

        abort_if($asso === null, 403, 'Association inaccessible.');

        $request->session()->put('current_association_id', $asso->id);
        $user->update(['derniere_association_id' => $asso->id]);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
