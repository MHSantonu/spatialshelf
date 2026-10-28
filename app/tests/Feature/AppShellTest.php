<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('scan'))->assertRedirect(route('login'));
        $this->get(route('stock'))->assertRedirect(route('login'));
        $this->get(route('orders'))->assertRedirect(route('login'));
    }

    public function test_shell_shows_shop_name_and_tab_bar(): void
    {
        Shop::factory()->create(['name' => 'Test Store']);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('scan'));
        $response->assertOk()
            ->assertSee('Test Store')
            ->assertSee(route('scan', absolute: false))
            ->assertSee(route('stock', absolute: false))
            ->assertSee(route('orders', absolute: false))
            ->assertSee('aria-current="page"', false);

        $this->get(route('stock'))->assertOk()->assertSee('Test Store');
        $this->get(route('orders'))->assertOk()->assertSee('Test Store');
    }

    public function test_shell_falls_back_to_app_name_without_a_shop(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('scan'))->assertOk()->assertSee(config('app.name'));
    }
}
