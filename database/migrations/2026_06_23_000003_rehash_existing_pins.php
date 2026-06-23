<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rehash PINs that are not null and not already hashed (starting with $2y$ or $2a$ or $2x$)
        User::whereNotNull('pin')
            ->get()
            ->each(function ($user) {
                $pin = (string) $user->pin;
                if (! str_starts_with($pin, '$2y$') && ! str_starts_with($pin, '$2a$')) {
                    $user->pin = $pin; // The model cast 'pin' => 'hashed' will automatically hash it on save!
                    $user->save();
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Hashing is one-way and cannot be reversed.
    }
};
