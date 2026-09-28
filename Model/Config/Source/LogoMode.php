<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which logo the payment page shows in its header
 */
class LogoMode implements OptionSourceInterface
{
    public const SHOP = 'shop';
    public const CUSTOM = 'custom';
    public const NONE = 'none';

    public const MODES = [self::SHOP, self::CUSTOM, self::NONE];

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SHOP, 'label' => __('The store logo')],
            ['value' => self::CUSTOM, 'label' => __('An uploaded picture')],
            ['value' => self::NONE, 'label' => __('No logo, the first letter of the store name')],
        ];
    }
}
