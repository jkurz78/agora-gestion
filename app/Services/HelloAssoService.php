<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HelloAssoParametres;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HelloAssoService
{
    public function testerConnexion(HelloAssoParametres $parametres): HelloAssoTestResult
    {
        $baseUrl = $parametres->environnement->baseUrl();

        // Étape 1 : obtenir un token OAuth2
        try {
            $tokenResponse = Http::timeout(10)->asForm()->post("{$baseUrl}/oauth2/token", [
                'client_id'     => $parametres->client_id,
                'client_secret' => $parametres->client_secret,
                'grant_type'    => 'client_credentials',
            ]);
        } catch (ConnectionException) {
            return new HelloAssoTestResult(
                success: false,
                erreur: 'Impossible de joindre HelloAsso : timeout ou erreur réseau',
            );
        }

        if ($tokenResponse->failed()) {
            return new HelloAssoTestResult(
                success: false,
                erreur: "Erreur d'authentification : client_id ou client_secret invalide (HTTP {$tokenResponse->status()})",
            );
        }

        $token = $tokenResponse->json('access_token');

        // Étape 2 : vérifier le slug organisation
        try {
            $orgResponse = Http::timeout(10)
                ->withToken($token)
                ->get("{$baseUrl}/v5/organizations/{$parametres->organisation_slug}");
        } catch (ConnectionException) {
            return new HelloAssoTestResult(
                success: false,
                erreur: 'Impossible de joindre HelloAsso : timeout ou erreur réseau',
            );
        }

        if ($orgResponse->status() === 404) {
            return new HelloAssoTestResult(
                success: false,
                erreur: 'Organisation introuvable : vérifiez le slug (HTTP 404)',
            );
        }

        if ($orgResponse->failed()) {
            return new HelloAssoTestResult(
                success: false,
                erreur: "Erreur lors de la vérification de l'organisation (HTTP {$orgResponse->status()})",
            );
        }

        $nom = $orgResponse->json('name') ?? $orgResponse->json('organizationName') ?? '—';

        return new HelloAssoTestResult(
            success: true,
            organisationNom: $nom,
        );
    }
}
