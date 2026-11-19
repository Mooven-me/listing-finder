<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Dto\Ai\ListingEvaluationResultDto;
use App\Dto\Ai\TopicExpansionResultDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConfigurableLlmClient implements LlmClientInterface
{
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
    ) {
    }

    public function generateStructured(
        string $systemPrompt,
        string $userPrompt,
        string $dtoClass,
        array $base64Images = [],
    ): object {
        $activeModel = [] !== $base64Images ? $this->visionModel : $this->model;
        $rawContent = match (strtolower(trim($this->provider))) {
            'openai' => $this->callOpenAi($activeModel, $systemPrompt, $userPrompt, $base64Images),
            default => $this->callOllama($activeModel, $systemPrompt, $userPrompt, $dtoClass, $base64Images),
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

        return $dto;
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
    ): string {
        $userMessage = [
            'role' => 'user',
            'content' => $userPrompt,
        ];

        if ([] !== $base64Images) {
            $userMessage['images'] = array_values($base64Images);
        }

        $endpoint = rtrim($this->baseUrl, '/').'/api/chat';

        try {
            $payload = $this->sendOllamaChatRequest($endpoint, $model, $systemPrompt, $userMessage, $dtoClass);
        } catch (\Throwable $e) {
            // If a text-only model rejects attached images (HTTP 400), retry cleanly without images.
            if ([] !== $base64Images && isset($userMessage['images'])) {
                unset($userMessage['images']);
                try {
                    $payload = $this->sendOllamaChatRequest($endpoint, $model, $systemPrompt, $userMessage, $dtoClass);
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
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'price_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'working_condition_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'product_age_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'relevance_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'is_working' => ['type' => 'boolean'],
                    'estimated_age' => ['type' => 'string'],
                    'reasoning' => ['type' => 'string'],
                ],
                'required' => [
                    'score',
                    'price_score',
                    'working_condition_score',
                    'product_age_score',
                    'relevance_score',
                    'is_working',
                    'estimated_age',
                    'reasoning',
                ],
            ];
        }

        return 'json';
    }

    private function normalizeJsonForDto(string $json, string $dtoClass): string
    {
        if (TopicExpansionResultDto::class !== $dtoClass) {
            return $json;
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $json;
        }

        if (!\is_array($decoded) || !isset($decoded['sub_topics']) || !\is_array($decoded['sub_topics'])) {
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

    /**
     * @param list<string> $base64Images
     */
    private function callOpenAi(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $base64Images,
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
