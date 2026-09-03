<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model\Settlement;

/**
 * What became of a matched ledger payment.
 */
class SettlementResult
{
    /** The order is paid: invoiced and moved to the settled status. */
    public const SETTLED = 'settled';

    /** Less arrived than was quoted; the order stays pending and the difference is still due. */
    public const UNDERPAID = 'underpaid';

    /** The order cannot take a payment any more (cancelled, closed, held) - the money is orphaned. */
    public const NOT_PAYABLE = 'not_payable';

    /**
     * @var string
     */
    private string $status;

    /**
     * @var string
     */
    private string $amountPaid;

    /**
     * @var string
     */
    private string $amountRequested;

    /**
     * @param string $status
     * @param string $amountPaid
     * @param string $amountRequested
     */
    public function __construct(string $status, string $amountPaid, string $amountRequested)
    {
        $this->status = $status;
        $this->amountPaid = $amountPaid;
        $this->amountRequested = $amountRequested;
    }

    /**
     * Whether the order is now paid
     *
     * @return bool
     */
    public function isSettled(): bool
    {
        return $this->status === self::SETTLED;
    }

    /**
     * One of the class constants
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Delivered amount, as a decimal string in the quoted asset
     *
     * @return string
     */
    public function getAmountPaid(): string
    {
        return $this->amountPaid;
    }

    /**
     * Quoted amount, as a decimal string in the quoted asset
     *
     * @return string
     */
    public function getAmountRequested(): string
    {
        return $this->amountRequested;
    }
}
