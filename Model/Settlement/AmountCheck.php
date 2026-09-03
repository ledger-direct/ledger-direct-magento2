<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\Settlement;

use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * Decides whether what arrived on the ledger pays for what was quoted.
 *
 * XRP tolerates a small shortfall: the quoted amount is a float rounded to five places and
 * wallets may deduct rounding on their side, so a payment within XRP_TOLERANCE of the request
 * counts as complete - the same 0.15 % the Shopware plugin applies. An issued currency must
 * match the quoted issuer and currency exactly and deliver at least the quoted value; a token
 * from another issuer is not the token that was asked for, however it is named.
 */
class AmountCheck
{
    public const XRP_TOLERANCE = '0.0015';

    /**
     * Whether the intent's delivered amount settles its requested amount
     *
     * @param PaymentIntent $intent a fulfilled intent (amount_paid set)
     * @return bool
     */
    public function isSettled(PaymentIntent $intent): bool
    {
        if ($intent->amountPaid === null) {
            return false;
        }

        if (is_array($intent->amountRequested)) {
            if (!is_array($intent->amountPaid)
                || $intent->amountPaid['currency'] !== $intent->amountRequested['currency']
                || $intent->amountPaid['issuer'] !== $intent->amountRequested['issuer']
            ) {
                return false;
            }

            return BigDecimal::of((string) $intent->amountPaid['value'])
                ->isGreaterThanOrEqualTo(BigDecimal::of((string) $intent->amountRequested['value']));
        }

        if (is_array($intent->amountPaid)) {
            return false;
        }

        $requested = BigDecimal::of((string) $intent->amountRequested);
        $acceptable = $requested->minus($requested->multipliedBy(self::XRP_TOLERANCE));

        return BigDecimal::of((string) $intent->amountPaid)->isGreaterThanOrEqualTo($acceptable);
    }

    /**
     * The delivered amount as a plain decimal string, whatever its shape
     *
     * @param PaymentIntent $intent
     * @return string|null
     */
    public function paidValue(PaymentIntent $intent): ?string
    {
        if ($intent->amountPaid === null) {
            return null;
        }

        return is_array($intent->amountPaid)
            ? (string) $intent->amountPaid['value']
            : $this->formatFloat($intent->amountPaid);
    }

    /**
     * The requested amount as a plain decimal string, whatever its shape
     *
     * @param PaymentIntent $intent
     * @return string
     */
    public function requestedValue(PaymentIntent $intent): string
    {
        return is_array($intent->amountRequested)
            ? (string) $intent->amountRequested['value']
            : $this->formatFloat($intent->amountRequested);
    }

    /**
     * Render a float without exponent notation or trailing zeros
     *
     * @param float $value
     * @return string
     */
    private function formatFloat(float $value): string
    {
        return (string) BigDecimal::of((string) $value)->stripTrailingZeros();
    }
}
