<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Commands;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
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
     * @var XrplClient
     */
    private XrplClient $xrplClient;

    /**
     * @var MagentoConfigProvider
     */
    private MagentoConfigProvider $configProvider;

    /**
     * @param XrplClient $xrplClient
     * @param MagentoConfigProvider $configProvider
     */
    public function __construct(XrplClient $xrplClient, MagentoConfigProvider $configProvider)
    {
        $this->xrplClient = $xrplClient;
        $this->configProvider = $configProvider;

        parent::__construct(static::$defaultName);
    }

    /**
     * @inheritdoc
     */
    public function configure(): void
    {
        $this->setName(static::$defaultName);
        $this->setDescription('XRPL transaction lookup');
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'Hash identifying a tx');
        $this->addOption('ctid', null, InputOption::VALUE_OPTIONAL, 'CTID identifying a validated tx');
    }

    /**
     * Look a single transaction up on the ledger
     *
     * Exactly one of --hash or --ctid identifies it; the XRPL `tx` method accepts either.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hash = $input->getOption('hash');
        $ctid = $input->getOption('ctid');

        if (!($hash xor $ctid)) {
            $output->writeln('Either a --hash or a --ctid is required as a parameter');

            return Command::FAILURE;
        }

        $transaction = $this->xrplClient->tx(
            (string) ($hash ?: $ctid),
            $this->configProvider->getNetwork(MagentoConfigProvider::CHAIN)
        );

        if ($transaction === null) {
            $output->writeln('Transaction not found on the ledger.');

            return Command::FAILURE;
        }

        $output->writeln(json_encode($transaction, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
