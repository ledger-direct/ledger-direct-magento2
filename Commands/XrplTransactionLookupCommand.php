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

class XrplTransactionLookupCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'ledger-direct:xrpl-transaction:lookup';

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
    public function __construct(ChainServices $chains, MagentoConfigProvider $configProvider)
    {
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
        $this->setDescription('Transaction lookup on the XRP Ledger or Xahau');
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'Hash identifying a tx');
        $this->addOption('ctid', null, InputOption::VALUE_OPTIONAL, 'CTID identifying a validated tx');
        $this->addOption('chain', null, InputOption::VALUE_REQUIRED, 'XRPL (default) or XAHAU', ChainCatalog::XRPL);
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hash = $input->getOption('hash');
        $ctid = $input->getOption('ctid');

        if (!($hash xor $ctid)) {
            $output->writeln('Either a --hash or a --ctid is required as a parameter');

            return Command::FAILURE;
        }

        $chain = strtoupper((string) $input->getOption('chain'));
        $transaction = $this->chains->client($chain)->tx(
            (string) ($hash ?: $ctid),
            $this->configProvider->getNetwork($chain)
        );

        if ($transaction === null) {
            $output->writeln('Transaction not found on the ledger.');

            return Command::FAILURE;
        }

        $output->writeln(json_encode($transaction, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
