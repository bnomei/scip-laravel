<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AcceptanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AcceptanceValidatedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $status = $this->route('status');

        return $status instanceof AcceptanceStatus
            && $this->status === $status
            && Gate::allows('manage-acceptance');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'status' => ['required', 'in:draft,published'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'The title is required.',
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'headline',
        ];
    }
}
