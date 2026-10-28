<?php

use App\Exceptions\VoiceTranscriberException;
use App\Exceptions\WorkerException;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Meta\VoiceTranscriber;
use App\Services\WorkerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $GLOBALS['voice_shared_root'] = sys_get_temp_dir().'/voice-'.uniqid();
    mkdir($GLOBALS['voice_shared_root'].'/voice', recursive: true);
    config(['spatialshelf.shared_storage_path' => $GLOBALS['voice_shared_root']]);
});

afterEach(function () {
    $root = $GLOBALS['voice_shared_root'] ?? null;
    unset($GLOBALS['voice_shared_root']);
    if (! is_string($root) || ! is_dir($root)) {
        return;
    }
    foreach (glob($root.'/voice/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root.'/voice');
    @rmdir($root);
});

function voiceWavFile(string $bytes = 'fake-wav-bytes'): void
{
    file_put_contents($GLOBALS['voice_shared_root'].'/voice/note.wav', $bytes);
}

function voiceTranscriber(): VoiceTranscriber
{
    return new VoiceTranscriber('test-key', 'http://meta.test', worker: new WorkerClient('http://worker.test'));
}

function voiceAsrRequest(): Request
{
    $recorded = Http::recorded(fn (Request $request) => $request->url() === 'http://meta.test/asr/transcribe');
    expect($recorded)->toHaveCount(1);

    return $recorded->values()->first()[0];
}

/** @return array<string, array> */
function voiceParts(Request $request): array
{
    $parts = [];
    foreach ($request->data() as $part) {
        $parts[$part['name']] = $part;
    }

    return $parts;
}

test('transcribe converts via the worker and posts multipart to ASR', function () {
    $shop = Shop::factory()->create();
    Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Tang Orange', 'brand' => 'Tang']);
    voiceWavFile($bytes = 'fake-wav-bytes');
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'voice/note.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
        'http://meta.test/asr/transcribe' => Http::response([
            'sessionId' => 'voyager/transcribe/abc',
            'transcript' => 'Tang one piece',
            'audioDurationMs' => 6800,
            'turns' => [],
        ]),
    ]);

    $result = voiceTranscriber()->transcribe('voice/note.ogg', $shop);

    expect($result)->toBe('Tang one piece');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://worker.test/audio/to-wav'
        && $request['audio_path'] === 'voice/note.ogg');

    $asr = voiceAsrRequest();
    expect($asr->isMultipart())->toBeTrue()
        ->and($asr->hasHeader('Authorization', 'Bearer test-key'))->toBeTrue();
    $parts = voiceParts($asr);
    expect(json_decode($parts['request']['contents'], true))->toBe([
        'model' => 'muse-voice-transcribe-1.0',
        'mode' => 'PUSH_TO_TALK',
        'audioEncoding' => 'WAV',
        'languageBias' => ['English', 'Bengali'],
        'keywords' => ['Tang Orange', 'Tang'],
    ])
        ->and($parts['audio']['contents'])->toBe($bytes)
        ->and($parts['audio']['filename'])->toBe('note.wav');
});

test('transcribe caps keywords at 50 names then brands', function () {
    $shop = Shop::factory()->create();
    foreach (range(0, 29) as $i) {
        Product::factory()->create([
            'shop_id' => $shop->id,
            'name' => sprintf('Product %02d', $i),
            'brand' => sprintf('Brand %02d', $i),
        ]);
    }
    Product::factory()->create(['shop_id' => $shop->id, 'name' => 'No Brand', 'brand' => null]);
    Product::factory()->create(['shop_id' => $shop->id, 'name' => 'Dead', 'brand' => 'Dead', 'active' => false]);
    Product::factory()->create(['name' => 'Elsewhere', 'brand' => 'Elsewhere']);
    voiceWavFile();
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'a.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
        'http://meta.test/asr/transcribe' => Http::response(['transcript' => 'hi']),
    ]);

    voiceTranscriber()->transcribe('a.ogg', $shop);

    // 31 names sort before every brand, so the cap keeps 31 names + 19 brands.
    $names = array_merge(
        array_map(fn ($i) => sprintf('Product %02d', $i), range(0, 29)),
        ['No Brand']
    );
    sort($names);
    $expected = array_merge($names, array_map(fn ($i) => sprintf('Brand %02d', $i), range(0, 18)));
    $parts = voiceParts(voiceAsrRequest());
    $keywords = json_decode($parts['request']['contents'], true)['keywords'];
    expect($keywords)->toBe($expected)
        ->and($keywords)->not->toContain('Dead')
        ->and($keywords)->not->toContain('Elsewhere')
        ->and($keywords)->not->toContain('');
});

