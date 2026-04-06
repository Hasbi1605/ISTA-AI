<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestAIConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:ai-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connection to the Python AI microservice';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = config('app.ai_service_url', env('AI_SERVICE_URL', 'http://localhost:8001'));
        $this->info("Checking connection to AI Service at: {$url}/api/health");

        try {
            $response = Http::timeout(5)->get("{$url}/api/health");

            if ($response->successful()) {
                $this->info("✅ Connection successful!");
                $this->line(json_encode($response->json(), JSON_PRETTY_PRINT));
            } else {
                $this->error("❌ Connection failed with status: " . $response->status());
            }
        } catch (\Exception $e) {
            $this->error("❌ Connection error: " . $e->getMessage());
        }
    }
}
