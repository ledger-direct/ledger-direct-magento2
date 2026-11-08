<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Commands;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Model\ChainServices;
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
     * @var ChainServices
     */
    private ChainServices $chains;

    /**
     * @var MagentoConfigProvider
     */
    private MagentoConfigProvider $configProvider;

    /**
     * @param ChainServices $chains
     * @param MagentoConfigProvider $configProvider
     */
    public function __construct(
        ChainServices $chains,
        MagentoConfigProvider $configProvider
    ) {
        $this->chains = $chains;
        $this->configProvider = $configProvider;

        parent::__construct(static::$defaultName);
    }

    /**
     * @inheritdoc
     */
    public function configure(): void
    {
        $this->setName(static::$defaultName);
        $this->setDescription(
            'Account lookup on the XRP Ledger or Xahau: list an account\'s transactions, or sync them into the module'
        );
        $this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account address');
        $this->addOption('chain', null, InputOption::VALUE_REQUIRED, 'XRPL (default) or XAHAU', ChainCatalog::XRPL);
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
        $chain = strtoupper((string) $input->getOption('chain'));
        $network = $this->configProvider->getNetwork($chain);

        if ($input->getOption('sync')) {
            $this->chains->sync($chain)->syncTransactions($accountAddress, $network);
            $output->writeln(
                'Synced incoming transactions for ' . $accountAddress . ' on ' . $chain . ' ' . $network . '.'
            );

            return Command::SUCCESS;
        }

        $page = $this->chains->client($chain)->fetchAccountTransactions($accountAddress, $network);
        $output->writeln(json_encode($page['transactions'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
