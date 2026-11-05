<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\ProductFeed;

use Koh\SyliusAcp\Command\CreateFeedCommand;
use Koh\SyliusAcp\Command\ExportFeedCommand;
use Koh\SyliusAcp\Command\PushFeedCommand;
use Koh\SyliusAcp\ProductFeed\AcpFeedBuilder;
use Koh\SyliusAcp\ProductFeed\AcpProductMapper;
use Koh\SyliusAcp\ProductFeed\Api\FeedApiClient;
use Koh\SyliusAcp\ProductFeed\CatalogueStream;
use Koh\SyliusAcp\ProductFeed\FeedPusher;
use Koh\SyliusAcp\ProductFeed\FeedScopeFactory;
use Koh\SyliusAcp\ProductFeed\JsonlFeedWriter;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ExportFeedCommand::class)]
#[CoversClass(PushFeedCommand::class)]
#[CoversClass(CreateFeedCommand::class)]
final class FeedCommandsTest extends TestCase
{
    /** @var list<array{string, string, string, string}> */
    private array $requests = [];

    private string $directory = '';

    protected function tearDown(): void
    {
        // Only ever the directory a test created under the temp dir.
        if ('' === $this->directory || !str_starts_with($this->directory, sys_get_temp_dir().'/koh-acp-export-') || !is_dir($this->directory)) {
            return;
        }
        foreach ([JsonlFeedWriter::METADATA_FILE, JsonlFeedWriter::PRODUCTS_FILE] as $file) {
            if (is_file($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }
        rmdir($this->directory);
    }

    public function testExportWritesTheSnapshot(): void
    {
        $this->directory = sys_get_temp_dir().'/koh-acp-export-'.bin2hex(random_bytes(4));
        $tester = new CommandTester(new ExportFeedCommand(
            $this->scopes(),
            $this->builder(),
            new JsonlFeedWriter(),
            'feed_configured',
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-05T09:30:00Z'),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['directory' => $this->directory, '--target-country' => 'at']));

        self::assertSame('Wrote 1 products of channel WEB (de_DE) to '.$this->directory.'/products.jsonl and metadata.json.', trim($tester->getDisplay()));
        $metadata = json_decode((string) file_get_contents($this->directory.'/metadata.json'), true);
        self::assertSame(['id' => 'feed_configured', 'target_country' => 'AT', 'updated_at' => '2026-11-05T09:30:00Z'], $metadata);
        $line = trim((string) file_get_contents($this->directory.'/products.jsonl'));
        AcpFeedSchema::assertValid(json_decode($line), 'Product');
    }

    public function testExportTakesTheFeedIdOptionAndTheDefaultClock(): void
    {
        $this->directory = sys_get_temp_dir().'/koh-acp-export-'.bin2hex(random_bytes(4));
        $tester = new CommandTester(new ExportFeedCommand($this->scopes(), $this->builder(), new JsonlFeedWriter(), 'feed_configured'));

        self::assertSame(Command::SUCCESS, $tester->execute(['directory' => $this->directory.'/', '--feed-id' => 'feed_cli', '--locale' => 'en_US', '--channel' => 'WEB']));
        self::assertStringContainsString(' to '.$this->directory.'/products.jsonl and', $tester->getDisplay());

        $metadata = json_decode((string) file_get_contents($this->directory.'/metadata.json'), true);
        self::assertIsArray($metadata);
        self::assertSame('feed_cli', $metadata['id']);
        self::assertSame('DE', $metadata['target_country']);
        self::assertEqualsWithDelta(time(), strtotime((string) $metadata['updated_at']), 60);
        self::assertStringContainsString('(en_US)', $tester->getDisplay());
    }

    public function testExportNeedsAFeedId(): void
    {
        $tester = new CommandTester(new ExportFeedCommand($this->scopes(), $this->builder(), new JsonlFeedWriter(), ' '));

        self::assertSame(Command::FAILURE, $tester->execute(['directory' => sys_get_temp_dir()]));
        self::assertStringContainsString('No feed id', $tester->getDisplay());
    }

    public function testExportReportsAConfigurationError(): void
    {
        $tester = new CommandTester(new ExportFeedCommand($this->scopes(), $this->builder(), new JsonlFeedWriter(), 'f'));

        self::assertSame(Command::FAILURE, $tester->execute(['directory' => sys_get_temp_dir(), '--locale' => 'fr_FR']));
        self::assertSame("Locale \"fr_FR\" is not enabled on channel \"WEB\" (enabled: de_DE, en_US).\n", $tester->getDisplay());
    }

    public function testPushDoesNothingWhileUnconfigured(): void
    {
        $tester = new CommandTester($this->push('', '', null));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('ACP Feed API not configured (koh_acp.acp_feed.api); nothing to push.', trim($tester->getDisplay()));
        self::assertSame([], $this->requests);
    }

    public function testPushRefusesAHalfConfiguration(): void
    {
        foreach ([
            [['https://agent.example', 'token', ' '], 'koh_acp.acp_feed.feed_id is empty.'],
            [['https://agent.example', '', 'feed_1'], 'koh_acp.acp_feed.api.bearer_token is empty.'],
            [['', '', 'feed_1'], 'koh_acp.acp_feed.api.base_url is empty.'],
            [['http://agent.example', 't', ''], 'koh_acp.acp_feed.api.base_url must be an https:// URL (ACP requires HTTPS).'],
        ] as [[$base, $token, $feed], $problem]) {
            $tester = new CommandTester($this->push($base, $token, $feed));
            self::assertSame(Command::FAILURE, $tester->execute([]), $base.'|'.$token.'|'.$feed);
            self::assertSame('ACP Feed API half configured: '.$problem."\n", $tester->getDisplay());
        }
        self::assertSame([], $this->requests);
    }

    public function testPushUpsertsAndReports(): void
    {
        $tester = new CommandTester($this->push('https://agent.example', 'token', 'feed_1', retireAnswer: '{"products":[{"id":"OLD","variants":[{"id":"O","title":"O"}]}]}'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--retire-missing' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));

        self::assertSame(
            "  1 products sent\nUpserted 1 products of channel WEB (de_DE) to feed feed_1 in 2 requests; 1 products marked discontinued.\n",
            $tester->getDisplay(),
        );
        self::assertSame(['GET', 'PATCH', 'GET', 'PATCH'], array_column($this->requests, 0));
    }

    public function testPushTakesTheFeedIdOptionAndIsQuietWithoutVerbosity(): void
    {
        $tester = new CommandTester($this->push('https://agent.example', 'token', 'feed_configured'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--feed-id' => ' feed_cli ']));
        self::assertSame('Upserted 1 products of channel WEB (de_DE) to feed feed_cli in 1 requests.', trim($tester->getDisplay()));
        self::assertStringEndsWith('/feeds/feed_cli', $this->requests[0][1]);
    }

    public function testPushReportsTheAgentsError(): void
    {
        $tester = new CommandTester($this->push('https://agent.example', 'token', 'feed_1', feedAnswer: new MockResponse('{"type":"invalid_request","code":"feed_not_found","message":"Feed not found","param":"id"}', ['http_code' => 404])));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame("Feed API: HTTP 404 invalid_request/feed_not_found: Feed not found (param: id)\n", $tester->getDisplay());
    }

    public function testPushReportsAConfigurationError(): void
    {
        $tester = new CommandTester($this->push('https://agent.example', 'token', 'feed_1'));

        self::assertSame(Command::FAILURE, $tester->execute(['--channel' => 'NOPE']));
        self::assertSame("Channel \"NOPE\" does not exist.\n", $tester->getDisplay());
        self::assertSame([], $this->requests);
    }

    public function testCreatePrintsTheNewFeedId(): void
    {
        $tester = new CommandTester(new CreateFeedCommand($this->scopes(), $this->client('https://agent.example', 'token', createAnswer: new MockResponse('{"id":"feed_new","target_country":"DE"}', ['http_code' => 201]))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("feed_new\nSet koh_acp.acp_feed.feed_id to feed_new.", trim($tester->getDisplay()));
        self::assertSame([['POST', 'https://agent.example/feeds']], array_map(static fn (array $r): array => [$r[0], $r[1]], $this->requests));
        self::assertSame('{"target_country":"DE"}', $this->requests[0][2]);
        self::assertMatchesRegularExpression('/^koh-acp-create-[0-9a-f]{32}$/', $this->requests[0][3]);
    }

    public function testCreateWithAnExplicitMarket(): void
    {
        $tester = new CommandTester(new CreateFeedCommand($this->scopes(), $this->client('https://agent.example', 'token', createAnswer: new MockResponse('{"id":"feed_ch"}', ['http_code' => 201]))));

        self::assertSame(Command::SUCCESS, $tester->execute(['--target-country' => 'CH', '--channel' => 'WEB']));
        $unknown = new CommandTester(new CreateFeedCommand($this->scopes(), $this->client('https://agent.example', 'token')));
        self::assertSame(Command::FAILURE, $unknown->execute(['--channel' => 'NOPE']));
        self::assertSame('{"target_country":"CH"}', $this->requests[0][2]);
    }

    public function testCreateFailures(): void
    {
        $unconfigured = new CommandTester(new CreateFeedCommand($this->scopes(), $this->client('', '')));
        self::assertSame(Command::FAILURE, $unconfigured->execute([]));
        self::assertSame("ACP Feed API not configured: koh_acp.acp_feed.api.base_url is empty.\n", $unconfigured->getDisplay());

        $badScope = new CommandTester(new CreateFeedCommand($this->scopes(), $this->client('https://agent.example', 'token')));
        self::assertSame(Command::FAILURE, $badScope->execute(['--target-country' => 'XYZ']));
        self::assertSame("Target country \"XYZ\" is not an ISO 3166-1 alpha-2 code.\n", $badScope->getDisplay());

        $refused = new CommandTester(new CreateFeedCommand($this->scopes(), $this->client('https://agent.example', 'token', createAnswer: new MockResponse('{"type":"invalid_request","code":"invalid_target_country","message":"no","param":"target_country"}', ['http_code' => 400]))));
        self::assertSame(Command::FAILURE, $refused->execute([]));
        self::assertSame("Feed API: HTTP 400 invalid_request/invalid_target_country: no (param: target_country)\n", $refused->getDisplay());

        $noId = new CommandTester(new CreateFeedCommand($this->scopes(), $this->client('https://agent.example', 'token', createAnswer: new MockResponse('{"id":""}', ['http_code' => 201]))));
        self::assertSame(Command::FAILURE, $noId->execute([]));
        self::assertSame("Feed API answered without a feed id.\n", $noId->getDisplay());
        self::assertCount(2, $this->requests, 'only the two calls that got past the checks');
    }

    private function push(string $base, string $token, ?string $feedId, ?MockResponse $feedAnswer = null, string $retireAnswer = '{"products":[]}'): PushFeedCommand
    {
        $client = $this->client($base, $token, $feedAnswer, $retireAnswer);

        return new PushFeedCommand($this->scopes(), new FeedPusher($this->builder(), $client), $client, $feedId);
    }

    private function client(string $base, string $token, ?MockResponse $feedAnswer = null, string $retireAnswer = '{"products":[]}', ?MockResponse $createAnswer = null): FeedApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($feedAnswer, $retireAnswer, $createAnswer): MockResponse {
            $key = '';
            foreach ((array) ($options['headers'] ?? []) as $header) {
                if (str_starts_with(strtolower((string) $header), 'idempotency-key: ')) {
                    $key = substr((string) $header, 17);
                }
            }
            $this->requests[] = [$method, $url, (string) ($options['body'] ?? ''), $key];

            return match (true) {
                'POST' === $method => $createAnswer ?? new MockResponse('{"id":"x"}', ['http_code' => 201]),
                'PATCH' === $method => new MockResponse('{"id":"feed_1","accepted":true}'),
                str_ends_with($url, '/products') => new MockResponse($retireAnswer),
                default => $feedAnswer ?? new MockResponse('{"id":"feed_1"}'),
            };
        });

        return new FeedApiClient($http, $base, $token, '2026-04-17', sleep: static function (): void {});
    }

    private function builder(): AcpFeedBuilder
    {
        $product = FeedFixtures::product();
        FeedFixtures::variant($product, 'CLASSIC_TEE_M');
        $product->setCurrentLocale('en_US');
        $product->setName('Classic Tee');
        $product->setSlug('classic-tee');

        $stream = $this->createStub(CatalogueStream::class);
        $stream->method('products')->willReturn([$product]);

        return new AcpFeedBuilder($stream, new AcpProductMapper(new AvailabilityChecker()));
    }

    private function scopes(): FeedScopeFactory
    {
        $channel = FeedFixtures::channel();
        $repository = $this->createStub(ChannelRepositoryInterface::class);
        $repository->method('findEnabled')->willReturn([$channel]);
        $repository->method('findOneByCode')->willReturnCallback(static fn (string $code): ?Channel => 'WEB' === $code ? $channel : null);
        $contexts = $this->createStub(AgentContextFactoryInterface::class);
        $contexts->method('create')->willReturnCallback(static fn (?ChannelInterface $c = null): AgentContext => new AgentContext($c ?? $channel, 'https://shop.example.com', 'de_DE'));

        return new FeedScopeFactory($repository, $contexts);
    }
}
