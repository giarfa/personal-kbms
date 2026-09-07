<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_authentication_route_names_are_registered(): void
    {
        foreach (['login', 'register', 'logout', 'password.request', 'profile.edit', 'two-factor.login'] as $name) {
            $this->assertFalse(Route::has($name), "Route [{$name}] should not exist.");
        }

        $authIsh = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->filter(fn ($name) => preg_match('/^(auth\.|password\.|two-factor\.|settings\.)/', $name) === 1);

        $this->assertTrue($authIsh->isEmpty(), 'No route name should match auth./password./two-factor./settings. — found: '.$authIsh->implode(', '));
    }

    public function test_the_user_model_does_not_exist(): void
    {
        $this->assertFalse(class_exists(User::class));
    }

    public function test_the_apps_own_auth_config_file_is_gone(): void
    {
        // Laravel merges its own bundled vendor/laravel/framework/config/auth.php as a
        // base default when the app defines none — that's expected framework behavior,
        // not a leftover. What matters is that *this app* never shipped its own file.
        $this->assertFileDoesNotExist(config_path('auth.php'));
        $this->assertFileDoesNotExist(config_path('fortify.php'));
    }

    public function test_login_route_is_missing(): void
    {
        $this->get('/login')->assertNotFound();
    }

    public function test_the_shell_carries_no_logout_or_settings_affordance(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('Log out');
        $response->assertDontSee('Settings');
    }
}
