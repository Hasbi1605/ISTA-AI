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
     * @param array|null $document_filenames Optional document filenames for RAG mode
     * @param string|null $user_id User ID for authorization in RAG mode
     * @return \Generator
     */
    public function sendChat(array $messages, ?array $document_filenames = null, ?string $user_id = null)
    {
        try {
            $payload = [
                'messages' => $messages,
            ];
            
            if ($document_filenames !== null) {
                $payload['document_filenames'] = $document_filenames;
            }
            
            if ($user_id !== null) {
                $payload['user_id'] = $user_id;
            }
            
            $response = $this->client->post($this->baseUrl . '/api/chat', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'text/event-stream',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'stream' => true,
                'timeout' => 120, // Tambahkan timeout yang aman (120 detik)
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
    
    /**
     * Summarize a document.
     *
     * @param string $filename
     * @param string|null $user_id User ID for authorization
     * @return array
     */
    public function summarizeDocument(string $filename, ?string $user_id = null): array
    {
        try {
            $payload = [
                'filename' => $filename,
            ];
            
            if ($user_id !== null) {
                $payload['user_id'] = $user_id;
            }
            
            $response = $this->client->post($this->baseUrl . '/api/documents/summarize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
            
        } catch (RequestException $e) {
            Log::error('AI Service Summarize Error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Gagal merangkum dokumen: ' . $e->getMessage()
            ];
        }
    }
}
