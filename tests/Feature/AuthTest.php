<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function login(string $password, string $email = 'admin@ardic.com')
    {
        return $this->post('/login', compact('email', 'password'));
    }

    public function test_guests_see_nothing(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/board')->assertUnauthorized();
        $this->getJson('/history')->assertUnauthorized();
        $this->postJson('/lists', ['name' => 'x'])->assertUnauthorized();
        $this->post('/switch-user/1')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_login_forces_password_change_then_opens_board(): void
    {
        $this->login('yanlis')->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->login('ilk-sifre-123', ' Admin@Ardic.com ')->assertRedirect('/');
        $this->get('/')->assertRedirect('/password');
        $this->getJson('/board')->assertForbidden();
        $this->get('/password')->assertOk();

        $change = fn ($current, $new, $confirm) => $this->post('/password', [
            'current_password' => $current, 'password' => $new, 'password_confirmation' => $confirm,
        ]);
        $change('yanlis', 'yeni-sifre-1', 'yeni-sifre-1')->assertSessionHasErrors('current_password');
        $change('ilk-sifre-123', 'kisa', 'kisa')->assertSessionHasErrors('password');
        $change('ilk-sifre-123', 'yeni-sifre-1', 'baska-sifre')->assertSessionHasErrors('password');
        $change('ilk-sifre-123', 'ilk-sifre-123', 'ilk-sifre-123')->assertSessionHasErrors('password');
        $change('ilk-sifre-123', 'yeni-sifre-1', 'yeni-sifre-1')->assertRedirect('/');
        $this->get('/')->assertOk();

        $this->post('/logout')->assertRedirect('/login');
        $this->getJson('/board')->assertUnauthorized();
        $this->login('ilk-sifre-123');
        $this->assertGuest();
        $this->login('yeni-sifre-1');
        $this->assertAuthenticated();
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('yanlis');
        }
        $this->login('ilk-sifre-123')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_reset_command_ends_open_sessions(): void
    {
        User::query()->update(['must_change_password' => false]);
        $this->login('ilk-sifre-123');
        $this->getJson('/board')->assertOk();

        $this->artisan('user:reset-password admin@ardic.com')->assertSuccessful();
        Auth::forgetGuards();
        $this->getJson('/board')->assertUnauthorized();
        $this->artisan('user:reset-password yok@ardic.com')->assertFailed();
    }

    public function test_user_seeder_keeps_existing_users_and_adds_missing_ones(): void
    {
        $admin = User::firstWhere('email', 'admin@ardic.com');
        $admin->update(['password' => 'kendi-sifresi', 'must_change_password' => false]);
        User::firstWhere('email', 'ayse.yilmaz@ardic.com')->delete();

        $this->seed(UserSeeder::class);

        $this->assertSame(4, User::count());
        $this->assertTrue(Hash::check('kendi-sifresi', $admin->refresh()->password));
        $this->assertFalse($admin->must_change_password);
        $this->assertTrue(Hash::check('ilk-sifre-123', User::firstWhere('email', 'ayse.yilmaz@ardic.com')->password));
        $this->assertSame(4, User::where('email', 'like', '%@ardic.com')->count());
    }
}
