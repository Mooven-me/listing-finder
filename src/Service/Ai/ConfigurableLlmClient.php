<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Dto\Ai\ListingEvaluationResultDto;
use App\Dto\Ai\ProductTypeGatekeeperResultDto;
use App\Dto\Ai\TopicExpansionResultDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConfigurableLlmClient implements LlmClientInterface
{
    private const VISION_CAPABILITY_CACHE_TTL = 600; // 10 minutes
    private const LLM_PACE_CACHE_KEY = 'llm_last_request_ts';

    private ?bool $visionSupportedMemo = null;
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        #[Autowire(env: 'AI_PROVIDER')]
        private readonly string $provider = 'ollama',
        #[Autowire(env: 'AI_BASE_URL')]
        private readonly string $baseUrl = 'http://host.docker.internal:11434',
        #[Autowire(env: 'AI_API_KEY')]
        private readonly string $apiKey = '',
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $model = 'lfm2.5:8b',
        #[Autowire(env: 'AI_VISION_MODEL')]
        private readonly string $visionModel = 'lfm2.5:8b',
        #[Autowire(env: 'int:AI_TIMEOUT')]
        private readonly int $timeout = 120,
        #[Autowire(env: 'int:AI_NUM_CTX')]
        private readonly int $numCtx = 8192,
        private readonly ?CacheInterface $cache = null,
        #[Autowire(env: 'AI_VISION_ENABLED')]
        private readonly string $visionEnabledMode = 'auto',
        #[Autowire(env: 'int:AI_MIN_REQUEST_INTERVAL_MS')]
        private readonly int $minRequestIntervalMs = 0,
    ) {
    }

    public function getVisionModelName(): string
    {
        return $this->visionModel;
    }

    public function supportsVision(): bool
    {
        if (null !== $this->visionSupportedMemo) {
            return $this->visionSupportedMemo;
        }

        $override = strtolower(trim($this->visionEnabledMode));
        if (\in_array($override, ['false', '0', 'no', 'off'], true)) {
            return $this->visionSupportedMemo = false;
        }
        if (\in_array($override, ['true', '1', 'yes', 'on'], true)) {
            return $this->visionSupportedMemo = true;
        }

        if ('openai' === strtolower(trim($this->provider))) {
            $modelLower = strtolower(trim($this->visionModel));

            // Only treat OpenAI-compatible models as vision-capable when their name signals multimodal support
            return $this->visionSupportedMemo = 1 === preg_match('/(vision|vl\b|gpt-4o|gpt-4\.1|gemini|gemma3|claude-3|claude-4|llama-4|pixtral|llava|minicpm-v)/i', $modelLower);
        }

        $cacheKey = 'ollama_vision_cap_'.sha1(rtrim($this->baseUrl, '/').'|'.trim($this->visionModel));

        try {
            if (null !== $this->cache) {
                /** @var bool $supported */
                $supported = $this->cache->get($cacheKey, function (ItemInterface $item): bool {
                    $item->expiresAfter(self::VISION_CAPABILITY_CACHE_TTL);

                    return $this->probeOllamaVisionCapability();
                });

                return $this->visionSupportedMemo = $supported;
            }

            return $this->visionSupportedMemo = $this->probeOllamaVisionCapability();
        } catch (\Throwable) {
            return $this->visionSupportedMemo = false;
        }
    }

    public function generateStructured(
        string $systemPrompt,
        string $userPrompt,
        string $dtoClass,
        array $base64Images = [],
    ): object {
        $this->throttleIfConfigured();

        $actuallyUsedImages = false;
        $activeModel = [] !== $base64Images ? $this->visionModel : $this->model;

        $rawContent = match (strtolower(trim($this->provider))) {
            'openai' => $this->callOpenAi($activeModel, $systemPrompt, $userPrompt, $base64Images, $actuallyUsedImages),
            default => $this->callOllama($activeModel, $systemPrompt, $userPrompt, $dtoClass, $base64Images, $actuallyUsedImages),
        };

        $cleanJson = $this->normalizeJsonForDto($this->extractJsonObject($rawContent), $dtoClass);

        try {
            /** @var object $dto */
            $dto = $this->serializer->deserialize($cleanJson, $dtoClass, 'json', [
                AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to deserialize LLM response into %s: %s', $dtoClass, $e->getMessage()), 0, $e);
        }

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            throw new \RuntimeException(sprintf('LLM response failed validation for %s: %s', $dtoClass, (string) $violations));
        }

        if ($dto instanceof ListingEvaluationResultDto) {
            /** @var object $dto */
            $dto = $dto->withImageAnalyzed($actuallyUsedImages);
        }

        return $dto;
    }

    private function throttleIfConfigured(): void
    {
        if ($this->minRequestIntervalMs <= 0) {
            return;
        }

        $isCli = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true);
        $effectiveIntervalMs = $isCli ? $this->minRequestIntervalMs : min($this->minRequestIntervalMs, 2000);

        $now = microtime(true);
        $last = $this->lastRequestAt;

        if (null !== $this->cache) {
            try {
                /** @var float $cachedLast */
                $cachedLast = $this->cache->get(self::LLM_PACE_CACHE_KEY, static function (ItemInterface $item): float {
                    $item->expiresAfter(60);

                    return 0.0;
                });
                $last = max($last, $cachedLast);
            } catch (\Throwable) {
                // fallback to in-memory timestamp
            }
        }

        $elapsedMs = ($now - $last) * 1000.0;
        if ($last > 0.0 && $elapsedMs < $effectiveIntervalMs) {
            $sleepUs = (int) round(($effectiveIntervalMs - $elapsedMs) * 1000.0);
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $recorded = microtime(true);
        $this->lastRequestAt = $recorded;

        if (null !== $this->cache) {
            try {
                $this->cache->delete(self::LLM_PACE_CACHE_KEY);
                $this->cache->get(self::LLM_PACE_CACHE_KEY, static function (ItemInterface $item) use ($recorded): float {
                    $item->expiresAfter(60);

                    return $recorded;
                });
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    private function probeOllamaVisionCapability(): bool
    {
        $endpoint = rtrim($this->baseUrl, '/').'/api/show';

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'timeout' => min(5, max(1, $this->timeout)),
                'json' => [
                    'name' => $this->visionModel,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                return false;
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);

            $capabilities = $payload['capabilities'] ?? null;
            if (\is_array($capabilities)) {
                foreach ($capabilities as $cap) {
                    if (\is_string($cap) && 'vision' === strtolower(trim($cap))) {
                        return true;
                    }
                }
            }

            $modelInfo = $payload['model_info'] ?? null;
            if (\is_array($modelInfo)) {
                foreach (array_keys($modelInfo) as $key) {
                    if (\is_string($key) && str_contains(strtolower($key), '.vision.')) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $base64Images
     */
    private function callOllama(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        string $dtoClass,
        array $base64Images,
        bool &$actuallyUsedImages = false,
    ): string {
        $userMessage = [
            'role' => 'user',
            'content' => $userPrompt,
        ];

        $sendImages = [] !== $base64Images;
        if ($sendImages) {
            $userMessage['images'] = array_values($base64Images);
        }

        $endpoint = rtrim($this->baseUrl, '/').'/api/chat';

        try {
            $payload = $this->sendOllamaChatRequest($endpoint, $model, $systemPrompt, $userMessage, $dtoClass);
            $actuallyUsedImages = $sendImages;
        } catch (\Throwable $e) {
            // If a text-only model rejects attached images (HTTP 400), retry cleanly without images.
            if ($sendImages && isset($userMessage['images'])) {
                unset($userMessage['images']);
                $this->visionSupportedMemo = false;
                try {
                    $payload = $this->sendOllamaChatRequest($endpoint, $model, $systemPrompt, $userMessage, $dtoClass);
                    $actuallyUsedImages = false;
                } catch (\Throwable $retryError) {
                    throw new \RuntimeException(sprintf('Ollama request to "%s" failed: %s', $endpoint, $retryError->getMessage()), 0, $retryError);
                }
            } else {
                throw new \RuntimeException(sprintf('Ollama request to "%s" failed: %s', $endpoint, $e->getMessage()), 0, $e);
            }
        }

        $content = $payload['message']['content'] ?? null;
        if (!\is_string($content) || '' === trim($content)) {
            throw new \RuntimeException('Ollama returned an empty or malformed message content.');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $userMessage
     *
     * @return array<string, mixed>
     */
    private function sendOllamaChatRequest(
        string $endpoint,
        string $model,
        string $systemPrompt,
        array $userMessage,
        string $dtoClass,
    ): array {
        $isCli = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true);
        $response = $this->httpClient->request('POST', $endpoint, [
            'timeout' => $isCli ? $this->timeout : min($this->timeout, 15),
            'json' => [
                'model' => $model,
                'stream' => false,
                'options' => [
                    'temperature' => 0.2,
                    'num_ctx' => max(2048, $this->numCtx),
                ],
                'format' => $this->resolveOllamaFormat($dtoClass),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    $userMessage,
                ],
            ],
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $response->toArray();

        return $payload;
    }

    /**
     * @return string|array<string, mixed>
     */
    private function resolveOllamaFormat(string $dtoClass): string|array
    {
        if (TopicExpansionResultDto::class === $dtoClass) {
            return [
                'type' => 'object',
                'properties' => [
                    'core_product_noun' => ['type' => 'string'],
                    'core_product_synonyms' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'sub_topics' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                        'maxItems' => 15,
                    ],
                ],
                'required' => ['core_product_noun', 'core_product_synonyms', 'sub_topics'],
            ];
        }

        if (ProductTypeGatekeeperResultDto::class === $dtoClass) {
            return [
                'type' => 'object',
                'properties' => [
                    'buyer_wants_item_type' => ['type' => 'string'],
                    'listing_sells_item_type' => ['type' => 'string'],
                    'is_same_core_product' => ['type' => 'boolean'],
                    'is_accessory_or_component_only' => ['type' => 'boolean'],
                    'brief_reason' => ['type' => 'string'],
                ],
                'required' => [
                    'buyer_wants_item_type',
                    'listing_sells_item_type',
                    'is_same_core_product',
                    'is_accessory_or_component_only',
                    'brief_reason',
                ],
            ];
        }

        if (ListingEvaluationResultDto::class === $dtoClass) {
            return [
                'type' => 'object',
                'properties' => [
                    'reasoning' => ['type' => 'string'],
                    'buyer_requested_product_type' => ['type' => 'string'],
                    'listing_actual_product_type' => ['type' => 'string'],
                    'is_wrong_product_or_accessory' => ['type' => 'boolean'],
                    'matched_specs' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'missing_or_unverified_specs' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'is_working' => ['type' => 'boolean'],
                    'estimated_age' => ['type' => 'string'],
                    'relevance_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'price_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'working_condition_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'product_age_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                ],
                'required' => [
                    'reasoning',
                    'buyer_requested_product_type',
                    'listing_actual_product_type',
                    'is_wrong_product_or_accessory',
                    'matched_specs',
                    'missing_or_unverified_specs',
                    'is_working',
                    'estimated_age',
                    'relevance_score',
                    'price_score',
                    'working_condition_score',
                    'product_age_score',
                    'score',
                ],
            ];
        }

        return 'json';
    }

    private function normalizeJsonForDto(string $json, string $dtoClass): string
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $json;
        }

        if (!\is_array($decoded)) {
            return $json;
        }

        if (TopicExpansionResultDto::class === $dtoClass) {
            if (isset($decoded['sub_topics']) && \is_array($decoded['sub_topics'])) {
                $flattened = [];
                array_walk_recursive($decoded['sub_topics'], static function (mixed $item) use (&$flattened): void {
                    if (\is_string($item) && '' !== trim($item)) {
                        $flattened[] = trim($item);
                    }
                });
                $decoded['sub_topics'] = $flattened;
            }

            if (isset($decoded['core_product_synonyms'])) {
                $syns = [];
                if (\is_array($decoded['core_product_synonyms'])) {
                    array_walk_recursive($decoded['core_product_synonyms'], static function (mixed $item) use (&$syns): void {
                        if (\is_string($item) && '' !== trim($item)) {
                            $syns[] = trim($item);
                        }
                    });
                } elseif (\is_string($decoded['core_product_synonyms']) && '' !== trim($decoded['core_product_synonyms'])) {
                    $syns[] = trim($decoded['core_product_synonyms']);
                }
                $decoded['core_product_synonyms'] = array_values(array_unique($syns));
            }

            return (string) json_encode($decoded, \JSON_UNESCAPED_UNICODE);
        }

        if (ProductTypeGatekeeperResultDto::class === $dtoClass) {
            foreach (['is_same_core_product', 'is_accessory_or_component_only'] as $boolField) {
                if (isset($decoded[$boolField]) && !\is_bool($decoded[$boolField])) {
                    $decoded[$boolField] = filter_var($decoded[$boolField], \FILTER_VALIDATE_BOOLEAN);
                }
            }

            return (string) json_encode($decoded, \JSON_UNESCAPED_UNICODE);
        }

        if (ListingEvaluationResultDto::class === $dtoClass) {
            foreach (['matched_specs', 'missing_or_unverified_specs'] as $listField) {
                if (!isset($decoded[$listField])) {
                    continue;
                }
                if (!\is_array($decoded[$listField])) {
                    $decoded[$listField] = \is_string($decoded[$listField]) && '' !== trim($decoded[$listField])
                        ? [trim($decoded[$listField])]
                        : [];
                    continue;
                }

                $cleanList = [];
                array_walk_recursive($decoded[$listField], static function (mixed $item) use (&$cleanList): void {
                    if (\is_string($item) && '' !== trim($item)) {
                        $trimmed = trim($item);
                        if (!\in_array(strtolower($trimmed), ['none', 'n/a', 'null', 'nothing', 'aucun', 'aucune'], true)) {
                            $cleanList[] = $trimmed;
                        }
                    }
                });
                $decoded[$listField] = array_values(array_unique($cleanList));
            }

            if (isset($decoded['is_wrong_product_or_accessory']) && !\is_bool($decoded['is_wrong_product_or_accessory'])) {
                $decoded['is_wrong_product_or_accessory'] = filter_var($decoded['is_wrong_product_or_accessory'], \FILTER_VALIDATE_BOOLEAN);
            }

            foreach (['score', 'price_score', 'working_condition_score', 'product_age_score', 'relevance_score'] as $intField) {
                if (isset($decoded[$intField]) && is_numeric($decoded[$intField])) {
                    $decoded[$intField] = max(0, min(100, (int) round((float) $decoded[$intField])));
                }
            }

            return (string) json_encode($decoded, \JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }

    /**
     * @param list<string> $base64Images
     */
    private function callOpenAi(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $base64Images,
        bool &$actuallyUsedImages = false,
    ): string {
        $userContent = $userPrompt;
        if ([] !== $base64Images) {
            $contentParts = [
                [
                    'type' => 'text',
                    'text' => $userPrompt,
                ],
            ];

            foreach ($base64Images as $base64Image) {
                $contentParts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => sprintf('data:image/jpeg;base64,%s', $base64Image),
                    ],
                ];
            }

            $userContent = $contentParts;
            $actuallyUsedImages = true;
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if ('' !== trim($this->apiKey)) {
            $headers['Authorization'] = sprintf('Bearer %s', trim($this->apiKey));
        }

        $endpoint = rtrim($this->baseUrl, '/').'/chat/completions';
        if (!str_ends_with(rtrim($this->baseUrl, '/'), '/v1')) {
            $endpoint = rtrim($this->baseUrl, '/').'/v1/chat/completions';
        }

        $isCli = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true);
        $maxAttempts = $isCli ? 3 : 2;
        $requestTimeout = $isCli ? $this->timeout : min($this->timeout, 10);
        $maxRetrySleepSec = $isCli ? 10 : 3;
        $maxWallClockBudgetSec = $isCli ? max(15.0, (float) $this->timeout) : 12.0;
        $startedAt = microtime(true);
        $payload = null;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    'timeout' => $requestTimeout,
                    'headers' => $headers,
                    'json' => [
                        'model' => $model,
                        'temperature' => 0.2,
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $systemPrompt,
                            ],
                            [
                                'role' => 'user',
                                'content' => $userContent,
                            ],
                        ],
                    ],
                ]);

                $statusCode = $response->getStatusCode();
            } catch (\Throwable $e) {
                $elapsed = microtime(true) - $startedAt;
                $retrySleep = min($maxRetrySleepSec, $attempt * 2);
                if ($attempt >= $maxAttempts || ($elapsed + $retrySleep + 1.0) >= $maxWallClockBudgetSec) {
                    throw new \RuntimeException(sprintf('OpenAI-compatible request to "%s" failed: %s', $endpoint, $e->getMessage()), 0, $e);
                }
                sleep($retrySleep);

                continue;
            }

            if (429 === $statusCode && $attempt < $maxAttempts) {
                $respHeaders = $response->getHeaders(false);
                $retryAfterRaw = $respHeaders['retry-after'][0] ?? null;

                // Fail fast if upstream Retry-After exceeds our retry window (e.g. daily RPD quota exhausted)
                if (is_numeric($retryAfterRaw) && (float) $retryAfterRaw > $maxRetrySleepSec) {
                    $errBody = mb_strimwidth($response->getContent(false), 0, 300, '...');
                    throw new \RuntimeException(sprintf('OpenAI-compatible request to "%s" failed: HTTP 429 (Retry-After %ss): %s', $endpoint, (string) $retryAfterRaw, $errBody));
                }

                $sleepSeconds = is_numeric($retryAfterRaw)
                    ? max(1, min($maxRetrySleepSec, (int) ceil((float) $retryAfterRaw)))
                    : min($maxRetrySleepSec, $attempt * 2);

                $elapsed = microtime(true) - $startedAt;
                if (($elapsed + $sleepSeconds + 1.0) < $maxWallClockBudgetSec) {
                    sleep($sleepSeconds);

                    continue;
                }
            }

            if ($statusCode >= 500 && $attempt < $maxAttempts) {
                $retrySleep = min($maxRetrySleepSec, $attempt * 2);
                $elapsed = microtime(true) - $startedAt;
                if (($elapsed + $retrySleep + 1.0) < $maxWallClockBudgetSec) {
                    sleep($retrySleep);

                    continue;
                }
            }

            if ($statusCode >= 400) {
                $errBody = mb_strimwidth($response->getContent(false), 0, 300, '...');
                throw new \RuntimeException(sprintf('OpenAI-compatible request to "%s" failed: HTTP %d from "%s": %s', $endpoint, $statusCode, $endpoint, $errBody));
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = $response->toArray(false);
                $payload = $decoded;
                break;
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('OpenAI-compatible request to "%s" failed: %s', $endpoint, $e->getMessage()), 0, $e);
            }
        }

        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (!\is_string($content) || '' === trim($content)) {
            throw new \RuntimeException('OpenAI-compatible endpoint returned an empty or malformed response.');
        }

        return $content;
    }

    private function extractJsonObject(string $rawContent): string
    {
        $trimmed = trim($rawContent);

        if (1 === preg_match('/```(?:json)?\s*(.*?)\s*```/is', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        $firstBrace = strpos($trimmed, '{');
        $lastBrace = strrpos($trimmed, '}');

        if (false !== $firstBrace && false !== $lastBrace && $lastBrace > $firstBrace) {
            return substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return $trimmed;
    }
}
