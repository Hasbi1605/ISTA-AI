<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class AIService
{
    protected $client;
    protected $baseUrl;
    protected $token;

    public function __construct()
    {
        $this->client = new Client();
        $this->baseUrl = config('app.ai_service_url', 'http://localhost:8001');
        $this->token = config('app.ai_service_token');
    }

    /**
     * Send a list of messages to the Python AI service and stream the response.
     *
     * @param array $messages
     * @return \Generator
     */
    public function sendChat(array $messages)
    {
        try {
            $response = $this->client->post($this->baseUrl . '/api/chat', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'text/event-stream',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messages' => $messages,
                ],
                'stream' => True,
            ]);

            $body = $response->getBody();

            while (!$body->eof()) {
                yield $body->read(1024);
            }

        } catch (RequestException $e) {
            Log::error('AI Service Error: ' . $e->getMessage());
            yield "❌ Kesalahan sistem saat menghubungi otak AI. Silakan coba lagi nanti.";
        }
    }
}
