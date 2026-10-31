<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Jobs\ProcessScan;
use App\Livewire\ScanPage;
use App\Models\Scan;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ScanPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_page_shows_upload_button_tip_and_recent_scans(): void
    {
        $shop = Shop::factory()->create();
        Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/a.mp4', 'status' => ScanStatus::Completed]);
        Scan::create(['shop_id' => $shop->id, 'video_path' => 'uploads/b.mp4', 'status' => ScanStatus::Processing]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('scan'));

        $response->assertOk()
            ->assertSee('Scan a shelf')
            ->assertSee('Film one shelf row, left to right', false)
            ->assertSee('type="file"', false)
            ->assertSee('accept="video/*"', false)
            ->assertSee('Completed')
            ->assertSee('Processing')
            ->assertSee('0 items');
    }

    public function test_scan_offers_record_and_gallery_inputs(): void
    {
        Shop::factory()->create();
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('scan'))->assertOk()->getContent();

        $this->assertStringContainsString('Record a shelf', $html);
        $this->assertStringContainsString('Choose from gallery', $html);
        preg_match_all('/<input[^>]*>/', $html, $matches);
        $videoInputs = array_values(array_filter(
            $matches[0],
            fn (string $tag): bool => str_contains($tag, 'wire:model="video"')
        ));
        $this->assertCount(2, $videoInputs);
        foreach ($videoInputs as $tag) {
            $this->assertStringContainsString('type="file"', $tag);
            $this->assertStringContainsString('accept="video/*"', $tag);
        }
        $withCapture = array_values(array_filter(
            $videoInputs,
            fn (string $tag): bool => str_contains($tag, 'capture=')
        ));
        $this->assertCount(1, $withCapture);
        $this->assertStringContainsString('capture="environment"', $withCapture[0]);
    }

    public function test_recent_scans_lists_only_the_last_five(): void
    {
        $shop = Shop::factory()->create();
        foreach (range(1, 6) as $i) {
            Scan::create(['shop_id' => $shop->id, 'video_path' => "uploads/{$i}.mp4", 'status' => ScanStatus::Completed]);
        }
        $this->actingAs(User::factory()->create());

        $oldest = Scan::query()->orderBy('id')->firstOrFail();
        $oldest->created_at = now()->subDay();
        $oldest->save();

        $response = $this->get(route('scan'));

        $response->assertOk()->assertDontSee('Scan '.$oldest->id.'<', false);
    }

    public function test_uploading_a_clip_creates_a_scan_and_dispatches_the_job(): void
    {
        Storage::fake('shared');
        Queue::fake();
        Shop::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanPage::class)
            ->set('video', UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'))
            ->assertRedirect();

        $scan = Scan::query()->firstOrFail();
        $this->assertSame(ScanStatus::Pending, $scan->status);
        $this->assertStringStartsWith('uploads/', $scan->video_path);
        Storage::disk('shared')->assertExists($scan->video_path);
        Queue::assertPushed(ProcessScan::class, fn ($job) => $job->scanId === $scan->id);
    }

    public function test_upload_rejects_non_video_files(): void
    {
        Storage::fake('shared');
        Queue::fake();
        Shop::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(ScanPage::class)
            ->set('video', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
            ->assertHasErrors('video');

        $this->assertSame(0, Scan::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_scan_show_page_reports_progress_checklist(): void
    {
        $shop = Shop::factory()->create();
        $scan = Scan::create([
            'shop_id' => $shop->id,
            'video_path' => 'uploads/a.mp4',
            'status' => ScanStatus::Processing,
            'stats' => ['progress' => 'Reading labels'],
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('Scan '.$scan->id)
            ->assertSee('Reading labels')
            ->assertSee('wire:poll.2s', false);
    }
}
