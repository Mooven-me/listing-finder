<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;

class MockListingProvider implements ListingProviderInterface
{
    public function getName(): string
    {
        return 'mock';
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<FetchedListingDto>
     */
    public function search(string $query, ?float $maxPrice = null, ?int $limit = 25, array $options = []): array
    {
        return $this->generateRealisticFixtures($this->getName(), $query, $maxPrice, $limit ?? 10);
    }

    /**
     * @return list<\App\Dto\Provider\ProviderOptionDefinition>
     */
    public function getOptionDefinitions(): array
    {
        return [];
    }

    /**
     * @return list<FetchedListingDto>
     */
    public function generateRealisticFixtures(string $providerName, string $query, ?float $maxPrice = null, int $limit = 5): array
    {
        $cleanQuery = trim($query);
        if ('' === $cleanQuery) {
            return [];
        }

        $basePrice = null !== $maxPrice && $maxPrice > 0.0 ? $maxPrice : 80.0;
        $now = new \DateTimeImmutable();

        $templates = [
            1 => [
                'multiplier' => 0.68,
                'titleSuffix' => ' - Parfait etat garantie',
                'condition' => 'Parfait etat',
                'description' => sprintf(
                    '%s en parfait etat - teste 100%% fonctionnel, recent, facture fournie. Utilisation tres soignee, aucune rayure ni defaut technique, livrable avec tous les accessoires d\'origine.',
                    $cleanQuery
                ),
                'ageMinutes' => 25,
            ],
            2 => [
                'multiplier' => 0.82,
                'titleSuffix' => ' - Complet en boite d\'origine',
                'condition' => 'Tres bon etat',
                'description' => sprintf(
                    '%s en tres bon etat, complet en boite d\'origine, peu servi, generation recente. Fonctionne impeccablement, entretien regulier.',
                    $cleanQuery
                ),
                'ageMinutes' => 90,
            ],
            3 => [
                'multiplier' => 0.95,
                'titleSuffix' => ' - Bon etat fonctionnel',
                'condition' => 'Bon etat',
                'description' => sprintf(
                    '%s en bon etat general, fonctionne parfaitement, quelques traces d\'usure normale, modele de 3 ans. Vendu avec cable d\'alimentation.',
                    $cleanQuery
                ),
                'ageMinutes' => 240,
            ],
            4 => [
                'multiplier' => 1.45,
                'titleSuffix' => ' - Edition standard',
                'condition' => 'Etat correct',
                'description' => sprintf(
                    '%s en etat correct, vendu seul sans accessoires ni boite. Prix ferme.',
                    $cleanQuery
                ),
                'ageMinutes' => 720,
            ],
            5 => [
                'multiplier' => 0.40,
                'titleSuffix' => ' - POUR PIECES / HS',
                'condition' => 'Pour pieces',
                'description' => sprintf(
                    '%s POUR PIECES / HS - ne s\'allume plus suite a coupure, non teste. Ideal bricoleur ou recuperation de composants.',
                    $cleanQuery
                ),
                'ageMinutes' => 1440,
            ],
        ];

        $results = [];
        $count = max(1, min($limit, 20));

        for ($index = 1; $index <= $count; ++$index) {
            $templateKey = (($index - 1) % count($templates)) + 1;
            $template = $templates[$templateKey];

            $externalId = sha1($providerName.'|'.strtolower($cleanQuery).'|'.$index);
            $price = round($basePrice * $template['multiplier'], 2);
            $title = sprintf('%s%s', $cleanQuery, $template['titleSuffix']);
            $encodedText = rawurlencode(substr($cleanQuery, 0, 30).' #'.$index);

            $results[] = new FetchedListingDto(
                provider: $providerName,
                externalId: $externalId,
                title: $title,
                price: $price,
                url: sprintf('https://example.com/%s/listings/%s', rawurlencode($providerName), $externalId),
                description: $template['description'],
                currency: 'EUR',
                imageUrls: [
                    sprintf('https://placehold.co/600x400/png?text=%s', $encodedText),
                ],
                conditionState: $template['condition'],
                publishedAt: $now->modify(sprintf('-%d minutes', $template['ageMinutes'] * $index)),
            );
        }

        return $results;
    }
}
