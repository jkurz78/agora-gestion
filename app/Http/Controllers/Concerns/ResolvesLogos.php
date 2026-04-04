<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Association;
use App\Models\Operation;
use Illuminate\Support\Facades\Storage;

trait ResolvesLogos
{
    /**
     * Resolve header and footer logos for operation documents.
     * Header: type logo if defined, else association logo.
     * Footer: association logo only when header uses the type logo.
     *
     * @return array{0: ?string, 1: string, 2: ?string, 3: string}
     */
    private function resolveLogos(?Association $association, Operation $operation): array
    {
        $assoBase64 = null;
        $assoMime = 'image/png';
        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $assoBase64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $assoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        $typeLogo = $operation->typeOperation?->logo_path;
        if ($typeLogo && Storage::disk('public')->exists($typeLogo)) {
            $typeBase64 = base64_encode(Storage::disk('public')->get($typeLogo));
            $ext = strtolower(pathinfo($typeLogo, PATHINFO_EXTENSION));
            $typeMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';

            return [$typeBase64, $typeMime, $assoBase64, $assoMime];
        }

        return [$assoBase64, $assoMime, null, 'image/png'];
    }

    /**
     * Resolve association logo only (for reports without operation context).
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveAssociationLogo(?Association $association): array
    {
        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $base64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';

            return [$base64, $mime];
        }

        return [null, 'image/png'];
    }
}
