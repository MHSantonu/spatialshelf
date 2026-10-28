<?php

namespace App\Services\Meta;

use OpenAI;
use OpenAI\Client;

/**
 * Builds the OpenAI-compatible client for the Meta Model API.
 *
 * No model calls live here beyond client construction; prompts and JSON
 * schemas live in prompts/ and every raw model response is stored by
 * the caller (scans.stats, orders.context) for debugging and the demo.
 */
final class MetaClient
{
    private readonly Client $client;

    public function __construct(string $apiKey, string $baseUrl)
    {
        $this->client = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->make();
    }

    public function client(): Client
    {
        return $this->client;
    }
}
