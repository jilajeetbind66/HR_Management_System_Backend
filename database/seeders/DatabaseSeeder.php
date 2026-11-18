<?php

namespace Database\Seeders;

use App\Support\DatabaseTarget;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if ($this->command) {
            $this->command->line(DatabaseTarget::description());
        }

        if (
            config('app.env') === 'production'
            && (!$this->command || !$this->command->option('force'))
        ) {
            throw new \RuntimeException('Refusing to seed the production database without --force.');
        }

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
