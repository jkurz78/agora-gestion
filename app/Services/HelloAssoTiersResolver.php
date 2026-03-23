<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;

final class HelloAssoTiersResolver
{
    /**
     * Extract unique persons from orders, deduplicated by email.
     * Uses user (beneficiary) if present, otherwise payer.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array{firstName: string, lastName: string, email: string}>
     */
    public function extractPersons(array $orders): array
    {
        $seen = [];

        foreach ($orders as $order) {
            $persons = [];

            // Extract per-item beneficiaries (user on each item)
            foreach ($order['items'] ?? [] as $item) {
                if (isset($item['user']['email']) && $item['user']['email'] !== '') {
                    $persons[] = $item['user'];
                }
            }

            // Fallback to order-level user/payer if no per-item users found
            if ($persons === []) {
                $person = $order['user'] ?? $order['payer'] ?? null;
                if ($person !== null) {
                    $persons[] = $person;
                }
            }

            foreach ($persons as $person) {
                $email = strtolower(trim($person['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                if (! isset($seen[$email])) {
                    $seen[$email] = [
                        'firstName' => $person['firstName'] ?? '',
                        'lastName' => $person['lastName'] ?? '',
                        'email' => $email,
                    ];
                }
            }
        }

        return array_values($seen);
    }

    /**
     * Resolve persons against SVS Tiers database.
     *
     * @param  list<array{firstName: string, lastName: string, email: string}>  $persons
     * @return array{linked: list<array>, unlinked: list<array>}
     */
    public function resolve(array $persons): array
    {
        $linked = [];
        $unlinked = [];

        foreach ($persons as $person) {
            // Check if already linked (est_helloasso + same email)
            $existingLinked = Tiers::where('email', $person['email'])
                ->where('est_helloasso', true)
                ->first();

            if ($existingLinked) {
                $linked[] = [
                    'email' => $person['email'],
                    'firstName' => $person['firstName'],
                    'lastName' => $person['lastName'],
                    'tiers_id' => $existingLinked->id,
                    'tiers_name' => $existingLinked->displayName(),
                ];

                continue;
            }

            // Find suggestions
            $suggestions = [];

            // Match by email (strong match)
            $emailMatch = Tiers::where('email', $person['email'])->first();
            if ($emailMatch) {
                $suggestions[] = [
                    'tiers_id' => $emailMatch->id,
                    'tiers_name' => $emailMatch->displayName(),
                    'match_type' => 'email',
                ];
            }

            // Match by name+prenom case-insensitive (weak match) — only if not already suggested
            $suggestedIds = collect($suggestions)->pluck('tiers_id')->all();
            $nameMatches = Tiers::whereRaw('LOWER(nom) = ?', [strtolower($person['lastName'])])
                ->whereRaw('LOWER(prenom) = ?', [strtolower($person['firstName'])])
                ->whereNotIn('id', $suggestedIds)
                ->get();

            foreach ($nameMatches as $match) {
                $suggestions[] = [
                    'tiers_id' => $match->id,
                    'tiers_name' => $match->displayName(),
                    'match_type' => 'nom',
                ];
            }

            $unlinked[] = [
                'email' => $person['email'],
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'suggestions' => $suggestions,
            ];
        }

        return ['linked' => $linked, 'unlinked' => $unlinked];
    }
}
