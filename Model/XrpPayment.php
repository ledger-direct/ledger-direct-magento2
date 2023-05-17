<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Magento\Framework\Model\AbstractModel;

class XrpPayment extends AbstractModel implements XrpPaymentInterface
{
    private int $orderId;

    private string $orderNumber;

    private string $currencyCode;

    private string $currencySymbol;

    private float $price;

    private string $network;

    private string $destinationAccount;

    private int $destinationTag;

    private float $xrpAmount;

    private float $exchangeRate;

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): self
    {
        $this->orderNumber = $orderNumber;

        return $this;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;

        return $this;
    }

    public function getCurrencySymbol(): string
    {
        return $this->currencySymbol;
    }

    public function setCurrencySymbol(string $currencySymbol): self
    {
        $this->currencySymbol = $currencySymbol;

        return $this;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function setNetwork(string $network): self
    {
        $this->network = $network;

        return $this;
    }

    public function getDestinationAccount(): string
    {
        return $this->destinationAccount;
    }

    public function setDestinationAccount(string $destinationAccount): self
    {
        $this->destinationAccount = $destinationAccount;

        return $this;
    }

    public function getDestinationTag(): int
    {
        return $this->destinationTag;
    }

    public function setDestinationTag(int $destinationTag): self
    {
        $this->destinationTag = $destinationTag;

        return $this;
    }

    public function getXrpAmount(): float
    {
        return $this->xrpAmount;
    }

    public function setXrpAmount(float $xrpAmount): self
    {
        $this->xrpAmount = $xrpAmount;

        return $this;
    }

    public function getExchangeRate(): float
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(float $exchangeRate): self
    {
        $this->exchangeRate = $exchangeRate;

        return $this;
    }
}
