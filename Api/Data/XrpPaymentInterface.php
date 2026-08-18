<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api\Data;

interface XrpPaymentInterface
{
    /**
     * Get the payment method type: xrp_payment, xrpl_rlusd_payment, or xrpl_usdc_payment
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Set the payment method type
     *
     * @param string $type
     * @return self
     */
    public function setType(string $type): self;

    /**
     * Get the order entity ID
     *
     * @return int
     */
    public function getOrderId(): int;

    /**
     * Set the order entity ID
     *
     * @param int $orderId
     * @return self
     */
    public function setOrderId(int $orderId): self;

    /**
     * Get the order increment ID
     *
     * @return string
     */
    public function getOrderNumber(): string;

    /**
     * Set the order increment ID
     *
     * @param string $orderNumber
     * @return self
     */
    public function setOrderNumber(string $orderNumber): self;

    /**
     * Get the order currency code
     *
     * @return string
     */
    public function getCurrencyCode(): string;

    /**
     * Set the order currency code
     *
     * @param string $currencyCode
     * @return self
     */
    public function setCurrencyCode(string $currencyCode): self;

    /**
     * Get the order currency symbol
     *
     * @return string
     */
    public function getCurrencySymbol(): string;

    /**
     * Set the order currency symbol
     *
     * @param string $currencySymbol
     * @return self
     */
    public function setCurrencySymbol(string $currencySymbol): self;

    /**
     * Get the order total due, in the order currency
     *
     * @return float
     */
    public function getPrice(): float;

    /**
     * Set the order total due, in the order currency
     *
     * @param float $price
     * @return self
     */
    public function setPrice(float $price): self;

    /**
     * Get the XRPL network the payment is expected on (mainnet or testnet)
     *
     * @return string
     */
    public function getNetwork(): string;

    /**
     * Set the XRPL network the payment is expected on (mainnet or testnet)
     *
     * @param string $network
     * @return self
     */
    public function setNetwork(string $network): self;

    /**
     * Get the XRPL account the payment must be sent to
     *
     * @return string
     */
    public function getDestinationAccount(): string;

    /**
     * Set the XRPL account the payment must be sent to
     *
     * @param string $destinationAccount
     * @return self
     */
    public function setDestinationAccount(string $destinationAccount): self;

    /**
     * Get the XRPL destination tag identifying this order's payment
     *
     * @return int
     */
    public function getDestinationTag(): int;

    /**
     * Set the XRPL destination tag identifying this order's payment
     *
     * @param int $destinationTag
     * @return self
     */
    public function setDestinationTag(int $destinationTag): self;

    /**
     * Get the amount of XRP requested
     *
     * @return float
     */
    public function getXrpAmount(): float;

    /**
     * Set the amount of XRP requested
     *
     * @param float $xrpAmount
     * @return self
     */
    public function setXrpAmount(float $xrpAmount): self;

    /**
     * Get the XRP/order-currency exchange rate used to calculate the requested amount
     *
     * @return float
     */
    public function getExchangeRate(): float;

    /**
     * Set the XRP/order-currency exchange rate used to calculate the requested amount
     *
     * @param float $exchangeRate
     * @return self
     */
    public function setExchangeRate(float $exchangeRate): self;

    /**
     * Get the settling transaction hash, if the payment has been matched on-chain
     *
     * @return string|null
     */
    public function getTxHash(): string|null;

    /**
     * Set the settling transaction hash
     *
     * @param string|null $txHash
     * @return self
     */
    public function setTxHash(?string $txHash): self;

    /**
     * Get the amount of the stablecoin requested (RLUSD/USDC payments only)
     *
     * @return string|null
     */
    public function getTokenAmount(): ?string;

    /**
     * Set the amount of the stablecoin requested
     *
     * @param string|null $tokenAmount
     * @return self
     */
    public function setTokenAmount(?string $tokenAmount): self;

    /**
     * Get the XRPL on-ledger currency code of the requested stablecoin (RLUSD/USDC payments only)
     *
     * @return string|null
     */
    public function getCurrency(): ?string;

    /**
     * Set the XRPL on-ledger currency code of the requested stablecoin
     *
     * @param string|null $currency
     * @return self
     */
    public function setCurrency(?string $currency): self;

    /**
     * Get the XRPL issuer account of the requested stablecoin (RLUSD/USDC payments only)
     *
     * @return string|null
     */
    public function getIssuer(): ?string;

    /**
     * Set the XRPL issuer account of the requested stablecoin
     *
     * @param string|null $issuer
     * @return self
     */
    public function setIssuer(?string $issuer): self;
}
