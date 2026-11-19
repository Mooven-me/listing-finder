<?php

declare(strict_types=1);

namespace App\Dto\Provider;

final readonly class ProviderOptionDefinition
{
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_SELECT = 'select';
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';

    /**
     * @param array<string, string> $choices Value => Display label (for TYPE_SELECT)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public mixed $default = null,
        public ?string $placeholder = null,
        public ?string $help = null,
        public array $choices = [],
        public ?string $dependsOn = null,
        public ?int $min = null,
        public ?int $max = null,
    ) {
    }
}
