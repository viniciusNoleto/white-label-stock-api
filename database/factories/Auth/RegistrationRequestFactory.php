<?php

namespace Database\Factories\Auth;

use App\Models\Auth\RegistrationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<RegistrationRequest>
 */
class RegistrationRequestFactory extends Factory {

    protected $model = RegistrationRequest::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('secret123'),
        ];
    }
}
