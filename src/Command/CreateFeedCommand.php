<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Command;

use Koh\SyliusAcp\ProductFeed\Api\FeedApiClient;
use Koh\SyliusAcp\ProductFeed\Api\FeedApiException;
use Koh\SyliusAcp\ProductFeed\FeedConfigurationException;
use Koh\SyliusAcp\ProductFeed\FeedScopeFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-off onboarding step: `POST /feeds` on the agent. The agent issues the
 * feed id; it goes into koh_acp.acp_feed.feed_id for every later push.
 */
#[AsCommand(name: 'koh:acp:feed:create', description: 'Create a feed on the configured agent-hosted Feed API and print its id.')]
final class CreateFeedCommand extends Command
{
    public function __construct(
        private readonly FeedScopeFactory $scopes,
        private readonly FeedApiClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel whose market the feed targets (default: koh_acp.acp_feed.channel, or the only enabled channel).')
            ->addOption('target-country', null, InputOption::VALUE_REQUIRED, 'ISO 3166-1 alpha-2 target market (default: configured, else derived from the channel).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $problem = $this->client->configurationProblem();
        if (null !== $problem) {
            $output->writeln('<error>ACP Feed API not configured: '.$problem.'</error>');

            return Command::FAILURE;
        }

        try {
            $channel = $input->getOption('channel');
            $country = $input->getOption('target-country');
            $scope = $this->scopes->create(\is_string($channel) ? $channel : null, null, \is_string($country) ? $country : null);
            $feed = $this->client->createFeed($scope->targetCountry, 'koh-acp-create-'.bin2hex(random_bytes(16)));
        } catch (FeedConfigurationException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        } catch (FeedApiException $exception) {
            $output->writeln('<error>Feed API: '.$exception->describe().'</error>');

            return Command::FAILURE;
        }

        $id = $feed['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            $output->writeln('<error>Feed API answered without a feed id.</error>');

            return Command::FAILURE;
        }

        $output->writeln($id);
        $output->writeln(\sprintf('<comment>Set koh_acp.acp_feed.feed_id to %s.</comment>', $id), OutputInterface::VERBOSITY_NORMAL);

        return Command::SUCCESS;
    }
}
