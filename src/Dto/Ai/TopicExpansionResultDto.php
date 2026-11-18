<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class TopicExpansionResultDto
{
    /**
     * @param list<string> $subTopics
     */
    public function __construct(
        #[SerializedName('sub_topics')]
        #[Assert\NotBlank]
        #[Assert\Count(min: 1, max: 15)]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(),
        ])]
        public array $subTopics = [],
    ) {
    }
}
