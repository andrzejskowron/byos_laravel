<?php

namespace Tests\Feature;

use App\Models\Plugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollingErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_plugin_polling_failure_throws_exception()
    {
        // Mock HTTP failure
        Http::fake([
            'https://api.example.com/data' => Http::response(null, 500),
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.example.com/data',
            'polling_verb' => 'get',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HTTP request failed with status: 500');

        $plugin->updateDataPayload();
    }

    public function test_plugin_polling_invalid_json_throws_exception()
    {
        // Mock invalid JSON response
        Http::fake([
            'https://api.example.com/data' => Http::response('invalid json', 200),
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.example.com/data',
            'polling_verb' => 'get',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON response received from polling URL');

        $plugin->updateDataPayload();
    }

    public function test_plugin_polling_success_updates_data()
    {
        // Mock successful HTTP response
        Http::fake([
            'https://api.example.com/data' => Http::response(['temperature' => 25, 'humidity' => 60], 200),
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.example.com/data',
            'polling_verb' => 'get',
            'data_payload' => null,
            'data_payload_updated_at' => null,
        ]);

        $plugin->updateDataPayload();

        $plugin->refresh();
        $this->assertEquals(['temperature' => 25, 'humidity' => 60], $plugin->data_payload);
        $this->assertNotNull($plugin->data_payload_updated_at);
    }

    public function test_plugin_polling_handles_informational_status_codes()
    {
        // Mock 1xx informational response (should fail)
        Http::fake([
            'https://api.example.com/data' => Http::response(['data' => 'test'], 102), // 102 Processing
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.example.com/data',
            'polling_verb' => 'get',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HTTP request failed with status: 102');

        $plugin->updateDataPayload();
    }

    public function test_plugin_polling_handles_client_error_status_codes()
    {
        // Mock 4xx client error response (should fail)
        Http::fake([
            'https://api.example.com/data' => Http::response(['error' => 'Not found'], 404),
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.example.com/data',
            'polling_verb' => 'get',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HTTP request failed with status: 404');

        $plugin->updateDataPayload();
    }

    public function test_plugin_polling_handles_server_error_status_codes()
    {
        // Mock 5xx server error response (should fail)
        Http::fake([
            'https://api.example.com/data' => Http::response(['error' => 'Internal server error'], 500),
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.example.com/data',
            'polling_verb' => 'get',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HTTP request failed with status: 500');

        $plugin->updateDataPayload();
    }
}
