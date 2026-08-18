<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Model;

use Hardcastle\LedgerDirect\Api\Data\XrpPaymentInterface;
use Magento\Framework\Model\AbstractModel;

class XrpPayment extends AbstractModel implements XrpPaymentInterface
{
    /**
     * @var string
     */
    private string $type;

    /**
     * @var string|null
     */
    private ?string $tokenAmount = null;

    /**
     * @var string|null
     */
    private ?string $currency = null;

    /**
     * @var string|null
     */
    private ?string $issuer = null;

    /**
     * @var int
     */
    private int $orderId;

    /**
     * @var string
     */
    private string $orderNumber;

    /**
     * @var string
     */
    private string $currencyCode;

    /**
     * @var string
     */
    private string $currencySymbol;

    /**
     * @var float
     */
    private float $price;

    /**
     * @var string
     */
    private string $network;

    /**
     * @var string
     */
    private string $destinationAccount;

    /**
     * @var int
     */
    private int $destinationTag;

    /**
     * @var float
     */
    private float $xrpAmount;

    /**
     * @var float
     */
    private float $exchangeRate;

    /**
     * @var string|null
     */
    private ?string $txHash;

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritDoc
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): int
    {
        return $this->orderId;
    }

    /**
     * @inheritDoc
     */
    public function setOrderId(int $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    /**
     * @inheritDoc
     */
    public function setOrderNumber(string $orderNumber): self
    {
        $this->orderNumber = $orderNumber;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /**
     * @inheritDoc
     */
    public function setCurrencyCode(string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCurrencySymbol(): string
    {
        return $this->currencySymbol;
    }

    /**
     * @inheritDoc
     */
    public function setCurrencySymbol(string $currencySymbol): self
    {
        $this->currencySymbol = $currencySymbol;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPrice(): float
    {
        return $this->price;
    }

    /**
     * @inheritDoc
     */
    public function setPrice(float $price): self
    {
        $this->price = $price;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getNetwork(): string
    {
        return $this->network;
    }

    /**
     * @inheritDoc
     */
    public function setNetwork(string $network): self
    {
        $this->network = $network;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getDestinationAccount(): string
    {
        return $this->destinationAccount;
    }

    /**
     * @inheritDoc
     */
    public function setDestinationAccount(string $destinationAccount): self
    {
        $this->destinationAccount = $destinationAccount;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getDestinationTag(): int
    {
        return $this->destinationTag;
    }

    /**
     * @inheritDoc
     */
    public function setDestinationTag(int $destinationTag): self
    {
        $this->destinationTag = $destinationTag;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getXrpAmount(): float
    {
        return $this->xrpAmount;
    }

    /**
     * @inheritDoc
     */
    public function setXrpAmount(float $xrpAmount): self
    {
        $this->xrpAmount = $xrpAmount;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getExchangeRate(): float
    {
        return $this->exchangeRate;
    }

    /**
     * @inheritDoc
     */
    public function setExchangeRate(float $exchangeRate): self
    {
        $this->exchangeRate = $exchangeRate;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTxHash(): string|null
    {
        return $this->txHash;
    }

    /**
     * @inheritDoc
     */
    public function setTxHash(string|null $txHash): self
    {
        $this->txHash = $txHash;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTokenAmount(): ?string
    {
        return $this->tokenAmount;
    }

    /**
     * @inheritDoc
     */
    public function setTokenAmount(?string $tokenAmount): self
    {
        $this->tokenAmount = $tokenAmount;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    /**
     * @inheritDoc
     */
    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    /**
     * @inheritDoc
     */
    public function setIssuer(?string $issuer): self
    {
        $this->issuer = $issuer;

        return $this;
    }
}
