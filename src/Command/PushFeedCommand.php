<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Command;

use Koh\SyliusAcp\ProductFeed\Api\FeedApiClient;
use Koh\SyliusAcp\ProductFeed\Api\FeedApiException;
use Koh\SyliusAcp\ProductFeed\FeedConfigurationException;
use Koh\SyliusAcp\ProductFeed\FeedPusher;
use Koh\SyliusAcp\ProductFeed\FeedScopeFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ACP API upsert: pushes the catalogue to the agent-hosted Feed API. Meant for
 * a scheduler; without a configured Feed API it says so and exits 0, so a
 * schedule set up ahead of the configuration does nothing and fails nothing.
 */
#[AsCommand(name: 'koh:acp:feed:push', description: 'Upsert the ACP product feed of a channel to the configured agent-hosted Feed API.')]
final class PushFeedCommand extends Command
{
    public function __construct(
        private readonly FeedScopeFactory $scopes,
        private readonly FeedPusher $pusher,
        private readonly FeedApiClient $client,
        private readonly ?string $feedId = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel code (default: koh_acp.acp_feed.channel, or the only enabled channel).')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale code (default: koh_acp.acp_feed.locale, or the channel default).')
            ->addOption('feed-id', null, InputOption::VALUE_REQUIRED, 'Feed id on the agent (default: koh_acp.acp_feed.feed_id).')
            ->addOption('retire-missing', null, InputOption::VALUE_NONE, 'Read the agent\'s product set back and mark products the shop no longer publishes as discontinued.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('feed-id');
        $feedId = trim(\is_string($option) ? $option : (string) $this->feedId);

        if (!$this->client->isConfigured() && '' === $feedId) {
            $output->writeln('ACP Feed API not configured (koh_acp.acp_feed.api); nothing to push.');

            return Command::SUCCESS;
        }

        $problem = $this->client->configurationProblem() ?? ('' === $feedId ? 'koh_acp.acp_feed.feed_id is empty.' : null);
        if (null !== $problem) {
            $output->writeln('<error>ACP Feed API half configured: '.$problem.'</error>');

            return Command::FAILURE;
        }

        try {
            $scope = $this->scopes->create(self::option($input, 'channel'), self::option($input, 'locale'));
            $report = $this->pusher->push(
                $scope,
                $feedId,
                (bool) $input->getOption('retire-missing'),
                static function (int $count) use ($output): void {
                    if ($output->isVerbose()) {
                        $output->writeln(\sprintf('  %d products sent', $count));
                    }
                },
            );
        } catch (FeedConfigurationException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        } catch (FeedApiException $exception) {
            $output->writeln('<error>Feed API: '.$exception->describe().'</error>');

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'Upserted %d products of channel %s (%s) to feed %s in %d requests%s.',
            $report->products,
            (string) $scope->channel->getCode(),
            $scope->localeCode,
            $feedId,
            $report->requests,
            $report->retired > 0 ? \sprintf('; %d products marked discontinued', $report->retired) : '',
        ));

        return Command::SUCCESS;
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }
}