test('transcribe joins turns when the transcript is missing', function () {
    $shop = Shop::factory()->create();
    voiceWavFile();
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'a.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
        'http://meta.test/asr/transcribe' => Http::response([
            'turns' => [['transcript' => 'Tang'], ['transcript' => 'two piece'], ['transcript' => '']],
        ]),
    ]);

    expect(voiceTranscriber()->transcribe('a.ogg', $shop))->toBe('Tang two piece');
});

test('transcribe throws on an empty transcript', function (array $asr) {
    voiceWavFile();
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'a.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
        'http://meta.test/asr/transcribe' => Http::response($asr),
    ]);

    try {
        voiceTranscriber()->transcribe('a.ogg', Shop::factory()->create());
        $this->fail('VoiceTranscriberException was not thrown.');
    } catch (VoiceTranscriberException $e) {
        expect($e->getMessage())->toContain('empty transcript');
    }
})->with([
    'blank string' => [['transcript' => '']],
    'whitespace' => [['transcript' => '   ']],
    'no turns' => [['transcript' => '', 'turns' => []]],
    'no transcript key' => [['sessionId' => 'x']],
]);

test('transcribe wraps ASR failures with status and message', function () {
    voiceWavFile();
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'a.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
        'http://meta.test/asr/transcribe' => Http::response(['error' => ['message' => 'overloaded']], 500),
    ]);

    try {
        voiceTranscriber()->transcribe('a.ogg', Shop::factory()->create());
        $this->fail('VoiceTranscriberException was not thrown.');
    } catch (VoiceTranscriberException $e) {
        expect($e->getMessage())->toContain('HTTP 500')->toContain('overloaded');
    }
});

test('transcribe lets worker failures bubble', function () {
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response(['detail' => 'Could not decode audio note.ogg'], 422),
    ]);

    try {
        voiceTranscriber()->transcribe('voice/note.ogg', Shop::factory()->create());
        $this->fail('WorkerException was not thrown.');
    } catch (WorkerException $e) {
        expect($e->getMessage())->toBe('Could not decode audio note.ogg')->and($e->status())->toBe(422);
    }
    Http::assertNotSent(fn (Request $request) => $request->url() === 'http://meta.test/asr/transcribe');
});

test('transcribe throws when the WAV is missing from disk', function () {
    Http::fake([
        'http://worker.test/audio/to-wav' => Http::response([
            'audio_path' => 'a.ogg', 'wav_path' => 'voice/note.wav',
            'sample_rate' => 24000, 'channels' => 1,
        ]),
    ]);

    try {
        voiceTranscriber()->transcribe('a.ogg', Shop::factory()->create());
        $this->fail('VoiceTranscriberException was not thrown.');
    } catch (VoiceTranscriberException $e) {
        expect($e->getMessage())->toContain('WAV not found');
    }
    Http::assertNotSent(fn (Request $request) => $request->url() === 'http://meta.test/asr/transcribe');
});

test('transcribe requires an API key', function () {
    Http::fake();

    try {
        (new VoiceTranscriber('', 'http://meta.test'))->transcribe('a.ogg', Shop::factory()->create());
        $this->fail('VoiceTranscriberException was not thrown.');
    } catch (VoiceTranscriberException $e) {
        expect($e->getMessage())->toContain('MODEL_API_KEY');
    }
    Http::assertNothingSent();
});
