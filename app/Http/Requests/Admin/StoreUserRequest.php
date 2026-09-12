<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Rules\ValidItsNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'its_number' => ['required', 'string', new ValidItsNumber, 'unique:users,its_number'],
            'role' => ['required', 'string', 'in:'.implode(',', User::ROLES)],
            'is_active' => ['required', 'boolean'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
