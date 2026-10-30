<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\ProcessScan;
use App\Livewire\ScanShowPage;
use App\Models\Product;
use App\Models\Scan;
use App\Models\ScanItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ScanShowPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_scan_shows_checklist_phrases_and_elapsed(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Processing,
            'started_at' => now()->subSeconds(45),
            'stats' => ['progress' => 'Cutting out products', 'phrases' => ['plastic jar', 'cardboard box']],
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Preparing video')
            ->assertSee('Reading labels')
            ->assertSee('Muse Spark chose:')
            ->assertSee('plastic jar')
            ->assertSee('cardboard box')
            ->assertSee('Safety net:')
            ->assertSee('45s elapsed')
            ->assertSee('wire:poll.2s', false);
    }

    public function test_picked_phrases_render_as_chips_above_the_safety_net(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Processing,
            'started_at' => now()->subSeconds(20),
            'stats' => [
                'progress' => 'Finding products',
                'phrases' => ['oil bottle', 'jar', 'box', 'packet', 'bottle'],
                'picked_phrases' => ['oil bottle'],
                'phrase_source' => 'picked',
            ],
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('scans.show', $scan))->assertOk();
        $response->assertSee('Muse Spark chose:');
        $response->assertSee('oil bottle');
        $response->assertSee('Safety net: jar · box · packet · bottle');
        // The safety net is a plain line, not a hero chip.
        $this->assertSame(1, substr_count($response->getContent(), 'chip-pop'));
    }

    public function test_finished_steps_show_their_seconds(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Processing,
            'started_at' => now()->subSeconds(20),
            'stats' => [
                'progress' => 'Cutting out products',
                'timings' => ['prepare' => 2.5, 'pick' => 4.2, 'find' => 31.8],
            ],
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('2.5s')
            ->assertSee('4.2s')
            ->assertSee('31.8s');
    }

    public function test_queued_scan_says_queued(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Pending,
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Queued')
            ->assertSee('wire:poll.2s', false);
    }

    public function test_completed_scan_shows_summary_and_buttons_without_polling(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Completed,
            'started_at' => now()->subSeconds(30),
            'finished_at' => now(),
        ]);
        ScanItem::create(['scan_id' => $scan->id, 'crop_path' => 'crops/a.jpg']);
        ScanItem::create(['scan_id' => $scan->id, 'crop_path' => 'crops/b.jpg', 'matched_product_id' => $product->id]);
        Storage::disk('shared')->put("scans/{$scan->id}/sam/overlay_jar.txt", '<0f>0<|box;|>');
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Found 2 products · 1 already known')
            ->assertSee('Review products')
            ->assertSee('Watch scan')
            ->assertSee(route('scans.review', $scan, absolute: false))
            ->assertSee(route('scans.watch', $scan, absolute: false))
            ->assertDontSee('wire:poll', false);
    }

    public function test_completed_scan_shows_memory_line_with_step_timings(): void
    {
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Completed,
            'started_at' => now()->subSeconds(30),
            'finished_at' => now(),
            'stats' => ['progress' => 'Done', 'timings' => ['memory' => 0.42, 'read' => 12.3]],
        ]);
        ScanItem::create(['scan_id' => $scan->id, 'crop_path' => 'crops/a.jpg', 'matched_product_id' => $product->id]);
        ScanItem::create(['scan_id' => $scan->id, 'crop_path' => 'crops/b.jpg', 'matched_product_id' => $product->id]);
        ScanItem::create(['scan_id' => $scan->id, 'crop_path' => 'crops/c.jpg']);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Found 3 products · 2 already known')
            ->assertSee('2 recognised from memory — no AI needed')
            ->assertSee('Memory check 0.42s · Reading labels 12.3s');
    }

    public function test_completed_scan_hides_timings_when_stats_are_missing(): void
    {
        $shop = Shop::factory()->create();
        $product = Product::factory()->create(['shop_id' => $shop->id]);
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Completed,
            'started_at' => now()->subSeconds(30),
            'finished_at' => now(),
        ]);
        ScanItem::create(['scan_id' => $scan->id, 'crop_path' => 'crops/a.jpg', 'matched_product_id' => $product->id]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('1 recognised from memory — no AI needed')
            ->assertDontSee('Memory check');
    }

    public function test_failed_scan_shows_error_and_retry_button(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Failed,
            'stats' => ['error' => 'SAM timed out'],
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('SAM timed out')
            ->assertSee('Retry scan')
            ->assertDontSee('wire:poll', false);
    }

    public function test_retry_resets_the_scan_and_redispatches_the_job(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Failed,
            'stats' => ['error' => 'SAM timed out'],
            'finished_at' => now(),
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanShowPage::class, ['scan' => $scan])
            ->call('retry');

        $scan->refresh();
        $this->assertSame(ScanStatus::Pending, $scan->status);
        $this->assertSame(['progress' => 'Queued'], $scan->stats);
        $this->assertNull($scan->finished_at);
        Queue::assertPushed(ProcessScan::class, fn ($job) => $job->scanId === $scan->id);
    }
}
