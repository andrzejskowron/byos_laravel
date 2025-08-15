<?php

namespace App\Models;

use App\Liquid\Filters\Data;
use App\Liquid\Filters\Localization;
use App\Liquid\Filters\Numbers;
use App\Liquid\Filters\StringMarkup;
use App\Liquid\Filters\Uniqueness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Keepsuit\Liquid\Exceptions\LiquidException;

class Plugin extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'data_payload' => 'json',
        'data_payload_updated_at' => 'datetime',
        'is_native' => 'boolean',
        'markup_language' => 'string',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid();
            }
        });
    }

    public function isDataStale(): bool
    {
        if ($this->data_strategy === 'webhook') {
            // Treat as stale if any webhook event has occurred in the past hour
            return $this->data_payload_updated_at && $this->data_payload_updated_at->gt(now()->subHour());
        }
        if (! $this->data_payload_updated_at || ! $this->data_stale_minutes) {
            return true;
        }

        return $this->data_payload_updated_at->addMinutes($this->data_stale_minutes)->isPast();
    }

    public function updateDataPayload(): void
    {
        if ($this->data_strategy === 'polling' && $this->polling_url) {

            $headers = ['User-Agent' => 'usetrmnl/byos_laravel', 'Accept' => 'application/json'];

            if ($this->polling_header) {
                $headerLines = explode("\n", trim($this->polling_header));
                foreach ($headerLines as $line) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }

            $httpRequest = Http::withHeaders($headers);

            if ($this->polling_verb === 'post' && $this->polling_body) {
                $httpRequest = $httpRequest->withBody($this->polling_body);
            }

            try {
                // Make the request based on the verb
                if ($this->polling_verb === 'post') {
                    $response = $httpRequest->post($this->polling_url);
                } else {
                    $response = $httpRequest->get($this->polling_url);
                }

                // Check if the response was successful
                if (!$response->successful()) {
                    throw new \Exception("HTTP request failed with status: " . $response->status());
                }

                $responseData = $response->json();

                // Check if we got valid JSON data
                if ($responseData === null && $response->body() !== 'null') {
                    throw new \Exception("Invalid JSON response received from polling URL");
                }

                // Validate the response data structure
                $this->validateResponseData($responseData);

                $this->update([
                    'data_payload' => $responseData,
                    'data_payload_updated_at' => now(),
                ]);
            } catch (\Exception $e) {
                // Re-throw the exception so the calling code can handle it
                throw $e;
            }
        }
    }

    /**
     * Validate the response data to ensure it's in the expected format
     * This helps catch cases where the API returns "successful" responses with incorrect data
     */
    private function validateResponseData($responseData): void
    {
        // For XKCD API through allorigins, we expect specific structure
        if (is_array($responseData)) {
            // Check for allorigins.win wrapper format
            if (isset($responseData['status']) && isset($responseData['contents'])) {
                // This is an allorigins response, validate the contents
                if ($responseData['status']['http_code'] !== 200) {
                    throw new \Exception("Proxied request failed with HTTP code: " . $responseData['status']['http_code']);
                }

                $contents = $responseData['contents'];
                if (is_string($contents)) {
                    $decodedContents = json_decode($contents, true);
                    if ($decodedContents === null) {
                        throw new \Exception("Proxied response contains invalid JSON in contents field");
                    }
                    // For XKCD, we expect certain fields
                    if (!isset($decodedContents['img']) || !isset($decodedContents['title'])) {
                        throw new \Exception("Response data missing expected XKCD fields (img, title)");
                    }
                }
            }
            // Check for direct XKCD API format
            elseif (isset($responseData['img']) && isset($responseData['title'])) {
                // Direct XKCD format is valid
                return;
            }
            // Check for error responses that might look "successful"
            elseif (isset($responseData['error']) || isset($responseData['message'])) {
                $errorMsg = $responseData['error'] ?? $responseData['message'] ?? 'Unknown error';
                throw new \Exception("API returned error response: " . $errorMsg);
            }
            // If it's an array but doesn't match expected patterns, it might be invalid
            elseif (empty($responseData) || (count($responseData) === 1 && isset($responseData[0]) && is_string($responseData[0]))) {
                throw new \Exception("Response data appears to be in unexpected format");
            }
        }

        // If responseData is null or empty, that's also problematic
        if (empty($responseData) && $responseData !== 0 && $responseData !== false) {
            throw new \Exception("Response data is empty or null");
        }
    }

    /**
     * Render the plugin's markup
     *
     * @throws LiquidException
     */
    public function render(string $size = 'full', bool $standalone = true): string
    {
        if ($this->render_markup) {
            $renderedContent = '';

            if ($this->markup_language === 'liquid') {
                $environment = App::make('liquid.environment');

                // Register all custom filters
                $environment->filterRegistry->register(Numbers::class);
                $environment->filterRegistry->register(Data::class);
                $environment->filterRegistry->register(StringMarkup::class);
                $environment->filterRegistry->register(Uniqueness::class);
                $environment->filterRegistry->register(Localization::class);

                $template = $environment->parseString($this->render_markup);
                $context = $environment->newRenderContext(data: ['size' => $size, 'data' => $this->data_payload]);
                $renderedContent = $template->render($context);
            } else {
                $renderedContent = Blade::render($this->render_markup, ['size' => $size, 'data' => $this->data_payload]);
            }

            if ($standalone) {
                return view('trmnl-layouts.single', [
                    'slot' => $renderedContent,
                ])->render();
            }

            return $renderedContent;
        }

        if ($this->render_markup_view) {
            if ($standalone) {
                return view('trmnl-layouts.single', [
                    'slot' => view($this->render_markup_view, [
                        'size' => $size,
                        'data' => $this->data_payload,
                    ])->render(),
                ])->render();
            }

            return view($this->render_markup_view, [
                'size' => $size,
                'data' => $this->data_payload,
            ])->render();

        }

        return '<p>No render markup yet defined for this plugin.</p>';
    }
}
