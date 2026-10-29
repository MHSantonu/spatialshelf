<?php

use App\Data\CropsResult;
use App\Data\EmbedResult;
use App\Data\PrepareResult;
use App\Data\SegmentResult;
use App\Data\ToWavResult;
use App\Exceptions\WorkerException;
use App\Services\WorkerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function workerPreparePayload(): array
{
    return [
        'scan_id' => 's1',
        'clip_path' => 'scans/s1/clip.mp4',
        'clip_width' => 1280,
        'clip_height' => 720,
        'full_width' => 1280,
        'full_height' => 720,
        'frame_count' => 120,
        'duration' => 12.0,
        'fps' => 10,
        'rotation' => 0,
        'source_frame_count' => 360,
        'source_frame_indices' => [18, 64, 110],
        'keyframes' => ['scans/s1/keyframes/kf_0.jpg'],
        'keyframes_full' => ['scans/s1/keyframes_full/kf_0.jpg'],
    ];
}

function workerSegmentPayload(): array
{
    return [
        'scan_id' => 's1',
        'phrases' => ['bottle'],
        'phrase_source' => 'given',
        'keyframe_indices' => [0, 1],
        'counts_per_keyframe' => ['0' => 29, '1' => 49],
        'total_detections' => 78,
        'detections_path' => 'scans/s1/detections.json',
        'errors' => [['keyframe' => 1, 'phrase' => 'bottle', 'error' => 'Timeout']],
        'overlay' => [
            'phrase' => 'bottle', 'status' => 'done',
            'path' => 'scans/s1/sam/overlay_bottle.txt', 'seconds' => 20.3, 'error' => null,
        ],
        'timings' => [
            'total_seconds' => 30.1, 'pick_phrases_seconds' => null,
            'image_seconds' => 9.4, 'overlay_seconds' => 20.3,
        ],
    ];
}

function workerCropsPayload(): array
{
    return [
        'scan_id' => 's1',
        'crops' => [[
            'id' => 'kf0_0', 'keyframe' => 0, 'crop_path' => 'scans/s1/crops/kf0_0.jpg',
            'crop_width' => 268, 'crop_height' => 268, 'box' => [100, 150, 300, 350],
            'confidence' => 0.9, 'phrase' => 'bottle',
            'shelf_row' => 1, 'shelf_order' => 2, 'embedding' => [0.1, 0.2, 0.3],
        ]],
        'total_crops' => 1,
        'skipped_edge' => 0,
        'rows' => 1,
    ];
}

test('prepare returns a typed DTO from the worker payload', function () {
    Http::fake(['http://worker.test/scan/prepare' => Http::response(workerPreparePayload())]);

    $result = (new WorkerClient('http://worker.test'))->prepare('s1', 'uploads/x.mp4');

    expect($result)->toBeInstanceOf(PrepareResult::class)
        ->and($result->scanId)->toBe('s1')
        ->and($result->clipPath)->toBe('scans/s1/clip.mp4')
        ->and($result->frameCount)->toBe(120)
        ->and($result->duration)->toBe(12.0)
        ->and($result->sourceFrameIndices)->toBe([18, 64, 110])
        ->and($result->keyframesFull)->toBe(['scans/s1/keyframes_full/kf_0.jpg']);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/scan/prepare'
        && $request['scan_id'] === 's1'
        && $request['video_path'] === 'uploads/x.mp4');
});

test('segment maps nested DTOs and int-keyed counts', function () {
    Http::fake(['http://worker.test/scan/segment' => Http::response(workerSegmentPayload())]);

    $result = (new WorkerClient('http://worker.test'))->segment('s1', ['bottle']);

    expect($result)->toBeInstanceOf(SegmentResult::class)
        ->and($result->phrases)->toBe(['bottle'])
        ->and($result->countsPerKeyframe)->toBe([0 => 29, 1 => 49])
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->error)->toBe('Timeout')
        ->and($result->overlay->isDone())->toBeTrue()
        ->and($result->overlay->seconds)->toBe(20.3)
        ->and($result->timings->pickPhrasesSeconds)->toBeNull();
    Http::assertSent(fn (Request $request) => $request['phrases'] === ['bottle']);
});

test('segment maps a pending overlay with nulls', function () {
    $payload = workerSegmentPayload();
    $payload['overlay'] = [
        'phrase' => 'bottle', 'status' => 'running',
        'path' => null, 'seconds' => null, 'error' => null,
    ];
    $payload['timings']['overlay_seconds'] = null;
    Http::fake(['http://worker.test/scan/segment' => Http::response($payload)]);

    $result = (new WorkerClient('http://worker.test'))->segment('s1');

    expect($result->overlay->isDone())->toBeFalse()
        ->and($result->overlay->path)->toBeNull()
        ->and($result->timings->overlaySeconds)->toBeNull();
});

