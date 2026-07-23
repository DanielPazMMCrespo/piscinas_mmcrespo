<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Filament\Pages\Auth\Login;
use Livewire\Livewire;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PersistenceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_session_lifetime_is_configured_to_30_days(): void
    {
        $this->assertEquals(43200, config('session.lifetime'));
    }

    public function test_remember_me_defaults_to_false_on_login_form(): void
    {
        Livewire::test(Login::class)
            ->assertSet('data.remember', false);
    }

    public function test_login_authenticates_with_remember_me_by_default(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $user->assignRole('admin');

        Auth::logout();
        $this->assertFalse(Auth::check());

        // Attempt login without specifying remember parameter (simulating form submission)
        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertTrue(Auth::check());
        
        // Retrieve the authenticated user's remember token cookie or status
        // In Laravel, acting as remember set will store remember_token on user model
        $user->refresh();
        $this->assertNotNull($user->remember_token);
    }
}
