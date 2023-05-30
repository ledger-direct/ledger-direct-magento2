<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Commands;

use Hardcastle\LedgerDirect\Service\XrplTxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class XrplAccountLookupCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrpl-account:lookup';

    protected XrplTxService $txService;

    public function __construct(
        XrplTxService $txService
    ) {
        $this->txService = $txService;

        parent::__construct(static::$defaultName);
    }

    /**
     * @inheritdoc
     */
    public function configure(): void
    {
        $this->setName(static::$defaultName);
        $this->setDescription('XRPL account lookup');
        $this->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account address');
        $this->addOption('write', null, InputOption::VALUE_OPTIONAL, 'Write result to file system');
        $this->addOption('sync', null, InputOption::VALUE_OPTIONAL, 'Write result to file system');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $accountAddress = $input->getOption('account');
        $write = $input->getOption('write') ?? false;
        $sync = $input->getOption('sync') ?? false;

        if ($sync) {
            $this->txService->syncAccountTransactions($accountAddress);
        } else {
            $accountTxResult = $this->txService->fetchAccountTransactions($accountAddress);
            $output->writeln(print_r($accountTxResult, true));
        }

        return Command::SUCCESS;
    }
}
