<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * FalEndpointSchema — a fal.ai endpoint's own INPUT schema, used to shape the submit body.
 *
 * fal's generation knobs differ per model (duration is an int enum 3..15 on one model, the string
 * "5"/"10" on another, the const "8s" on a third; resolutions and aspect ratios are per-model
 * enums; prompts and image lists have hard caps) — and an unknown value is a 422. Instead of a
 * hardcoded per-model map, this service pulls the endpoint's public OpenAPI document (no auth),
 * caches it, and clamps the requested duration/resolution/ratio to the NEAREST value the model
 * actually accepts — emitted verbatim in the enum's original type. A fetch failure fails OPEN:
 * the body degrades to prompt + images (the model's own defaults), never a broken submission.
 */
final class FalEndpointSchema
{
    // === CONSTANTS ===
    private const CFG_CATALOG_URL = 'services.fal.catalog_url';
    private const CFG_TIMEOUT = 'services.fal.timeout';

    private const OPENAPI_PATH = '/openapi/queue/openapi.json';
    private const CACHE_PREFIX = 'fal.endpoint-schema.';
    private const CACHE_TTL_SECONDS = 3600;
    // An empty schema (outage) is cached only briefly so a transient failure can't poison the
    // mapping for the full TTL.
    private const EMPTY_TTL_SECONDS = 120;

    private const COMPONENT_SCHEMAS = ['components', 'schemas'];
    private const REQUEST_SCHEMA_PATH = 'post.requestBody.content.application/json.schema';
    private const INPUT_SUFFIX = 'Input';

    // Body keys the shaper writes, and the $params keys (the VideoGenerationProvider vocabulary)
    // they are fed from.
    private const KEY_PROMPT = 'prompt';
    private const KEY_IMAGE_URL = 'image_url';
    private const KEY_IMAGE_URLS = 'image_urls';
    private const KEY_DURATION = 'duration';
    private const KEY_RESOLUTION = 'resolution';
    private const KEY_ASPECT_RATIO = 'aspect_ratio';
    private const PARAM_DURATION = 'duration_seconds';
    private const PARAM_RESOLUTION = 'resolution';
    private const PARAM_RATIO = 'ratio';

    private const TYPE_INTEGER = 'integer';
    private const TYPE_STRING = 'string';
    private const DIGITS_ONLY = '/\D/';

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * The endpoint's INPUT JSON schema (its `properties` describe the accepted body), cached per
     * model. Returns [] when the document is unreachable or unparseable — callers treat that as
     * "no knowledge" and send the minimal legacy body.
     *
     * @return array<string,mixed>
     */
    public function inputSchema(string $model): array
    {
        if ($model === '') {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX.md5($model);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $schema = $this->fetchInputSchema($model);
        Cache::put($cacheKey, $schema, $schema === [] ? self::EMPTY_TTL_SECONDS : self::CACHE_TTL_SECONDS);

        return $schema;
    }

    /**
     * The full POST body for a submission: prompt (truncated to the model's maxLength), the image
     * key(s) the model declares (image_urls trimmed to maxItems), and duration/resolution/aspect
     * ratio clamped to the model's allowed values. With NO schema the body is exactly the legacy
     * shape (prompt + image_url + image_urls) so a schema outage can never regress a submission.
     *
     * @param  array<string,mixed>  $schema
     * @param  array<int,string>  $inlinedImages
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function shapeBody(array $schema, string $prompt, array $inlinedImages, array $params): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if ($properties === []) {
            return $this->legacyBody($prompt, $inlinedImages);
        }

        $body = [self::KEY_PROMPT => $this->clampPrompt($prompt, $properties[self::KEY_PROMPT] ?? null)];

        if ($inlinedImages !== []) {
            if (isset($properties[self::KEY_IMAGE_URL])) {
                $body[self::KEY_IMAGE_URL] = $inlinedImages[0];
            }
            if (isset($properties[self::KEY_IMAGE_URLS])) {
                $body[self::KEY_IMAGE_URLS] = $this->clampImages($inlinedImages, $properties[self::KEY_IMAGE_URLS]);
            }
            if (! isset($body[self::KEY_IMAGE_URL]) && ! isset($body[self::KEY_IMAGE_URLS])) {
                // A text-to-video model (or an unexpected schema) — the references are dropped on
                // purpose (an unknown key is a 422), but the operator must be able to see it.
                Log::warning('fal.schema.images_dropped', ['declared_keys' => array_keys($properties)]);
            }
        }

        $duration = $this->clampDuration($properties[self::KEY_DURATION] ?? null, $params[self::PARAM_DURATION] ?? null);
        if ($duration !== null) {
            $body[self::KEY_DURATION] = $duration;
        }

        $resolution = $this->clampResolution($properties[self::KEY_RESOLUTION] ?? null, $params[self::PARAM_RESOLUTION] ?? null);
        if ($resolution !== null) {
            $body[self::KEY_RESOLUTION] = $resolution;
        }

        $ratio = $this->matchAspectRatio($properties[self::KEY_ASPECT_RATIO] ?? null, $params[self::PARAM_RATIO] ?? null);
        if ($ratio !== null) {
            $body[self::KEY_ASPECT_RATIO] = $ratio;
        }

        return $body;
    }

    /**
     * The NUMERIC seconds the duration clamp would send for this schema (e.g. 15 when 120 was
     * requested on a 3..15 model), or null when the model exposes no duration knob — so prompt
     * timings can be built against what the model will actually render.
     */
    public function effectiveDuration(array $schema, int $requestedSeconds): ?int
    {
        $property = is_array($schema['properties'][self::KEY_DURATION] ?? null)
            ? $schema['properties'][self::KEY_DURATION]
            : null;

        $value = $this->clampDuration($property, $requestedSeconds);
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace(self::DIGITS_ONLY, '', (string) $value);

        return $digits === '' ? null : (int) $digits;
    }

    /** The pre-schema body shape — prompt + first image + full list. @param array<int,string> $inlinedImages @return array<string,mixed> */
    private function legacyBody(string $prompt, array $inlinedImages): array
    {
        $body = [self::KEY_PROMPT => $prompt];

        if ($inlinedImages !== []) {
            $body[self::KEY_IMAGE_URL] = $inlinedImages[0];
            if (count($inlinedImages) > 1) {
                $body[self::KEY_IMAGE_URLS] = $inlinedImages;
            }
        }

        return $body;
    }

    /** @return array<string,mixed> */
    private function fetchInputSchema(string $model): array
    {
        try {
            $response = $this->http
                ->baseUrl((string) config(self::CFG_CATALOG_URL))
                ->timeout((int) config(self::CFG_TIMEOUT))
                ->acceptJson()
                ->get(self::OPENAPI_PATH, ['endpoint_id' => $model]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        return $this->extractInput($response->json());
    }

    /**
     * The INPUT schema out of an OpenAPI document: the POST requestBody schema ($ref-resolved),
     * with a heuristic fallback to the "*Input" component that carries a prompt property.
     *
     * @param  array<string,mixed>  $doc
     * @return array<string,mixed>
     */
    private function extractInput(array $doc): array
    {
        $schemas = data_get($doc, implode('.', self::COMPONENT_SCHEMAS));
        $schemas = is_array($schemas) ? $schemas : [];

        foreach ((array) ($doc['paths'] ?? []) as $path) {
            $resolved = $this->resolveRef(data_get($path, self::REQUEST_SCHEMA_PATH), $schemas);
            if ($this->looksLikeInput($resolved)) {
                return $resolved;
            }
        }

        foreach ($schemas as $name => $schema) {
            if (is_string($name) && str_ends_with($name, self::INPUT_SUFFIX)
                && is_array($schema) && $this->looksLikeInput($schema)) {
                return $schema;
            }
        }

        return [];
    }

    /** Resolve a possible {$ref: '#/components/schemas/X'} node against the component map. @return array<string,mixed> */
    private function resolveRef(mixed $schema, array $schemas): array
    {
        if (! is_array($schema)) {
            return [];
        }

        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $schema = $schemas[basename($ref)] ?? [];
        }

        return is_array($schema) ? $schema : [];
    }

    private function looksLikeInput(array $schema): bool
    {
        return is_array($schema['properties'] ?? null) && isset($schema['properties'][self::KEY_PROMPT]);
    }

    private function clampPrompt(string $prompt, mixed $property): string
    {
        $max = is_array($property) ? ($property['maxLength'] ?? null) : null;

        return (is_int($max) && $max > 0) ? mb_substr($prompt, 0, $max) : $prompt;
    }

    /** @param array<int,string> $images @return array<int,string> */
    private function clampImages(array $images, mixed $property): array
    {
        $max = is_array($property) ? ($property['maxItems'] ?? null) : null;

        return (is_int($max) && $max > 0) ? array_slice($images, 0, $max) : $images;
    }

    /**
     * Clamp the requested seconds to the duration values the model accepts, returning the winning
     * enum entry VERBATIM (int 15, string "10", or "8s" — the original type matters): the largest
     * allowed value not exceeding the request, else the smallest allowed. Without an enum, an
     * integer property is min/max-clamped and a string property receives the request as a string.
     */
    private function clampDuration(mixed $property, mixed $requested): mixed
    {
        if (! is_array($property) || ! is_numeric($requested)) {
            return null;
        }

        $seconds = (int) $requested;
        $candidates = $this->numericCandidates($property);

        if ($candidates !== []) {
            return $this->largestNotAbove($candidates, $seconds);
        }

        $types = (array) ($property['type'] ?? []);

        if (in_array(self::TYPE_INTEGER, $types, true)) {
            $value = $seconds;
            if (is_numeric($property['minimum'] ?? null)) {
                $value = max($value, (int) $property['minimum']);
            }
            if (is_numeric($property['maximum'] ?? null)) {
                $value = min($value, (int) $property['maximum']);
            }

            return $value;
        }

        return in_array(self::TYPE_STRING, $types, true) ? (string) $seconds : null;
    }

    /** The enum entry numerically nearest the requested resolution (ties → the smaller/cheaper). */
    private function clampResolution(mixed $property, mixed $requested): mixed
    {
        if (! is_array($property)) {
            return null;
        }

        $digits = (string) preg_replace(self::DIGITS_ONLY, '', (string) $requested);
        if ($digits === '') {
            return null;
        }

        $target = (int) $digits;
        $candidates = $this->numericCandidates($property);

        if ($candidates === []) {
            // Free-form string knob — pass the request through untouched.
            return in_array(self::TYPE_STRING, (array) ($property['type'] ?? []), true) ? (string) $requested : null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        $pick = $candidates[0];

        foreach ($candidates as $candidate) {
            if (abs($candidate['number'] - $target) < abs($pick['number'] - $target)) {
                $pick = $candidate;
            }
        }

        return $pick['value'];
    }

    /** Aspect ratio is sent ONLY on an exact enum match — 'adaptive' etc. are silently omitted. */
    private function matchAspectRatio(mixed $property, mixed $requested): ?string
    {
        if (! is_array($property) || ! is_string($requested) || $requested === '') {
            return null;
        }

        $enum = is_array($property['enum'] ?? null) ? $property['enum'] : [];

        return in_array($requested, $enum, true) ? $requested : null;
    }

    /**
     * The property's enum/const values paired with their numeric reading ("8s" → 8, "1080p" →
     * 1080); entries with no digits are skipped.
     *
     * @return array<int,array{value:mixed,number:int}>
     */
    private function numericCandidates(array $property): array
    {
        $raw = is_array($property['enum'] ?? null)
            ? $property['enum']
            : (array_key_exists('const', $property) ? [$property['const']] : []);

        $candidates = [];
        foreach ($raw as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $digits = (string) preg_replace(self::DIGITS_ONLY, '', (string) $value);
            if ($digits !== '') {
                $candidates[] = ['value' => $value, 'number' => (int) $digits];
            }
        }

        return $candidates;
    }

    /** @param array<int,array{value:mixed,number:int}> $candidates */
    private function largestNotAbove(array $candidates, int $requested): mixed
    {
        usort($candidates, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        $pick = null;
        foreach ($candidates as $candidate) {
            if ($candidate['number'] <= $requested) {
                $pick = $candidate;
            }
        }

        return ($pick ?? $candidates[0])['value'];
    }
}
