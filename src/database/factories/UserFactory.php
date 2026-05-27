<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * Default state in the pos_merchant app is `user_type=merchant`
 * with a parent company — the opposite of pos_admin's factory,
 * which defaults to platform_admin. Each app sets the factory
 * default to the role its own auth pipeline expects, so the
 * common path stays painless and cross-population scenarios use
 * an explicit state method.
 *
 * `->platformAdmin()` builds a non-company row for tests that
 * exercise the user_type gate (admin credentials must NOT log
 * in to /pos-merchant — same shape as the symmetric pos_admin
 * test).
 */
class UserFactory extends Factory
{
    /**
     * Memoise the bcrypt of `password` across the suite so
     * Hash::make doesn't dominate the runtime.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'user_type' => 'merchant',
            'status' => 'active',
            'timezone' => 'Asia/Muscat',
            'locale' => 'en',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Build a platform_admin row. Used by tests that prove the
     * pos_merchant login refuses admin credentials, or that
     * exercise cross-app fixtures.
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_type' => 'platform_admin',
            'company_id' => null,
        ]);
    }

    /**
     * Suspended account — login should fail (audited).
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'suspended',
        ]);
    }
}
