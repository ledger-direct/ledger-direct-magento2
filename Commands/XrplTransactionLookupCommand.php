<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use LedgerDirect\Service\XrplTransactionFileService;
use LedgerDirect\Service\XrplTxService;

class XrplTransactionLookupCommand extends Command
{
    public const COMMAND_NAME = 'ledger-direct:xrpl-transaction:lookup';

    protected XrplTxService $txService;

    public function __construct(
        XrplTxService $txService
    ) {
        parent::__construct();

        $this->txService = $txService;
    }

    /**
     * @inheritdoc
     */
    public function configure()
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription('XRPL transaction lookup');
        $this->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'Hash identifying a tx');
        $this->addOption('ctid', null, InputOption::VALUE_OPTIONAL, 'CTID identifying a validated tx');
        $this->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Tx source - XRPL, DB or BOTH');
        $this->addOption('write', null, InputOption::VALUE_OPTIONAL, 'Write result to file system');

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hash = $input->getOption('hash');
        $ctid  = $input->getOption('ctid');

        if ($hash xor $ctid) {

            $source = $input->getOption('source');

            if ($source === 'XRPL') {

                return Command::SUCCESS;
            } else if ($source === 'DB') {

                return Command::SUCCESS;
            }

            $output->writeln('The --source parameter has not been specified, i.e. XRPL or DB');

            return Command::SUCCESS;
        }

        $output->writeln('Either a --hash or a --ctid is required as a parameter');

        return Command::FAILURE;
    }
}
