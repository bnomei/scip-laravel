<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Forms\AcceptanceValidationForm;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Validate;
use Livewire\Component;

final class AcceptanceValidation extends Component
{
    #[Validate('required|min:3')]
    public string $title = '';

    public AcceptanceValidationForm $form;

    public function mount(): void
    {
        $this->form = new AcceptanceValidationForm($this, 'form');
    }

    protected function rules(): array
    {
        return [
            'title' => 'required|min:3',
            'form.title' => 'required',
            'orphan' => 'required',
            'items.*.title' => 'required',
        ];
    }

    protected function messages(): array
    {
        return [
            'title.required' => 'Title is required.',
            'form.title.required' => 'Form title is required.',
            'orphan.required' => 'Orphan is required.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'title' => 'headline',
            'form.title' => 'form headline',
            'orphan' => 'orphan field',
        ];
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'string',
            'form.title' => 'string',
            'orphan' => 'string',
            'dynamic.'.$this->title => 'required',
        ]);

        Validator::make(
            ['title' => $this->title, 'form.title' => $this->form->title],
            ['title' => 'required|min:5', 'form.title' => ['required', 'string'], 'items.*.title' => 'required'],
            ['title.required' => 'Validator title is required.'],
            ['title' => 'validator headline'],
        )->validate();
    }

    public function render(): View
    {
        return view('livewire.acceptance-validation');
    }
}
