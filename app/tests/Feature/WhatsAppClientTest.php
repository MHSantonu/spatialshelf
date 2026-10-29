<?php

use App\Exceptions\MediaTooLargeException;
use App\Exceptions\WhatsAppException;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $GLOBALS['wa_shared_root'] = storage_path('framework/testing/wa-'.uniqid());
    mkdir($GLOBALS['wa_shared_root'], recursive: true);
});

afterEach(function () {
    $root = $GLOBALS['wa_shared_root'] ?? null;
    unset($GLOBALS['wa_shared_root']);
    if (! is_string($root) || ! is_dir($root)) {
        return;
    }
    foreach (glob($root.'/wa-media/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root.'/wa-media');
    @rmdir($root);
});

function waToken(): string
{
    return 'test-token';
}

function waClient(): WhatsAppClient
{
    return new WhatsAppClient(
        waToken(),
        'phone-123',
        'https://graph.test/v26.0',
        $GLOBALS['wa_shared_root'],
    );
}

function waMessagesRequest(): Request
{
    $recorded = Http::recorded(
        fn (Request $request) => $request->url() === 'https://graph.test/v26.0/phone-123/messages'
    );
    expect($recorded)->toHaveCount(1);

    return $recorded->values()->first()[0];
}

function waHasAuth(Request $request): bool
{
    if (! $request->hasHeader('Authorization')) {
        return false;
    }
    $values = $request->header('Authorization');

    return isset($values[0]) && str_ends_with($values[0], waToken());
}

test('the default base URL is Graph v26.0', function () {
    expect(WhatsAppClient::GRAPH_BASE_URL)->toBe('https://graph.facebook.com/v26.0');
});

test('sendText posts the text payload with auth', function () {
    Http::fake(['https://graph.test/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    waClient()->sendText('8801700000000', 'Price: ৳120');

    $request = waMessagesRequest();
    expect(waHasAuth($request))->toBeTrue()
        ->and($request->data())->toBe([
            'messaging_product' => 'whatsapp',
            'to' => '8801700000000',
            'type' => 'text',
            'text' => ['body' => 'Price: ৳120'],
        ]);
});

test('sendButtons posts an interactive button payload', function () {
    Http::fake(['https://graph.test/*' => Http::response(['messages' => [['id' => 'wamid.2']]])]);

    waClient()->sendButtons('8801700000000', 'Confirm your order?', [
        ['yes', 'Yes, confirm'],
        ['edit', 'Edit items'],
        ['cancel', 'Cancel'],
    ]);

    expect(waMessagesRequest()->data())->toBe([
        'messaging_product' => 'whatsapp',
        'to' => '8801700000000',
        'type' => 'interactive',
        'interactive' => [
            'type' => 'button',
            'body' => ['text' => 'Confirm your order?'],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => 'yes', 'title' => 'Yes, confirm']],
                ['type' => 'reply', 'reply' => ['id' => 'edit', 'title' => 'Edit items']],
                ['type' => 'reply', 'reply' => ['id' => 'cancel', 'title' => 'Cancel']],
            ]],
        ],
    ]);
});

