<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Observer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Item;

class PaymentMethodIsActive implements ObserverInterface
{
    protected ProductRepositoryInterface $productRepository;

    public function __construct(
        ProductRepositoryInterface $productRepository
    ) {
        $this->productRepository = $productRepository;
    }

    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $paymentMethod = $observer->getEvent()->getMethodInstance()->getCode();
        $quote = $observer->getEvent()->getQuote();
        $result = $observer->getEvent()->getResult();

        if ($paymentMethod == 'xrpl_token_payment') {
            $result->setData('is_available', true);
            foreach ($quote->getAllVisibleItems() as $item) {
                if (!$this->productHasTokenPrice($item)) {
                    $result->setData('is_available', false);
                }
            }
        }
    }

    /**
     * @param Item $item
     * @return bool
     */
    private function productHasTokenPrice(Item $item): bool
    {
        $productId = $item->getProduct()->getId();
        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException $e) {
            return false;
        }
        $lptPrice = $product->getData('ledger_direct_lpt_price');

        return !empty($lptPrice);
    }
}
