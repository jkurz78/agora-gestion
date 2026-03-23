<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;

final class HelloAssoTiersResolver
{
    /**
     * Extract unique persons (beneficiaries) from orders, deduplicated by nom+prénom.
     *
     * For each item, the beneficiary is item.user (nom+prénom only in HelloAsso).
     * Payer data (email, address) is attached when the beneficiary name matches the payer,
     * or as fallback context when there's a single item.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array{firstName: string, lastName: string, email: ?string, address: ?string, city: ?string, zipCode: ?string, country: ?string}>
     */
    public function extractPersons(array $orders): array
    {
        $seen = [];

        foreach ($orders as $order) {
            $payer = $order['payer'] ?? [];
            $payerFirstName = strtolower(trim($payer['firstName'] ?? ''));
            $payerLastName = strtolower(trim($payer['lastName'] ?? ''));

            foreach ($order['items'] ?? [] as $item) {
                $user = $item['user'] ?? null;
                if ($user === null || empty($user['lastName'])) {
                    continue;
                }

                $firstName = trim($user['firstName'] ?? '');
                $lastName = trim($user['lastName'] ?? '');
                $key = strtolower($lastName) . '|' . strtolower($firstName);

                if (isset($seen[$key])) {
                    continue;
                }

                // Is the beneficiary the same person as the payer?
                $isSameAsPayer = strtolower($firstName) === $payerFirstName
                    && strtolower($lastName) === $payerLastName;

                $seen[$key] = [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'email' => $isSameAsPayer ? strtolower(trim($payer['email'] ?? '')) ?: null : null,
                    'address' => $payer['address'] ?? null,
                    'city' => $payer['city'] ?? null,
                    'zipCode' => $payer['zipCode'] ?? null,
                    'country' => $payer['country'] ?? null,
                ];
            }

            // Fallback: if order has no items with user, use payer directly
            if (empty($order['items'])) {
                $person = $order['user'] ?? $payer;
                if (! empty($person['lastName'])) {
                    $firstName = trim($person['firstName'] ?? '');
                    $lastName = trim($person['lastName'] ?? '');
                    $key = strtolower($lastName) . '|' . strtolower($firstName);

                    if (! isset($seen[$key])) {
                        $seen[$key] = [
                            'firstName' => $firstName,
                            'lastName' => $lastName,
                            'email' => strtolower(trim($person['email'] ?? '')) ?: null,
                            'address' => $payer['address'] ?? null,
                            'city' => $payer['city'] ?? null,
                            'zipCode' => $payer['zipCode'] ?? null,
                            'country' => $payer['country'] ?? null,
                        ];
                    }
                }
            }
        }

        return array_values($seen);
    }

    /**
     * Resolve persons against SVS Tiers database.
     * Primary match: nom+prénom (case-insensitive) + est_helloasso.
     * Suggestions: nom+prénom match, then email match if available.
     *
     * @param  list<array{firstName: string, lastName: string, email: ?string}>  $persons
     * @return array{linked: list<array>, unlinked: list<array>}
     */
    public function resolve(array $persons): array
    {
        $linked = [];
        $unlinked = [];

        foreach ($persons as $person) {
            $lowerLastName = strtolower($person['lastName']);
            $lowerFirstName = strtolower($person['firstName']);

            // Check if already linked (est_helloasso + same nom+prénom)
            $existingLinked = Tiers::whereRaw('LOWER(nom) = ?', [$lowerLastName])
                ->whereRaw('LOWER(prenom) = ?', [$lowerFirstName])
                ->where('est_helloasso', true)
                ->first();

            if ($existingLinked) {
                $linked[] = [
                    'firstName' => $person['firstName'],
                    'lastName' => $person['lastName'],
                    'email' => $person['email'] ?? null,
                    'tiers_id' => $existingLinked->id,
                    'tiers_name' => $existingLinked->displayName(),
                ];

                continue;
            }

            // Find suggestions
            $suggestions = [];

            // Match by name+prenom (strong match for HelloAsso context)
            $nameMatches = Tiers::whereRaw('LOWER(nom) = ?', [$lowerLastName])
                ->whereRaw('LOWER(prenom) = ?', [$lowerFirstName])
                ->get();

            foreach ($nameMatches as $match) {
                $suggestions[] = [
                    'tiers_id' => $match->id,
                    'tiers_name' => $match->displayName(),
                    'match_type' => 'nom',
                ];
            }

            // Match by email if available (and not already suggested)
            if (! empty($person['email'])) {
                $suggestedIds = collect($suggestions)->pluck('tiers_id')->all();
                $emailMatch = Tiers::where('email', $person['email'])
                    ->whereNotIn('id', $suggestedIds)
                    ->first();

                if ($emailMatch) {
                    $suggestions[] = [
                        'tiers_id' => $emailMatch->id,
                        'tiers_name' => $emailMatch->displayName(),
                        'match_type' => 'email',
                    ];
                }
            }

            $unlinked[] = [
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'email' => $person['email'] ?? null,
                'suggestions' => $suggestions,
            ];
        }

        return ['linked' => $linked, 'unlinked' => $unlinked];
    }
}
