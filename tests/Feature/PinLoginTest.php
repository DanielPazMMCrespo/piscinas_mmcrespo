<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PinLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
    }

    public function test_user_can_login_with_correct_pin(): void
    {
        $user = User::factory()->create([
            'email' => 'tecnico@test.pt',
            'pin'   => Hash::make('1234'),
        ]);
        $user->assignRole('tecnico');

        Livewire::test(\App\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => 'tecnico@test.pt', 'password' => '1234'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_wrong_pin(): void
    {
        User::factory()->create([
            'email' => 'tecnico@test.pt',
            'pin'   => Hash::make('1234'),
        ]);

        Livewire::test(\App\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => 'tecnico@test.pt', 'password' => '9999'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_user_cannot_use_another_users_pin(): void
    {
        $userA = User::factory()->create([
            'email' => 'a@test.pt',
            'pin'   => Hash::make('1111'),
        ]);
        $userA->assignRole('tecnico');

        User::factory()->create([
            'email' => 'b@test.pt',
            'pin'   => Hash::make('2222'),
        ]);

        Livewire::test(\App\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => 'a@test.pt', 'password' => '2222'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_user_without_pin_cannot_login_with_digits(): void
    {
        User::factory()->create([
            'email' => 'nopn@test.pt',
            'pin'   => null,
        ]);

        Livewire::test(\App\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => 'nopn@test.pt', 'password' => '1234'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }
}
