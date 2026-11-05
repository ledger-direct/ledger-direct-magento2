<?php declare(strict_types=1);
/**
 * Copyright (c) Alexander Busse | Hardcastle Technologies.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hardcastle\LedgerDirect\Model\Config\Source;

use Hardcastle\LedgerDirect\Model\Email\PendingPaymentSender;
use Magento\Config\Model\Config\Source\Email\Template;

/**
 * The email templates to choose from for the payment instructions email, with the module's own
 * template as the default
 *
 * Magento's source model names the default template after the field's path in the admin
 * structure. In the payment section that path is not payment/…: Magento_Paypal serves the section
 * as payment_us, payment_de, …, or payment_other, depending on the merchant country. The default
 * would then be a template that does not exist, and the whole Payment Methods page failed to
 * render. The template id here is the one etc/email_templates.xml defines, whatever the path.
 */
class PendingEmailTemplate extends Template
{
    /**
     * @inheritDoc
     */
    public function toOptionArray()
    {
        $this->setPath(PendingPaymentSender::CONFIG_TEMPLATE);

        return parent::toOptionArray();
    }
}
