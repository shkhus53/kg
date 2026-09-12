<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Rules\ValidItsNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_users');
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'its_number' => ['required', 'string', new ValidItsNumber, Rule::unique('users', 'its_number')->ignore($user->id)],
            'role' => ['required', 'string', 'in:'.implode(',', User::ROLES)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
