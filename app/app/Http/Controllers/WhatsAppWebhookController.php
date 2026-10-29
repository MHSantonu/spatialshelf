<?php

namespace App\Http\Controllers;

use App\Jobs\HandleInboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        // PHP mangles dots in query keys to underscores, so Meta's
        // hub.mode / hub.verify_token / hub.challenge arrive as hub_*.
        $verifyToken = (string) config('spatialshelf.wa_verify_token');
        $challenge = (string) $request->query('hub_challenge', '');

        if (
            $verifyToken !== ''
            && $challenge !== ''
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($verifyToken, (string) $request->query('hub_verify_token', ''))
        ) {
            return response($challenge)->header('Content-Type', 'text/plain');
        }

        return response('verification failed', 403)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response|JsonResponse
    {
        if (! $this->signatureOk($request)) {
            return response('bad signature', 403)->header('Content-Type', 'text/plain');
        }

        // Always 200 past this point: Meta retries anything else, and the
        // queue (not this request) does the real work, so this stays fast.
        $payload = json_decode($request->getContent(), true);
        if (is_array($payload)) {
            $this->dispatchMessages($payload);
        }

        return response()->json(['status' => 'ok']);
    }

    private function signatureOk(Request $request): bool
    {
        $secret = (string) config('spatialshelf.wa_app_secret');
        if ($secret === '') {
            return false;
        }
        $provided = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($provided, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $provided);
    }

    /** @param array<string, mixed> $payload */
    private function dispatchMessages(array $payload): void
    {
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $changes = $entry['changes'] ?? [];
            if (! is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                $this->dispatchValue($change['value'] ?? null);
            }
        }
    }

    private function dispatchValue(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        // Statuses (value.statuses) are ignored; only messages dispatch.
        $messages = $value['messages'] ?? [];
        if (! is_array($messages)) {
            return;
        }
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $id = $message['id'] ?? null;
            if (is_string($id) && $id !== '') {
                // Cache::add is atomic: false means this id was seen in the last 24 h.
                if (! Cache::add("wa:seen:{$id}", true, now()->addDay())) {
                    continue;
                }
            }
            HandleInboundMessage::dispatch($message, $this->contactName($value));
        }
    }

    /** @param array<string, mixed> $value */
    private function contactName(array $value): ?string
    {
        $contacts = $value['contacts'] ?? null;
        if (! is_array($contacts) || ! isset($contacts[0]) || ! is_array($contacts[0])) {
            return null;
        }
        $name = $contacts[0]['profile']['name'] ?? null;

        return is_string($name) ? $name : null;
    }
}
