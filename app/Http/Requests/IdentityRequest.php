<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class IdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'email' => 'Introduce un correo válido.',
            'string' => 'El campo :attribute debe ser texto.', 'max.string' => 'El campo :attribute no debe superar :max caracteres.',
            'min.string' => 'El campo :attribute debe tener al menos :min caracteres.', 'confirmed' => 'Las contraseñas no coinciden.',
            'regex' => 'El enlace no tiene un formato válido.', 'in' => 'Selecciona una opción permitida.',
            'boolean' => 'Selecciona un estado válido.', 'prohibited' => 'El campo :attribute no está permitido.',
            'password.mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
            'password.numbers' => 'La contraseña debe incluir números.', 'password.symbols' => 'La contraseña debe incluir símbolos.'];
    }

    public function attributes(): array
    {
        return ['email' => 'correo', 'password' => 'contraseña', 'current_password' => 'contraseña actual',
            'name' => 'nombre', 'role' => 'rol', 'active' => 'estado', 'token' => 'enlace'];
    }
}
