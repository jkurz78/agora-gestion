<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StatutMembre;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateMembreRequest extends FormRequest
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
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'adresse' => ['nullable', 'string'],
            'date_adhesion' => ['nullable', 'date'],
            'statut' => ['required', Rule::in(array_column(StatutMembre::cases(), 'value'))],
            'notes' => ['nullable', 'string'],
        ];
    }
}
