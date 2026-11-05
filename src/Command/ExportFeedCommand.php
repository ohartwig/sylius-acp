<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Command;

use Koh\SyliusAcp\ProductFeed\AcpFeedBuilder;
use Koh\SyliusAcp\ProductFeed\FeedConfigurationException;
use Koh\SyliusAcp\ProductFeed\FeedScopeFactory;
use Koh\SyliusAcp\ProductFeed\JsonlFeedWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ACP offline full replacement: writes `metadata.json` and `products.jsonl`
 * for one channel and locale. Handing the two files to the agent (upload,
 * bucket, SFTP — whatever the agent's onboarding names) is up to the caller.
 */
#[AsCommand(name: 'koh:acp:feed:export', description: 'Write the ACP product feed (metadata.json + products.jsonl) of a channel to a directory.')]
final class ExportFeedCommand extends Command
{
    /** @param ?\Closure(): \DateTimeImmutable $clock */
    public function __construct(
        private readonly FeedScopeFactory $scopes,
        private readonly AcpFeedBuilder $builder,
        private readonly JsonlFeedWriter $writer,
        private readonly ?string $feedId = null,
        private readonly ?\Closure $clock = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory to write metadata.json and products.jsonl to (created if missing).')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel code (default: koh_acp.acp_feed.channel, or the only enabled channel).')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale code (default: koh_acp.acp_feed.locale, or the channel default).')
            ->addOption('feed-id', null, InputOption::VALUE_REQUIRED, 'Feed id the agent issued (default: koh_acp.acp_feed.feed_id).')
            ->addOption('target-country', null, InputOption::VALUE_REQUIRED, 'ISO 3166-1 alpha-2 target market (default: configured, else derived from the channel).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $feedId = trim((string) ($input->getOption('feed-id') ?? $this->feedId));
        if ('' === $feedId) {
            $output->writeln('<error>No feed id: metadata.json needs the id the agent issued. Pass --feed-id or set koh_acp.acp_feed.feed_id.</error>');

            return Command::FAILURE;
        }

        try {
            $scope = $this->scopes->create(
                self::option($input, 'channel'),
                self::option($input, 'locale'),
                self::option($input, 'target-country'),
            );
            $metadata = $this->builder->metadata($feedId, $scope->targetCountry, null === $this->clock ? new \DateTimeImmutable() : ($this->clock)());
            $directory = (string) $input->getArgument('directory');
            $count = $this->writer->write($directory, $metadata, $this->builder->products($scope));
        } catch (FeedConfigurationException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'Wrote %d products of channel %s (%s) to %s/%s and %s.',
            $count,
            (string) $scope->channel->getCode(),
            $scope->localeCode,
            rtrim($directory, '/'),
            JsonlFeedWriter::PRODUCTS_FILE,
            JsonlFeedWriter::METADATA_FILE,
        ));

        return Command::SUCCESS;
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }
}
