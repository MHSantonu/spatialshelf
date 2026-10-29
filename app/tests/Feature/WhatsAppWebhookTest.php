<?php

use App\Jobs\HandleInboundMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'spatialshelf.wa_verify_token' => 'verify-secret',
        'spatialshelf.wa_app_secret' => 'app-secret',
    ]);
    Queue::fake();
});

function waTextMessage(string $id = 'wamid.1'): array
{
    return [
        'id' => $id,
        'from' => '8801700000000',
        'timestamp' => '1761782400',
        'type' => 'text',
        'text' => ['body' => 'Dal 1kg'],
    ];
}

function waMessagePayload(array $messages, array $statuses = [], ?string $contact = 'Rahim'): array
{
    $value = [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '8801000000000', 'phone_number_id' => 'phone-123'],
    ];
    if ($messages !== []) {
        $value['messages'] = $messages;
    }
    if ($statuses !== []) {
        $value['statuses'] = $statuses;
    }
    if ($contact !== null) {
        $value['contacts'] = [['profile' => ['name' => $contact], 'wa_id' => '8801700000000']];
    }

    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => 'entry-1',
        'changes' => [['field' => 'messages', 'value' => $value]],
    ]]];
}

function waSignature(string $body): string
{
    return 'sha256='.hash_hmac('sha256', $body, 'app-secret');
}

function waServer(string $body, ?string $signature = null): array
{
    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? waSignature($body),
    ];
}

test('GET verifies the subscription and returns the challenge as plain text', function () {
    $response = $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-secret&hub.challenge=challenge-123');

    $response->assertOk();
    $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    $response->assertSee('challenge-123', false);
});

test('GET rejects bad verification requests', function (string $query) {
    $this->get('/webhooks/whatsapp?'.$query)->assertForbidden();
})->with([
    'wrong mode' => ['hub.mode=unsubscribe&hub.verify_token=verify-secret&hub.challenge=c'],
    'wrong token' => ['hub.mode=subscribe&hub.verify_token=nope&hub.challenge=c'],
    'missing challenge' => ['hub.mode=subscribe&hub.verify_token=verify-secret'],
    'missing token' => ['hub.mode=subscribe&hub.challenge=c'],
]);

test('GET rejects verification when no verify token is configured', function () {
    config(['spatialshelf.wa_verify_token' => '']);

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=c')->assertForbidden();
});

test('POST without a signature returns 403', function () {
    $body = json_encode(waMessagePayload([waTextMessage()]));

    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertForbidden();
    Queue::assertNothingPushed();
});

test('POST rejects bad signatures', function (string $signature) {
    $body = json_encode(waMessagePayload([waTextMessage()]));

    $this->call('POST', '/webhooks/whatsapp', [], [], [], waServer($body, $signature), $body)
        ->assertForbidden();
    Queue::assertNothingPushed();
})->with([
    'wrong hmac' => ['sha256='.str_repeat('0', 64)],
    'missing prefix' => ['deadbeef'],
]);

test('POST rejects everything when the app secret is not configured', function () {
    config(['spatialshelf.wa_app_secret' => '']);
    $body = json_encode(waMessagePayload([waTextMessage()]));

    $this->call('POST', '/webhooks/whatsapp', [], [], [], waServer($body), $body)->assertForbidden();
    Queue::assertNothingPushed();
});

test('POST dispatches one job per message with the contact name', function () {
    $body = json_encode(waMessagePayload([waTextMessage('wamid.1'), waTextMessage('wamid.2')]));

    $response = $this->call('POST', '/webhooks/whatsapp', [], [], [], waServer($body), $body);

    $response->assertOk()->assertJson(['status' => 'ok']);
    Queue::assertPushed(HandleInboundMessage::class, fn ($job) => $job->message['id'] === 'wamid.1'
        && $job->contactName === 'Rahim');
    Queue::assertPushed(HandleInboundMessage::class, fn ($job) => $job->message['id'] === 'wamid.2'
        && $job->contactName === 'Rahim');
    Queue::assertPushedOn('whatsapp', HandleInboundMessage::class);
    expect(Queue::pushed(HandleInboundMessage::class))->toHaveCount(2);
});

test('POST ignores statuses', function () {
    $body = json_encode(waMessagePayload([], [[
        'id' => 'wamid.s', 'status' => 'delivered', 'timestamp' => '1761782400',
        'recipient_id' => '8801700000000',
    ]]));

    $this->call('POST', '/webhooks/whatsapp', [], [], [], waServer($body), $body)->assertOk();
    Queue::assertNothingPushed();
});

test('POST skips message ids seen in the last 24 hours', function () {
    $body = json_encode(waMessagePayload([waTextMessage('wamid.dup')]));
    $server = waServer($body);

    $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body)->assertOk();
    $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body)->assertOk();

    expect(Queue::pushed(HandleInboundMessage::class))->toHaveCount(1);
    expect(Cache::has('wa:seen:wamid.dup'))->toBeTrue();
});

test('POST with an unparseable body still returns 200', function () {
    $body = '{not-json';

    $this->call('POST', '/webhooks/whatsapp', [], [], [], waServer($body), $body)->assertOk();
    Queue::assertNothingPushed();
});

test('POST dispatches with a null contact name when contacts are absent', function () {
    $body = json_encode(waMessagePayload([waTextMessage()], [], null));

    $this->call('POST', '/webhooks/whatsapp', [], [], [], waServer($body), $body)->assertOk();
    Queue::assertPushed(HandleInboundMessage::class, fn ($job) => $job->contactName === null);
});

test('POST dispatches messages without an id', function () {
    $message = waTextMessage();
    unset($message['id']);
    $body = json_encode(waMessagePayload([$message]));

    $this->call('POST', '/webhooks/whatsapp', [], [], [], waServer($body), $body)->assertOk();
    expect(Queue::pushed(HandleInboundMessage::class))->toHaveCount(1);
});
