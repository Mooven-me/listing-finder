<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ProductTypeGatekeeperResultDto
{
    public function __construct(
        #[SerializedName('buyer_wants_item_type')]
        #[Assert\NotBlank]
        public string $buyerWantsItemType = 'product',
        #[SerializedName('listing_sells_item_type')]
        #[Assert\NotBlank]
        public string $listingSellsItemType = 'unknown',
        #[SerializedName('is_same_core_product')]
        public bool $isSameCoreProduct = true,
        #[SerializedName('is_accessory_or_component_only')]
        public bool $isAccessoryOrComponentOnly = false,
        #[SerializedName('brief_reason')]
        #[Assert\NotBlank]
        public string $briefReason = 'Checked product type.',
    ) {
    }
}
