<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxied_https_request_generates_https_urls(): void
    {
        $this->call('GET', '/', [], [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'wines.trycloudflare.com',
            'REMOTE_ADDR' => '10.0.0.1',
        ])->assertOk();

        $this->assertTrue(request()->secure());
        $this->assertSame('https://wines.trycloudflare.com/logout', url('/logout'));
    }

    public function test_plain_local_request_stays_http(): void
    {
        $this->get('/')->assertOk();

        $this->assertFalse(request()->secure());
        $this->assertStringStartsWith('http://', url('/logout'));
    }
}
