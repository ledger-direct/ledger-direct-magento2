<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Gateway\Config;

use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Magento\Payment\Gateway\ConfigInterface;

/**
 * The payment method's title, translated while it is still the module's default
 *
 * The title is a configuration value (etc/config.xml), which Magento never translates. A merchant
 * who has not set a title of their own would otherwise show the English default in every store
 * view. A title the merchant has set is shown exactly as it is.
 */
class TitleValueHandler implements ValueHandlerInterface
{
    /**
     * @var ConfigInterface
     */
    private ConfigInterface $configInterface;

    /**
     * @param ConfigInterface $configInterface
     */
    public function __construct(ConfigInterface $configInterface)
    {
        $this->configInterface = $configInterface;
    }

    /**
     * @inheritdoc
     */
    public function handle(array $subject, $storeId = null)
    {
        $title = $this->configInterface->getValue('title', $storeId);

        return match ($title) {
            'Pay with XRP | Ledger Direct' => (string) __('Pay with XRP | Ledger Direct'),
            'Pay with RLUSD | Ledger Direct' => (string) __('Pay with RLUSD | Ledger Direct'),
            'Pay with USDC | Ledger Direct' => (string) __('Pay with USDC | Ledger Direct'),
            'Pay with XAH | Ledger Direct' => (string) __('Pay with XAH | Ledger Direct'),
            default => $title,
        };
    }
}
