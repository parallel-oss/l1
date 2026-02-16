<?php

namespace Parallel\L1\Test\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Parallel\L1\Test\Models\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'remember_token' => $this->faker->regexify('[A-Za-z0-9]{10}'),
        ];
    }
}
