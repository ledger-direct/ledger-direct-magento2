<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Model\Config\Backend;

use Hardcastle\LedgerDirect\Core\Presentation\AccentColor as AccentColorRule;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * The payment page's accent colour: a hex colour that carries white text.
 *
 * The rule is the core's (WCAG 4.5:1 to white); saving a colour that fails it is
 * refused with a message rather than silently replaced, so the merchant knows why
 * the page does not look the way they configured it. The page sanitises on render
 * all the same.
 */
class AccentColor extends Value
{
    /**
     * Normalise the colour and refuse one that cannot carry white text
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $stored = trim((string) $this->getValue());

        if ($stored === '') {
            $this->setValue(AccentColorRule::DEFAULT);

            return parent::beforeSave();
        }

        $color = AccentColorRule::normalize($stored);

        if ($color === null) {
            throw new LocalizedException(__('The accent colour must be a hex colour such as #1f5eff.'));
        }

        if (AccentColorRule::contrastToWhite($color) < AccentColorRule::MIN_CONTRAST_TO_WHITE) {
            throw new LocalizedException(
                __('The accent colour is too light to carry white text (contrast below 4.5:1). Choose a darker colour.')
            );
        }

        $this->setValue($color);

        return parent::beforeSave();
    }
}
