<?php
/**
 * The try-it shop's LedgerDirect setup. Both parts are safe to run again.
 *
 *   install.php config    every start: the receiving account and what goes with it
 *     - LD_TESTNET_ACCOUNT if set; otherwise the account from an earlier start; otherwise a fresh
 *       account from the testnet faucet (only its address is kept; the shop never signs anything)
 *     - XRP always; RLUSD and USDC only with an account of your own, because they need trust lines to
 *       the issuers on it, which a fresh faucet account does not have
 *     - free shipping, so an order costs exactly the article; outgoing mail off (no mail server)
 *
 *   install.php catalog   once: the category "LedgerDirect demo" with the demo article for 1 EUR,
 *     each created only if it does not exist yet
 */
declare(strict_types=1);

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;

require '/var/www/html/app/bootstrap.php';

const FAUCET = 'https://faucet.altnet.rippletest.net/accounts';
const ACCOUNT_FILE = '/var/www/html/var/.ledger-direct-try-account';
const ADDRESS = '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/';
const SKU = 'ledger-direct-demo';

function out(string $message): void
{
    fwrite(STDOUT, '[ledger-direct-try] ' . $message . "\n");
}

function faucetAccount(): string
{
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => '{}',
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(FAUCET, false, $context);
        $address = $body === false ? null : (json_decode($body, true)['account']['address'] ?? null);
        if (is_string($address) && preg_match(ADDRESS, $address)) {
            return $address;
        }
        out("testnet faucet did not answer (attempt {$attempt}), retrying");
        sleep(5 * $attempt);
    }
    throw new RuntimeException('The testnet faucet is unreachable. Set LD_TESTNET_ACCOUNT to a testnet address of your own.');
}

function configure($om): void
{
    $own = trim((string) getenv('LD_TESTNET_ACCOUNT'));
    if ($own !== '' && !preg_match(ADDRESS, $own)) {
        throw new RuntimeException("LD_TESTNET_ACCOUNT is not an XRPL address: {$own}");
    }
    $stored = is_file(ACCOUNT_FILE) ? trim((string) file_get_contents(ACCOUNT_FILE)) : '';
    $fromFaucet = is_file(ACCOUNT_FILE . '.faucet');

    if ($own !== '') {
        $account = $own;
        @unlink(ACCOUNT_FILE . '.faucet');
    } elseif ($stored !== '' && $fromFaucet) {
        $account = $stored;
    } else {
        $account = faucetAccount();
        touch(ACCOUNT_FILE . '.faucet');
    }
    file_put_contents(ACCOUNT_FILE, $account);
    $stablecoins = $own !== '' && getenv('LD_STABLECOINS') !== '0';

    $config = $om->get(WriterInterface::class);
    foreach ([
        'payment/ledger_direct/active' => 1,
        'payment/ledger_direct/use_testnet' => 1,
        'payment/ledger_direct/xrpl_testnet_account' => $account,
        'payment/ledger_direct/quote_expiry' => 300,
        'payment/xrp_payment/active' => 1,
        'payment/xrpl_rlusd_payment/active' => $stablecoins ? 1 : 0,
        'payment/xrpl_usdc_payment/active' => $stablecoins ? 1 : 0,
        'carriers/flatrate/active' => 0,
        'carriers/freeshipping/active' => 1,
        'carriers/freeshipping/free_shipping_subtotal' => 0,
        'system/smtp/disable' => 1,
        'general/store_information/name' => 'LedgerDirect demo shop',
        'design/head/default_title' => 'LedgerDirect demo shop',
    ] as $path => $value) {
        $config->save($path, $value);
    }
    $om->get(TypeListInterface::class)->cleanType('config');

    out($own !== ''
        ? "receiving account {$account} (yours); RLUSD and USDC " . ($stablecoins ? 'on — it needs the trust lines' : 'off')
        : "receiving account {$account} (from the testnet faucet); XRP only — RLUSD and USDC need an account with trust lines, see LD_TESTNET_ACCOUNT");
}

function catalog($om): void
{
    $category = $om->get(CategoryCollectionFactory::class)->create()
        ->addAttributeToFilter('url_key', 'ledger-direct-demo')
        ->setPageSize(1)
        ->getFirstItem();
    if (!$category->getId()) {
        $category = $om->get(CategoryInterfaceFactory::class)->create();
        $category->setName('LedgerDirect demo')->setParentId(2)->setIsActive(true)->setIncludeInMenu(true)
            ->setCustomAttribute('url_key', 'ledger-direct-demo');
        $category = $om->get(CategoryRepositoryInterface::class)->save($category);
    }

    $products = $om->get(ProductRepositoryInterface::class);
    try {
        $products->get(SKU);
    } catch (NoSuchEntityException $missing) {
        $product = $om->get(ProductInterfaceFactory::class)->create();
        $product->setSku(SKU)
            ->setName('LedgerDirect demo article')
            ->setAttributeSetId(4)
            ->setTypeId(Type::TYPE_SIMPLE)
            ->setPrice(1.00)
            ->setWeight(0.1)
            ->setStatus(Status::STATUS_ENABLED)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([1])
            ->setCustomAttribute('url_key', 'ledger-direct-demo-article')
            ->setCustomAttribute('description', 'A demo article for 1 EUR. Pay it with XRP on the XRP Ledger testnet.')
            ->setStockData(['qty' => 100000, 'is_in_stock' => 1, 'manage_stock' => 1, 'use_config_manage_stock' => 1]);
        $products->save($product);
    }
    $om->get(CategoryLinkManagementInterface::class)->assignProductToCategories(SKU, [(int) $category->getId()]);

    out('demo article "LedgerDirect demo article" (SKU ' . SKU . ', 1 EUR) in the category "LedgerDirect demo"');
}

$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(State::class)->setAreaCode('adminhtml');

match ($argv[1] ?? '') {
    'config' => configure($om),
    'catalog' => catalog($om),
    default => throw new InvalidArgumentException('usage: install.php config|catalog'),
};
