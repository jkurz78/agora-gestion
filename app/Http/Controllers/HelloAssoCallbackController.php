<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\HelloAssoNotification;
use App\Models\HelloAssoParametres;
use App\Support\Demo;
use App\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class HelloAssoCallbackController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        if (Demo::isActive()) {
            Log::info('helloasso.webhook.skipped_demo', [
                'payload_keys' => array_keys($request->all()),
            ]);

            return response()->json(['status' => 'skipped_demo'], 200);
        }

        $parametres = HelloAssoParametres::all()
            ->first(fn ($p) => hash_equals((string) ($p->callback_token ?? ''), $token));

        if ($parametres === null) {
            return response()->json(['error' => 'Invalid token'], 403);
        }

        $association = Association::findOrFail($parametres->association_id);
        TenantContext::boot($association);

        try {
            $payload = $request->all();
            $eventType = $payload['eventType'] ?? 'unknown';
            $libelle = self::buildLibelle($eventType, $payload['data'] ?? []);

            HelloAssoNotification::create([
                'association_id' => $parametres->association_id,
                'event_type' => $eventType,
                'libelle' => $libelle,
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'ok']);
        } finally {
            TenantContext::clear();
        }
    }

    private static function buildLibelle(string $eventType, array $data): string
    {
        $name = trim(($data['payer']['firstName'] ?? $data['name'] ?? '').' '.($data['payer']['lastName'] ?? ''));

        $formType = $data['formType'] ?? '';
        $formName = $data['formName'] ?? $data['formSlug'] ?? '';

        $prefix = match (true) {
            str_contains(strtolower($eventType), 'order') && strtolower($formType) === 'membership' => 'Nouvelle cotisation',
            str_contains(strtolower($eventType), 'order') && strtolower($formType) === 'donation' => 'Nouveau don',
            str_contains(strtolower($eventType), 'order') && strtolower($formType) === 'event' => 'Nouvelle inscription',
            str_contains(strtolower($eventType), 'order') => 'Nouvelle commande',
            str_contains(strtolower($eventType), 'payment') => 'Nouveau paiement',
            str_contains(strtolower($eventType), 'form') => 'Modification formulaire',
            default => 'Notification HelloAsso',
        };

        $parts = [$prefix];
        if ($name !== '') {
            $parts[] = "de {$name}";
        }
        if ($formName !== '' && ! str_contains(strtolower($eventType), 'form')) {
            $parts[] = "({$formName})";
        }

        return implode(' ', $parts);
    }
}
