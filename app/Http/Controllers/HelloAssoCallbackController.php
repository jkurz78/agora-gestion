<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HelloAssoNotification;
use App\Models\HelloAssoParametres;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HelloAssoCallbackController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $parametres = HelloAssoParametres::where('callback_token', $token)->first();

        if ($parametres === null) {
            return response()->json(['error' => 'Invalid token'], 403);
        }

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
    }

    private static function buildLibelle(string $eventType, array $data): string
    {
        $name = trim(($data['payer']['firstName'] ?? $data['name'] ?? '') . ' ' . ($data['payer']['lastName'] ?? ''));

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
