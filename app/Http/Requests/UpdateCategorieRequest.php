<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TypeCategorie;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategorieRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_column(TypeCategorie::cases(), 'value'))],
        ];
    }
}
