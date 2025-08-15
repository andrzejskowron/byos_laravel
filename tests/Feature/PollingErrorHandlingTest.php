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

    public function test_plugin_polling_validates_xkcd_response()
    {
        // Mock response that looks successful but has incorrect XKCD data
        Http::fake([
            'https://api.allorigins.win/get?url=https://xkcd.com/info.0.json' => Http::response([
                'status' => ['http_code' => 200],
                'contents' => '{"error": "Comic not found"}'
            ], 200),
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.allorigins.win/get?url=https://xkcd.com/info.0.json',
            'polling_verb' => 'get',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Response data missing expected XKCD fields');

        $plugin->updateDataPayload();
    }

    public function test_plugin_polling_validates_allorigins_error()
    {
        // Mock allorigins response with HTTP error
        Http::fake([
            'https://api.allorigins.win/get?url=https://xkcd.com/info.0.json' => Http::response([
                'status' => ['http_code' => 404],
                'contents' => 'Not Found'
            ], 200),
        ]);

        $plugin = Plugin::factory()->create([
            'data_strategy' => 'polling',
            'polling_url' => 'https://api.allorigins.win/get?url=https://xkcd.com/info.0.json',
            'polling_verb' => 'get',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Proxied request failed with HTTP code: 404');

        $plugin->updateDataPayload();
    }
}
