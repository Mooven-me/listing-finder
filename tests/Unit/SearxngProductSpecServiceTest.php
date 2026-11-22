<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Ai\SearxngProductSpecService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearxngProductSpecServiceTest extends TestCase
{
    public function testExtractsModelQueriesAndSkipsGenericTitles(): void
    {
        $service = new SearxngProductSpecService(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), 'http://searxng:8080');

        self::assertSame('Switch Netgear FS605v3', $service->extractModelLookupQuery('Switch Netgear FS605v3'));
        self::assertSame('administrable Linksys LGS326', $service->extractModelLookupQuery('Switch administrable Linksys LGS326'));
        self::assertSame('Canon printer AX380', $service->extractModelLookupQuery('Vend Canon printer AX380 bon etat'));

        // Generic titles without concrete product model codes must return null
        self::assertNull($service->extractModelLookupQuery('Switch ethernet 1Gbps 1000mbps 5 ports'));
        self::assertNull($service->extractModelLookupQuery('Câble Ethernet 15m Cat6 RJ45 1Gbps'));
    }

    public function testQueriesSearxngJsonApiAndCachesResults(): void
    {
        $callCount = 0;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$callCount): MockResponse {
            ++$callCount;
            self::assertStringContainsString('format=json', $url);

            return new MockResponse((string) json_encode([
                'results' => [
                    [
                        'title' => 'Linksys LGS326 Specifications',
                        'content' => '24 Gigabit RJ45 ports plus 2 combo Gigabit SFP uplinks (1G, not SFP+). Fanless.',
                    ],
                ],
            ]));
        });

        $service = new SearxngProductSpecService($httpClient, new ArrayAdapter(), new NullLogger(), 'http://searxng:8080');

        $first = $service->lookupSpecsForTitle('Switch administrable Linksys LGS326');
        $second = $service->lookupSpecsForTitle('Switch administrable Linksys LGS326');

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertSame(1, $callCount);
        self::assertStringContainsString('2 combo Gigabit SFP uplinks', $first);
    }
}
