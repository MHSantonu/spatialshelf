<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScanMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);

        $this->get(route('scans.clip', $scan))->assertRedirect(route('login'));
        $this->get(route('scans.sam', [$scan, 'overlay_x']))->assertRedirect(route('login'));
    }

    public function test_clip_streams_the_scan_video(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        Storage::disk('shared')->put("scans/{$scan->id}/clip.mp4", 'fake-mp4-bytes');
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('scans.clip', $scan));

        $response->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame(
            'fake-mp4-bytes',
            file_get_contents($response->baseResponse->getFile()->getPathname())
        );
    }

    public function test_clip_missing_returns_404(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.clip', $scan))->assertNotFound();
    }

    public function test_sam_serves_saved_raw_output(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        Storage::disk('shared')->put("scans/{$scan->id}/sam/overlay_jar.txt", '<0f>0<|box;|>');
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('scans.sam', [$scan, 'overlay_jar']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('<0f>0<|box;|>', false);
    }

    public function test_sam_rejects_missing_files_and_unsafe_names(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4']);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.sam', [$scan, 'overlay_missing']))->assertNotFound();
        $this->get("/scans/{$scan->id}/sam/..%2Fsecret")->assertNotFound();
        $this->get("/scans/{$scan->id}/sam/nope!")->assertNotFound();
    }

    public function test_watch_button_hidden_without_overlay(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4', 'status' => ScanStatus::Completed]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Review products')
            ->assertDontSee('Watch scan');
    }

    public function test_watch_button_hidden_when_overlay_is_empty(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4', 'status' => ScanStatus::Completed]);
        Storage::disk('shared')->put("scans/{$scan->id}/sam/overlay_jar.txt", '');
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))->assertOk()->assertDontSee('Watch scan');
    }

    public function test_watch_button_shown_with_overlay(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4', 'status' => ScanStatus::Completed]);
        Storage::disk('shared')->put("scans/{$scan->id}/sam/overlay_plastic_jar.txt", '<0f>0<|box;|>');
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Watch scan')
            ->assertSee(route('scans.watch', $scan, absolute: false));
    }

    public function test_watch_page_needs_an_overlay(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4', 'status' => ScanStatus::Completed]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.watch', $scan))->assertNotFound();
    }

    public function test_watch_page_renders_player_with_media_urls(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $scan = Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4', 'status' => ScanStatus::Completed]);
        Storage::disk('shared')->put("scans/{$scan->id}/sam/overlay_plastic_jar.txt", '<0f>0<|box;|>');
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.watch', $scan))
            ->assertOk()
            ->assertSee('<canvas', false)
            ->assertSee(route('scans.clip', $scan, absolute: false))
            ->assertSee(route('scans.sam', [$scan, 'overlay_plastic_jar'], absolute: false))
            ->assertSee('data-phrase="plastic jar"', false)
            ->assertSee('Loop', false);
    }
}
