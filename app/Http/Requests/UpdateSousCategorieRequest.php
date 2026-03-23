<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSousCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categorie_id' => ['required', 'exists:categories,id'],
            'nom' => ['required', 'string', 'max:100'],
            'code_cerfa' => ['nullable', 'string', 'max:10'],
            'pour_dons' => ['sometimes', 'boolean'],
            'pour_cotisations' => ['sometimes', 'boolean'],
            'pour_inscriptions' => ['sometimes', 'boolean'],
        ];
    }
}
