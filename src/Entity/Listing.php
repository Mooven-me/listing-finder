<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ListingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ListingRepository::class)]
#[ORM\Table(name: 'listing')]
#[ORM\UniqueConstraint(name: 'uniq_listing_search_provider_external_id', columns: ['search_request_id', 'provider', 'external_id'])]
class Listing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SearchRequest::class, inversedBy: 'listings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SearchRequest $searchRequest;

    #[ORM\Column(length: 64)]
    private string $provider;

    #[ORM\Column(length: 191)]
    private string $externalId;

    #[ORM\Column(length: 255)]
    private string $searchTopicUsed;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::FLOAT)]
    private float $price;

    #[ORM\Column(length: 16)]
    private string $currency;

    #[ORM\Column(type: Types::TEXT)]
    private string $url;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $imageUrls = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $conditionState;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isComputed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    /**
     * @param list<string> $imageUrls
     */
    public function __construct(
        SearchRequest $searchRequest,
        string $provider,
        string $externalId,
        string $searchTopicUsed,
        string $title,
        float $price,
        string $url,
        ?string $description = null,
        string $currency = 'EUR',
        array $imageUrls = [],
        ?string $conditionState = null,
        ?\DateTimeImmutable $publishedAt = null,
    ) {
        $this->searchRequest = $searchRequest;
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->searchTopicUsed = $searchTopicUsed;
        $this->title = $title;
        $this->price = $price;
        $this->url = $url;
        $this->description = $description;
        $this->currency = $currency;
        $this->imageUrls = array_values($imageUrls);
        $this->conditionState = $conditionState;
        $this->publishedAt = $publishedAt;
        $this->fetchedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSearchRequest(): SearchRequest
    {
        return $this->searchRequest;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getSearchTopicUsed(): string
    {
        return $this->searchTopicUsed;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return list<string>
     */
    public function getImageUrls(): array
    {
        return $this->imageUrls;
    }

    /**
     * @param list<string> $imageUrls
     */
    public function setImageUrls(array $imageUrls): self
    {
        $this->imageUrls = array_values($imageUrls);

        return $this;
    }

    public function getConditionState(): ?string
    {
        return $this->conditionState;
    }

    public function setConditionState(?string $conditionState): self
    {
        $this->conditionState = $conditionState;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function isComputed(): bool
    {
        return $this->isComputed;
    }

    public function setIsComputed(bool $isComputed): self
    {
        $this->isComputed = $isComputed;

        return $this;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
}