test('crops maps crop rows with embeddings', function () {
    Http::fake(['http://worker.test/scan/crops' => Http::response(workerCropsPayload())]);

    $result = (new WorkerClient('http://worker.test'))->crops('s1');

    expect($result)->toBeInstanceOf(CropsResult::class)
        ->and($result->totalCrops)->toBe(1)
        ->and($result->rows)->toBe(1)
        ->and($result->crops[0]->id)->toBe('kf0_0')
        ->and($result->crops[0]->box)->toBe([100, 150, 300, 350])
        ->and($result->crops[0]->embedding)->toBe([0.1, 0.2, 0.3]);
});

test('embed returns the vector with its dim', function () {
    Http::fake(['http://worker.test/embed' => Http::response([
        'image_path' => 'uploads/still.jpg', 'embedding' => [0.5, 0.5], 'dim' => 2,
    ])]);

    $result = (new WorkerClient('http://worker.test'))->embed('uploads/still.jpg');

    expect($result)->toBeInstanceOf(EmbedResult::class)
        ->and($result->imagePath)->toBe('uploads/still.jpg')
        ->and($result->embedding)->toBe([0.5, 0.5])
        ->and($result->dim)->toBe(2);
});

test('embed passes the crop flag for single-product photos', function () {
    Http::fake(['http://worker.test/embed' => Http::response([
        'image_path' => 'try/photo.jpg', 'embedding' => [0.5, 0.5], 'dim' => 2,
        'cropped' => true, 'box' => [10, 20, 30, 40],
    ])]);

    (new WorkerClient('http://worker.test'))->embed('try/photo.jpg', true);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/embed'
        && $request->data()['crop_largest_object'] === true);
});

test('toWav returns the converted path with format details', function () {
    Http::fake(['http://worker.test/audio/to-wav' => Http::response([
        'audio_path' => 'voice/note.ogg', 'wav_path' => 'voice/note.wav',
        'sample_rate' => 24000, 'channels' => 1,
    ])]);

    $result = (new WorkerClient('http://worker.test'))->toWav('voice/note.ogg');

    expect($result)->toBeInstanceOf(ToWavResult::class)
        ->and($result->audioPath)->toBe('voice/note.ogg')
        ->and($result->wavPath)->toBe('voice/note.wav')
        ->and($result->sampleRate)->toBe(24000)
        ->and($result->channels)->toBe(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/audio/to-wav'
        && $request['audio_path'] === 'voice/note.ogg');
});

test('a 422 response throws WorkerException with the worker message', function () {
    Http::fake(['http://worker.test/*' => Http::response(
        ['detail' => 'Video is 1.0 s long; clips must be between 1.5 s and 60.0 s.'], 422
    )]);

    try {
        (new WorkerClient('http://worker.test'))->prepare('s1', 'v.mp4');
        $this->fail('WorkerException was not thrown.');
    } catch (WorkerException $e) {
        expect($e->getMessage())->toBe('Video is 1.0 s long; clips must be between 1.5 s and 60.0 s.')
            ->and($e->status())->toBe(422);
    }
});

test('a 500 response throws WorkerException', function () {
    Http::fake(['http://worker.test/*' => Http::response(['detail' => 'boom'], 500)]);

    try {
        (new WorkerClient('http://worker.test'))->embed('x.jpg');
        $this->fail('WorkerException was not thrown.');
    } catch (WorkerException $e) {
        expect($e->getMessage())->toBe('boom')->and($e->status())->toBe(500);
    }
});

test('connection errors are retried twice before succeeding', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        if ($attempts < 3) {
            throw new ConnectionException('connection refused');
        }

        return Http::response(workerPreparePayload());
    });

    $result = (new WorkerClient('http://worker.test'))->prepare('s1', 'v.mp4');

    expect($result->scanId)->toBe('s1')->and($attempts)->toBe(3);
});

test('connection errors bubble after retries are exhausted', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('down');
    });

    try {
        (new WorkerClient('http://worker.test'))->embed('x.jpg');
        $this->fail('ConnectionException was not thrown.');
    } catch (ConnectionException) {
        expect($attempts)->toBe(3);
    }
});

test('other throwables are not retried', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new RuntimeException('boom');
    });

    try {
        (new WorkerClient('http://worker.test'))->embed('x.jpg');
        $this->fail('RuntimeException was not thrown.');
    } catch (RuntimeException) {
        expect($attempts)->toBe(1);
    }
});
