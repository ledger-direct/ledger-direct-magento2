<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api\Data;

interface XrpPaymentInterface
{
    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @param int $orderId
     * @return self
     */
    public function setOrderId(int $orderId): self;

    /**
     * @return string
     */
    public function getOrderNumber(): string;

    /**
     * @param string $orderNumber
     * @return self
     */
    public function setOrderNumber(string $orderNumber): self;

    /**
     * @return string
     */
    public function getCurrencyCode(): string;

    /**
     * @param string $currencyCode
     * @return self
     */
    public function setCurrencyCode(string $currencyCode): self;

    /**
     * @return string
     */
    public function getCurrencySymbol(): string;

    /**
     * @param string $currencySymbol
     * @return self
     */
    public function setCurrencySymbol(string $currencySymbol): self;

    /**
     * @return float
     */
    public function getPrice(): float;

    /**
     * @param float $price
     * @return self
     */
    public function setPrice(float $price): self;

    /**
     * @return string
     */
    public function getNetwork(): string;

    /**
     * @param string $network
     * @return self
     */
    public function setNetwork(string $network): self;

    /**
     * @return string
     */
    public function getDestinationAccount(): string;

    /**
     * @param string $destinationAccount
     * @return self
     */
    public function setDestinationAccount(string $destinationAccount): self;

    /**
     * @return int
     */
    public function getDestinationTag(): int;

    /**
     * @param int $destinationTag
     * @return self
     */
    public function setDestinationTag(int $destinationTag): self;

    /**
     * @return float
     */
    public function getXrpAmount(): float;

    /**
     * @param float $xrpAmount
     * @return self
     */
    public function setXrpAmount(float $xrpAmount): self;

    /**
     * @return float
     */
    public function getExchangeRate(): float;

    /**
     * @param float $exchangeRate
     * @return self
     */
    public function setExchangeRate(float $exchangeRate): self;
}
