<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Commands;

use Hardcastle\LedgerDirect\Service\XrplTxService;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class XrplPriceLookupCommand extends Command
{
    protected static $defaultName = 'ledger-direct:xrp-price:lookup';

    protected CryptoPriceProviderInterface $priceFinder;

    public function __construct(CryptoPriceProviderInterface $priceFinder) {
        $this->priceFinder = $priceFinder;

        parent::__construct(static::$defaultName);
    }

    /**
     * @inheritdoc
     */
    public function configure(): void
    {
        $this->setName(static::$defaultName);
        $this->setDescription('XRP price lookup, when no options are provided, default price providers will be looked up');
        $this->addOption('iso', null, InputOption::VALUE_REQUIRED, 'define providers to be queried for price');
        $this->addOption('provider', null, InputOption::VALUE_OPTIONAL, 'define providers to be queried for price');

    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $iso = $input->getOption('iso');
        $currentPrice = $this->priceFinder->getCurrentExchangeRate($iso);
        $output->writeln('Current XRP price: ' . $currentPrice);

        return Command::SUCCESS;
    }
}
