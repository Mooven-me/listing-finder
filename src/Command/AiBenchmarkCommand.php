<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Listing;
use App\Entity\SearchRequest;
use App\Enum\DealCategory;
use App\Service\Ai\ListingEvaluatorAgent;
use App\Service\Ai\LlmClientInterface;
use App\Service\Ai\TopicExpansionAgent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\SerializerInterface;

#[AsCommand(
    name: 'app:ai:benchmark',
    description: 'Run an end-to-end benchmark and verification of the three-stage Agentic AI pipeline (Topic Expansion, Stage-1 Gatekeeper, Stage-2 Reasoning Evaluator) and project Local GPU throughput.',
)]
final class AiBenchmarkCommand extends Command
{
    private const DEFAULT_SPEC = 'Je voudrais une carte graphique avec minimum 16Go de VRAM en bon etat de fonctionnement';
    private const DEFAULT_TARGET_PRICE = 350.0;

    public function __construct(
        private readonly TopicExpansionAgent $topicExpansionAgent,
        private readonly ListingEvaluatorAgent $listingEvaluatorAgent,
        private readonly LlmClientInterface $llmClient,
        private readonly SerializerInterface $serializer,
        #[Autowire(env: 'AI_PROVIDER')]
        private readonly string $aiProvider = 'ollama',
        #[Autowire(env: 'AI_BASE_URL')]
        private readonly string $aiBaseUrl = 'http://host.docker.internal:11434',
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $aiModel = 'lfm2.5:8b',
        #[Autowire(env: 'int:AI_NUM_CTX')]
        private readonly int $aiNumCtx = 8192,
        #[Autowire(env: 'int:AI_MIN_REQUEST_INTERVAL_MS')]
        private readonly int $minRequestIntervalMs = 0,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('spec', 's', InputOption::VALUE_REQUIRED, 'Product specification to benchmark', self::DEFAULT_SPEC)
            ->addOption('price', 'p', InputOption::VALUE_REQUIRED, 'Target price in EUR', (string) self::DEFAULT_TARGET_PRICE)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output benchmark telemetry as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $spec = trim((string) $input->getOption('spec'));
        if ('' === $spec) {
            $spec = self::DEFAULT_SPEC;
        }

        $targetPrice = (float) $input->getOption('price');
        if ($targetPrice <= 0) {
            $io->error('Target price must be greater than zero.');

            return Command::INVALID;
        }

        $visionSupported = $this->llmClient->supportsVision();
        $visionModel = $this->llmClient->getVisionModelName();

        // 1. Benchmark Topic Expansion Agent
        $t0 = microtime(true);
        $expansion = $this->topicExpansionAgent->expandTopic($spec, null, 6);
        $expansionMs = (int) round((microtime(true) - $t0) * 1000);

        $searchRequest = new SearchRequest(
            topic: $spec,
            targetPrice: $targetPrice,
            enableImages: $visionSupported,
            enabledProviders: ['leboncoin', 'vinted'],
        );
        $searchRequest->setGeneratedSubTopics($expansion->subTopics);

        $primarySubTopic = $expansion->subTopics[0] ?? 'produit occasion';
        $coreLabel = '' !== $expansion->coreProductNoun ? $expansion->coreProductNoun : $primarySubTopic;

        // 2. Benchmark Stage-1 Gatekeeper on an unrequested accessory trap vs. a core product listing
        $accessoryListing = new Listing(
            searchRequest: $searchRequest,
            provider: 'leboncoin',
            externalId: 'bench-accessory-001',
            searchTopicUsed: $primarySubTopic,
            title: sprintf('Cable adaptateur alimentation et boite vide pour %s', $coreLabel),
            price: 12.0,
            url: 'https://www.leboncoin.fr/ad/accessoires/bench001',
            description: sprintf('Cable adaptateur et accessoire seul pour %s.', $coreLabel),
            conditionState: 'Etat neuf',
        );

        $t1 = microtime(true);
        $accessoryGateResult = $this->listingEvaluatorAgent->checkCoreProductGatekeeper($searchRequest, $accessoryListing);
        $gatekeeperAccessoryMs = (int) round((microtime(true) - $t1) * 1000);

        // 3. Benchmark Stage-2 Reasoning-First Evaluation & Calibration Guardrails on a matching listing
        $candidateListing = new Listing(
            searchRequest: $searchRequest,
            provider: 'leboncoin',
            externalId: 'bench-product-002',
            searchTopicUsed: $primarySubTopic,
            title: sprintf('%s (%s) - Tres bon etat', $coreLabel, mb_strimwidth($spec, 0, 80, '')),
            price: round($targetPrice * 0.85, 2),
            url: 'https://www.leboncoin.fr/ad/produits/bench002',
            description: sprintf(
                'Vends %s en parfait etat de fonctionnement (2025). Specifications techniques verifiees : %s. 100%% fonctionnel, teste avant mise en vente, vendu complet pour %.2f EUR.',
                $coreLabel,
                $spec,
                round($targetPrice * 0.85, 2),
            ),
            conditionState: 'Tres bon etat',
        );

        $t2 = microtime(true);
        $evaluationResult = $this->listingEvaluatorAgent->evaluate($searchRequest, $candidateListing, runGatekeeper: true);
        $stage2EvaluationMs = (int) round((microtime(true) - $t2) * 1000);

        $totalPipelineMs = $expansionMs + $gatekeeperAccessoryMs + $stage2EvaluationMs;
        $category = DealCategory::fromScore($evaluationResult->score);

        // Compute 1,000-listing wave projections (assuming 35% filtered in Stage 1 and 65% evaluated in Stage 2)
        $effectiveStage2ComputeMs = max(250, $stage2EvaluationMs - $this->minRequestIntervalMs);
        $localSingleWorker1000AdsSec = (int) round((350 * max(15, $gatekeeperAccessoryMs) + 650 * $effectiveStage2ComputeMs) / 1000);
        $localDualWorker1000AdsSec = (int) round($localSingleWorker1000AdsSec / 2);
        $cloudThrottled1000AdsSec = (int) round((1000 * (9000 + $effectiveStage2ComputeMs)) / 1000);

        if ((bool) $input->getOption('json')) {
            $payload = [
                'runtime' => [
                    'provider' => $this->aiProvider,
                    'base_url' => $this->aiBaseUrl,
                    'model' => $this->aiModel,
                    'vision_model' => $visionModel,
                    'vision_supported' => $visionSupported,
                    'num_ctx' => $this->aiNumCtx,
                    'min_request_interval_ms' => $this->minRequestIntervalMs,
                ],
                'stage_0_topic_expansion' => [
                    'latency_ms' => $expansionMs,
                    'core_product_noun' => $expansion->coreProductNoun,
                    'core_product_synonyms' => $expansion->coreProductSynonyms,
                    'sub_topics' => $expansion->subTopics,
                ],
                'stage_1_gatekeeper_accessory_trap' => [
                    'latency_ms' => $gatekeeperAccessoryMs,
                    'rejected_before_scrape' => null !== $accessoryGateResult,
                    'score' => $accessoryGateResult?->score ?? 0,
                    'reasoning' => $accessoryGateResult?->reasoning ?? '',
                ],
                'stage_2_full_appraisal' => [
                    'latency_ms' => $stage2EvaluationMs,
                    'score_out_of_100' => $evaluationResult->score,
                    'category' => $category->value,
                    'sub_scores' => [
                        'relevance' => $evaluationResult->relevanceScore,
                        'price' => $evaluationResult->priceScore,
                        'working_condition' => $evaluationResult->workingConditionScore,
                        'product_age' => $evaluationResult->productAgeScore,
                    ],
                    'is_working' => $evaluationResult->isWorking,
                    'matched_specs' => $evaluationResult->matchedSpecs,
                    'missing_or_unverified_specs' => $evaluationResult->missingOrUnverifiedSpecs,
                    'reasoning' => $evaluationResult->reasoning,
                ],
                'projections_1000_listings_seconds' => [
                    'total_benchmark_ms' => $totalPipelineMs,
                    'local_gpu_1_worker_0ms_pacing_sec' => $localSingleWorker1000AdsSec,
                    'local_gpu_2_workers_16gb_32gb_vram_sec' => $localDualWorker1000AdsSec,
                    'cloud_free_tier_9000ms_pacing_sec' => $cloudThrottled1000AdsSec,
                ],
            ];

            $output->writeln($this->serializer->serialize($payload, 'json', [
                'json_encode_options' => \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
            ]));

            return Command::SUCCESS;
        }

        $io->title('Listing Finder - Agentic AI Pipeline & Local Hardware Benchmark');

        $io->section('1. Active AI Runtime & Hardware Configuration');
        $io->definitionList(
            ['AI Driver' => strtoupper($this->aiProvider)],
            ['Endpoint URL' => $this->aiBaseUrl],
            ['Reasoning Model' => $this->aiModel],
            ['Vision Model' => sprintf('%s (%s)', $visionModel, $visionSupported ? 'Vision Supported' : 'Text-Only / Vision Disabled')],
            ['Context Window (AI_NUM_CTX)' => sprintf('%d tokens', $this->aiNumCtx)],
            ['Outbound Pacing Interval' => sprintf('%d ms (%s)', $this->minRequestIntervalMs, 0 === $this->minRequestIntervalMs ? 'Unthrottled Local AI' : 'Rate-Limit Throttled')],
            ['Benchmark Specification' => $spec],
            ['Target Budget' => sprintf('%.2f EUR', $targetPrice)],
        );

        $io->section(sprintf('2. Stage 0: TopicExpansionAgent (%d ms)', $expansionMs));
        $io->definitionList(
            ['Extracted Core Noun' => '' !== $expansion->coreProductNoun ? $expansion->coreProductNoun : '(deterministic fallback)'],
            ['Core Synonyms' => [] !== $expansion->coreProductSynonyms ? implode(', ', $expansion->coreProductSynonyms) : 'None'],
            ['Generated Strict-AND Queries' => implode(' | ', $expansion->subTopics)],
        );

        $io->section(sprintf('3. Stage 1: Core-Product Gatekeeper Pre-Scrape Filter (%d ms)', $gatekeeperAccessoryMs));
        $io->table(
            ['Test Case', 'Listing Title', 'Gatekeeper Decision', 'Score', 'Detail Scrape & SearXNG'],
            [[
                'Unrequested Accessory Trap',
                mb_strimwidth($accessoryListing->getTitle(), 0, 52, '...'),
                null !== $accessoryGateResult ? 'REJECTED IN STAGE 1' : 'Passed',
                sprintf('%d/100 (POOR)', $accessoryGateResult?->score ?? 0),
                'Skipped (0 HTTP / 0 SearXNG calls)',
            ]],
        );

        $io->section(sprintf('4. Stage 2: Reasoning-First Appraisal & Calibration Guardrails (%d ms)', $stage2EvaluationMs));
        $io->table(
            ['Score', 'Tier', 'Price (vs Target)', 'Relevance', 'Price Score', 'Condition', 'Age Score', 'Works'],
            [[
                sprintf('%d/100', $evaluationResult->score),
                $category->label(),
                sprintf('%.2f EUR (Target: %.2f EUR)', $candidateListing->getPrice(), $targetPrice),
                sprintf('%d/100', $evaluationResult->relevanceScore),
                sprintf('%d/100', $evaluationResult->priceScore),
                sprintf('%d/100', $evaluationResult->workingConditionScore),
                sprintf('%d/100', $evaluationResult->productAgeScore),
                true === $evaluationResult->isWorking ? 'Yes' : 'No/Unknown',
            ]],
        );
        $io->block($evaluationResult->reasoning, 'STAGE-2 AI REASONING & EVIDENCE VERIFICATION', 'fg=white;bg=blue', '  ', true);

        $io->section('5. Local AI Throughput & GPU VRAM Scaling Projection (1,000-Listing Crawl Wave)');
        $io->table(
            ['Execution Profile', 'Pacing Interval', 'Recommended Models & Features', 'Projected Time (1,000 Ads)', 'Speedup vs Cloud'],
            [
                [
                    'Cloud Free Tier API (30 RPM / 8k TPM)',
                    '9,000 ms',
                    'Text-only API (Vision disabled, 1 worker)',
                    $this->formatDuration($cloudThrottled1000AdsSec),
                    '1.0x (Baseline)',
                ],
                [
                    'Local GPU (8-12 GB VRAM - 1 Worker)',
                    '0 ms',
                    '7B/8B Q4_K_M (lfm2.5:8b / qwen2.5:7b, 8k ctx)',
                    $this->formatDuration($localSingleWorker1000AdsSec),
                    sprintf('%.1fx faster', max(1.0, $cloudThrottled1000AdsSec / max(1, $localSingleWorker1000AdsSec))),
                ],
                [
                    'Local GPU (16 GB VRAM - 2 Workers + Vision)',
                    '0 ms',
                    '12B-14B + VLM (gemma3:12b / qwen2.5-vl:7b, 16k ctx)',
                    $this->formatDuration($localDualWorker1000AdsSec),
                    sprintf('%.1fx faster', max(1.0, $cloudThrottled1000AdsSec / max(1, $localDualWorker1000AdsSec))),
                ],
                [
                    'Local Workstation GPU (32 GB VRAM - Multi-Worker)',
                    '0 ms',
                    '27B-32B Dense + VLM (qwen2.5-vl:32b / gemma3:27b, 32k ctx)',
                    $this->formatDuration((int) round($localDualWorker1000AdsSec * 0.7)),
                    sprintf('%.1fx faster', max(1.0, $cloudThrottled1000AdsSec / max(1, (int) round($localDualWorker1000AdsSec * 0.7)))),
                ],
            ],
        );

        $io->success(sprintf(
            'Agentic AI pipeline benchmark completed in %d ms (Stage 0: %d ms | Stage 1: %d ms | Stage 2: %d ms).',
            $totalPipelineMs,
            $expansionMs,
            $gatekeeperAccessoryMs,
            $stage2EvaluationMs,
        ));

        return Command::SUCCESS;
    }

    private function formatDuration(int $totalSeconds): string
    {
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm %02ds', $hours, $minutes, $seconds);
        }

        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $seconds);
        }

        return sprintf('%ds', $seconds);
    }
}