test('sendButtons rejects more than three buttons', function () {
    Http::fake();

    try {
        waClient()->sendButtons('8801700000000', 'Pick one', [
            ['a', 'A'], ['b', 'B'], ['c', 'C'], ['d', 'D'],
        ]);
        $this->fail('InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('3');
    }
    Http::assertNothingSent();
});

test('sendButtons rejects titles longer than twenty characters', function () {
    Http::fake();

    try {
        waClient()->sendButtons('8801700000000', 'Pick one', [['a', str_repeat('x', 21)]]);
        $this->fail('InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('20');
    }
    Http::assertNothingSent();
});

test('sendList posts an interactive list payload', function () {
    Http::fake(['https://graph.test/*' => Http::response(['messages' => [['id' => 'wamid.3']]])]);

    waClient()->sendList('8801700000000', 'Pick a product:', 'View items', [
        ['p1', 'Tang Orange 500g', '৳120 each'],
        ['p2', 'ACI Salt 1kg'],
    ]);

    expect(waMessagesRequest()->data())->toBe([
        'messaging_product' => 'whatsapp',
        'to' => '8801700000000',
        'type' => 'interactive',
        'interactive' => [
            'type' => 'list',
            'body' => ['text' => 'Pick a product:'],
            'action' => [
                'button' => 'View items',
                'sections' => [[
                    'title' => 'Options',
                    'rows' => [
                        ['id' => 'p1', 'title' => 'Tang Orange 500g', 'description' => '৳120 each'],
                        ['id' => 'p2', 'title' => 'ACI Salt 1kg'],
                    ],
                ]],
            ],
        ],
    ]);
});

test('sendList rejects more than ten rows', function () {
    Http::fake();
    $rows = array_map(fn ($i) => ["p{$i}", "Item {$i}"], range(1, 11));

    try {
        waClient()->sendList('8801700000000', 'Pick one:', 'View', $rows);
        $this->fail('InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('10');
    }
    Http::assertNothingSent();
});

test('sendList rejects oversized titles and descriptions', function (array $rows, string $fragment) {
    Http::fake();

    try {
        waClient()->sendList('8801700000000', 'Pick one:', 'View', $rows);
        $this->fail('InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain($fragment);
    }
    Http::assertNothingSent();
})->with([
    'title over 24' => [[['p1', str_repeat('t', 25)]], '24'],
    'description over 72' => [[['p1', 'Ok', str_repeat('d', 73)]], '72'],
]);

test('markRead posts the read receipt payload', function () {
    Http::fake(['https://graph.test/*' => Http::response(['success' => true])]);

    waClient()->markRead('wamid.incoming');

    expect(waMessagesRequest()->data())->toBe([
        'messaging_product' => 'whatsapp',
        'status' => 'read',
        'message_id' => 'wamid.incoming',
    ]);
});

test('downloadMedia resolves the URL, sends auth on both calls, and saves the bytes', function () {
    Http::fake([
        'https://graph.test/*' => Http::response([
            'url' => 'https://media.test/file-1',
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
        ]),
        'https://media.test/*' => Http::response('fake-bytes!'),
    ]);

    $relative = waClient()->downloadMedia('media-1');

    expect($relative)->toBe('wa-media/media-1.jpg')
        ->and(file_get_contents($GLOBALS['wa_shared_root'].'/'.$relative))->toBe('fake-bytes!');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.test/v26.0/media-1'
        && waHasAuth($request));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://media.test/file-1'
        && waHasAuth($request));
});

test('downloadMedia maps spike MIME types to extensions', function (string $mime, string $ext) {
    Http::fake([
        'https://graph.test/*' => Http::response(['url' => 'https://media.test/f', 'mime_type' => $mime]),
        'https://media.test/*' => Http::response('x'),
    ]);

    expect(waClient()->downloadMedia('m1'))->toBe("wa-media/m1.{$ext}");
})->with([
    'jpeg' => ['image/jpeg', 'jpg'],
    'png' => ['image/png', 'png'],
    'webp' => ['image/webp', 'webp'],
    'ogg voice' => ['audio/ogg', 'ogg'],
    'mp3' => ['audio/mpeg', 'mp3'],
    'amr' => ['audio/amr', 'amr'],
    'unknown falls back to subtype' => ['image/gif', 'gif'],
]);

test('downloadMedia rejects media over the byte cap without downloading it', function () {
    Http::fake([
        'https://graph.test/*' => Http::response([
            'url' => 'https://media.test/f', 'mime_type' => 'image/jpeg',
            'file_size' => 20 * 1024 * 1024,
        ]),
        'https://media.test/*' => Http::response('x'),
    ]);

    try {
        waClient()->downloadMedia('big', 16 * 1024 * 1024);
        $this->fail('MediaTooLargeException was not thrown.');
    } catch (MediaTooLargeException $e) {
        expect($e->bytes)->toBe(20 * 1024 * 1024)
            ->and($e->maxBytes)->toBe(16 * 1024 * 1024)
            ->and($e->getMessage())->toContain('16 MB');
    }
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://media.test/f');
});

test('downloadMedia rejects oversized bodies when metadata lacks a size', function () {
    Http::fake([
        'https://graph.test/*' => Http::response(['url' => 'https://media.test/f', 'mime_type' => 'image/jpeg']),
        'https://media.test/*' => Http::response(str_repeat('x', 17)),
    ]);

    try {
        waClient()->downloadMedia('big2', 16);
        $this->fail('MediaTooLargeException was not thrown.');
    } catch (MediaTooLargeException $e) {
        expect($e->bytes)->toBe(17)->and($e->maxBytes)->toBe(16);
    }
    expect(is_file($GLOBALS['wa_shared_root'].'/wa-media/big2.jpg'))->toBeFalse();
});

test('a Graph failure throws WhatsAppException with Meta code and message, and logs them', function () {
    Log::spy();
    Http::fake(['https://graph.test/*' => Http::response([
        'error' => [
            'message' => '(#131031) Recipient is not a valid WhatsApp user.',
            'type' => 'OAuthException',
            'code' => 131031,
        ],
    ], 400)]);

    try {
        waClient()->sendText('8801700000000', 'hi');
        $this->fail('WhatsAppException was not thrown.');
    } catch (WhatsAppException $e) {
        expect($e->metaCode())->toBe(131031)
            ->and($e->status())->toBe(400)
            ->and($e->getMessage())->toContain('131031')
            ->and($e->getMessage())->toContain('not a valid WhatsApp user');
    }

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context) => $message === 'WhatsApp API request failed.'
            && ($context['code'] ?? null) === 131031
            && str_contains((string) ($context['message'] ?? ''), 'not a valid WhatsApp user')
    );
});

test('a downloadMedia failure throws WhatsAppException', function () {
    Http::fake(['https://graph.test/*' => Http::response([
        'error' => ['message' => '(#100) Invalid media id.', 'code' => 100],
    ], 400)]);

    try {
        waClient()->downloadMedia('bad-id');
        $this->fail('WhatsAppException was not thrown.');
    } catch (WhatsAppException $e) {
        expect($e->metaCode())->toBe(100)->and($e->getMessage())->toContain('Invalid media id');
    }
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://media.test'));
});

test('a missing access token throws before anything is sent', function () {
    Http::fake();

    try {
        (new WhatsAppClient('', 'phone-123'))->sendText('8801700000000', 'hi');
        $this->fail('WhatsAppException was not thrown.');
    } catch (WhatsAppException $e) {
        expect($e->getMessage())->toContain('WA_ACCESS_TOKEN');
    }
    Http::assertNothingSent();
});
