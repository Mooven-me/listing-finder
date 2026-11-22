<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ConfigurableLlmClient::class)]
interface LlmClientInterface
{
    /**
     * @template T of object
     *
     * @param class-string<T> $dtoClass
     * @param list<string>    $base64Images
     *
     * @return T
     */
    public function generateStructured(
        string $systemPrompt,
        string $userPrompt,
        string $dtoClass,
        array $base64Images = [],
    ): object;

    public function supportsVision(): bool;

    public function getVisionModelName(): string;
}
