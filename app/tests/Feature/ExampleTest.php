<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }

    public function test_landing_shows_pitch_steps_and_entry_points(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Turn a 10-second shelf video into WhatsApp orders.')
            ->assertSee('Scan shelf')
            ->assertSee('Customers order on WhatsApp')
            ->assertSee('Stock updates')
            ->assertSee('Try ordering')
            ->assertSee('View demo shop')
            ->assertSee(route('try', absolute: false))
            ->assertSee(route('login', absolute: false))
            ->assertSee('Meta Global AI Developer Hackathon');
    }
}
