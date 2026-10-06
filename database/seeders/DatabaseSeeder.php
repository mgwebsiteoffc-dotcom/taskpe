<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * There is nothing to seed here on purpose.
 *
 * TaskPe has no user accounts and no `users` table — the people who use it
 * are Shopify shops (app/Models/Shop) and their team rows
 * (app/Models/Member), which only ever exist after an OAuth install.
 * `php artisan migrate --seed` used to fatal with "Class App\Models\User not
 * found" (leftover Laravel skeleton seeder), which on a fresh deploy reads as
 * "the app is broken" and is exactly the kind of noise that hides a real
 * problem. Board data for a dev/review store comes from:
 *
 *     php artisan taskpe:demo-store {shop.myshopify.com} --force
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Intentionally empty — see the class docblock.
    }
}
