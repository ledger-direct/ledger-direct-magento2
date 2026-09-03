<?php declare(strict_types=1);

/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Commands;

use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException;
use Hardcastle\LedgerDirect\Port\MagentoConfigProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class XrplPriceLookupCommand extends Command
{
    /**
     * @var string
     */
    protected static $defaultName = 'ledger-direct:xrp-price:lookup';

    /**
     * @var PriceService
     */
    private PriceService $priceService;

    /**
     * @var MagentoConfigProvider
     */
    private MagentoConfigProvider $configProvider;

    /**
     * @param PriceService $priceService
     * @param MagentoConfigProvider $configProvider
     */
    public function __construct(PriceService $priceService, MagentoConfigProvider $configProvider)
    {
        $this->priceService = $priceService;
        $this->configProvider = $configProvider;

        parent::__construct(static::$defaultName);
    }

    /**
     * @inheritdoc
     */
    public function configure(): void
    {
        $this->setName(static::$defaultName);
        $this->setDescription('Price lookup against the core price oracles');
        $this->addOption('iso', null, InputOption::VALUE_REQUIRED, 'Quote currency ISO code, e.g. EUR');
        $this->addOption('asset', null, InputOption::VALUE_OPTIONAL, 'Base asset: XRP (default), RLUSD or USDC', 'XRP');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quoteCurrency = (string) $input->getOption('iso');
        $baseAsset = strtoupper((string) $input->getOption('asset'));
        $network = $this->configProvider->getNetwork(MagentoConfigProvider::CHAIN);

        try {
            // A total of 1.0 makes the returned quote's exchange rate the price of one unit.
            $quote = $this->priceService->getCryptoPriceForOrder(1.0, $quoteCurrency, $baseAsset, $network);
        } catch (PriceUnavailableException $exception) {
            $output->writeln('Error: ' . $baseAsset . ' price in "' . $quoteCurrency . '" is not available');

            return Command::FAILURE;
        }

        $output->writeln('Current ' . $baseAsset . ' price: ' . $quote->exchangeRate . ' ' . $quoteCurrency);

        return Command::SUCCESS;
    }
}
