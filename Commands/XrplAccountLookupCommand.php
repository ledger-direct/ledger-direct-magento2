<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Commands;

use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Port\MagentoConfigProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XrplAccountLookupCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'ledger-direct:xrpl-account:lookup';

    /**
     * @var XrplClient
     */
    private XrplClient $xrplClient;

    /**
     * @var SyncService
     */
    private SyncService $syncService;

    /**
     * @var MagentoConfigProvider
     */
    private MagentoConfigProvider $configProvider;

    /**
     * @param XrplClient $xrplClient
     * @param SyncService $syncService
     * @param MagentoConfigProvider $configProvider
     */
    public function __construct(
        XrplClient $xrplClient,
        SyncService $syncService,
        MagentoConfigProvider $configProvider
    ) {
        $this->xrplClient = $xrplClient;
        $this->syncService = $syncService;
        $this->configProvider = $configProvider;

        parent::__construct(static::$defaultName);
    }

    /**
     * @inheritdoc
     */
    public function configure(): void
    {
        $this->setName(static::$defaultName);
        $this->setDescription('XRPL account lookup: list an account\'s transactions, or sync them into the module');
        $this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account address');
        $this->addOption(
            'sync',
            null,
            InputOption::VALUE_NONE,
            'Sync incoming transactions into the module instead of printing them'
        );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accountAddress = (string) $input->getOption('account');
        $network = $this->configProvider->getNetwork(MagentoConfigProvider::CHAIN);

        if ($input->getOption('sync')) {
            $this->syncService->syncTransactions($accountAddress, $network);
            $output->writeln('Synced incoming transactions for ' . $accountAddress . ' on ' . $network . '.');

            return Command::SUCCESS;
        }

        $page = $this->xrplClient->fetchAccountTransactions($accountAddress, $network);
        $output->writeln(json_encode($page['transactions'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
