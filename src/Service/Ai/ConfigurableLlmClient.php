<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Dto\Ai\ListingEvaluationResultDto;
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

    private ?bool $visionSupportedMemo = null;

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

        if ('openai' === strtolower(trim($this->provider))) {
            return $this->visionSupportedMemo = true;
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
        $response = $this->httpClient->request('POST', $endpoint, [
            'timeout' => $this->timeout,
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
                    'sub_topics' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                        'maxItems' => 15,
                    ],
                ],
                'required' => ['sub_topics'],
            ];
        }

        if (ListingEvaluationResultDto::class === $dtoClass) {
            return [
                'type' => 'object',
                'properties' => [
                    'reasoning' => ['type' => 'string'],
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
            if (!isset($decoded['sub_topics']) || !\is_array($decoded['sub_topics'])) {
                return $json;
            }

            $flattened = [];
            array_walk_recursive($decoded['sub_topics'], static function (mixed $item) use (&$flattened): void {
                if (\is_string($item) && '' !== trim($item)) {
                    $flattened[] = trim($item);
                }
            });

            $decoded['sub_topics'] = $flattened;

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

        $endpoint = rtrim($this->baseUrl, '/').'/v1/chat/completions';

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'timeout' => $this->timeout,
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

            $payload = $response->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('OpenAI-compatible request to "%s" failed: %s', $endpoint, $e->getMessage()), 0, $e);
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
