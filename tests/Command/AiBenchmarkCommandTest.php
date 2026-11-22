<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AiBenchmarkCommandTest extends KernelTestCase
{
    public function testExecuteBenchmarkRendersAllStagesAndHardwareProjections(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:ai:benchmark');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--spec' => 'Carte graphique avec minimum 16Go de VRAM',
            '--price' => '320',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Agentic AI Pipeline & Local Hardware Benchmark', $display);
        self::assertStringContainsString('Stage 0: TopicExpansionAgent', $display);
        self::assertStringContainsString('Stage 1: Core-Product Gatekeeper Pre-Scrape Filter', $display);
        self::assertStringContainsString('REJECTED IN STAGE 1', $display);
        self::assertStringContainsString('Stage 2: Reasoning-First Appraisal & Calibration Guardrails', $display);
        self::assertStringContainsString('Local AI Throughput & GPU VRAM Scaling Projection', $display);
        self::assertStringContainsString('Local GPU (16 GB VRAM - 2 Workers + Vision)', $display);
    }

    public function testExecuteBenchmarkJsonOutputContainsStructuredTelemetry(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:ai:benchmark');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--spec' => 'Switch reseau manageable 8 ports RJ45 et 2 ports SFP+',
            '--price' => '120',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('runtime', $payload);
        self::assertArrayHasKey('stage_0_topic_expansion', $payload);
        self::assertArrayHasKey('stage_1_gatekeeper_accessory_trap', $payload);
        self::assertArrayHasKey('stage_2_full_appraisal', $payload);
        self::assertArrayHasKey('projections_1000_listings_seconds', $payload);

        self::assertNotEmpty($payload['stage_0_topic_expansion']['sub_topics']);
        self::assertTrue($payload['stage_1_gatekeeper_accessory_trap']['rejected_before_scrape']);
        self::assertLessThanOrEqual(10, $payload['stage_1_gatekeeper_accessory_trap']['score']);
        self::assertGreaterThanOrEqual(50, $payload['stage_2_full_appraisal']['score_out_of_100']);
    }

    public function testExecuteBenchmarkRejectsInvalidTargetPrice(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:ai:benchmark');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--price' => '0',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
    }
}
