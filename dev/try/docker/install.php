<?php
/**
 * The try-it shop's LedgerDirect setup. Both parts are safe to run again.
 *
 *   install.php config    every start: the receiving account and what goes with it
 *     - LD_TESTNET_ACCOUNT if set (yours: it needs the RLUSD and USDC trust lines itself)
 *     - otherwise the shop's own testnet wallet: created from the faucet on the first start, with
 *       trust lines to the RLUSD and USDC issuers of the core's StablecoinRegistry, and created anew
 *       when the testnet was reset (see wallet.php)
 *     - XRP always; RLUSD and USDC when the account has the trust lines
 *     - free shipping, so an order costs exactly the article; outgoing mail off (no mail server)
 *
 *   install.php catalog   when the image brings a new catalogue: the category "LedgerDirect demo" with
 *     the demo article for 1 EUR (the smoke test orders it), the category "Miniatures" with the
 *     articles of catalog/catalog.json, and the home page that explains the demo and shows the
 *     miniatures. Articles are found again by SKU: an existing one gets its texts and price updated,
 *     never a second copy of its pictures.
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
use Magento\Cms\Model\PageFactory;
use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;

require '/var/www/html/app/bootstrap.php';

const ACCOUNT_FILE = '/var/www/html/var/.ledger-direct-try-account';
const WALLET_FILE = '/var/www/html/var/.ledger-direct-try-wallet.json';
const WALLET = '/usr/local/share/ledger-direct/wallet.php';
const ADDRESS = '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/';
const SKU = 'ledger-direct-demo';
const CATALOG = '/usr/local/share/ledger-direct/catalog';

function out(string $message): void
{
    fwrite(STDOUT, '[ledger-direct-try] ' . $message . "\n");
}

/** Runs the wallet tool; returns its exit code, passes its own log lines through. */
function wallet(string ...$args): int
{
    $command = 'php ' . escapeshellarg(WALLET) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    passthru($command, $code);

    return $code;
}

/**
 * The shop's own testnet account with its trust lines; [address, whether RLUSD and USDC can be paid].
 * The trust-line limits come from the core's StablecoinRegistry, the one source of issuer addresses.
 */
function ownWallet(): array
{
    if (is_file(WALLET_FILE) && wallet('exists', WALLET_FILE) === 3) {
        out('the testnet was reset since the last start; creating a new receiving account');
        unlink(WALLET_FILE);
    }
    if (!is_file(WALLET_FILE)) {
        ob_start();
        $code = wallet('create', WALLET_FILE);
        ob_end_clean();
        if ($code !== 0) {
            throw new RuntimeException('could not create a testnet account; set LD_TESTNET_ACCOUNT to an address of your own');
        }
    }
    $address = json_decode((string) file_get_contents(WALLET_FILE), true)['address'];

    $registry = new StablecoinRegistry();
    $lines = [$registry->getRLUSDAmount('testnet', '1000000'), $registry->getUSDCAmount('testnet', '1000000')];
    $trusted = wallet('trust', WALLET_FILE, json_encode($lines)) === 0;
    if (!$trusted) {
        out('the trust lines could not be set; RLUSD and USDC stay off for now, the next start tries again');
    }

    return [$address, $trusted];
}

function configure($om): void
{
    $own = trim((string) getenv('LD_TESTNET_ACCOUNT'));
    if ($own !== '' && !preg_match(ADDRESS, $own)) {
        throw new RuntimeException("LD_TESTNET_ACCOUNT is not an XRPL address: {$own}");
    }
    if ($own !== '') {
        [$account, $stablecoins] = [$own, getenv('LD_STABLECOINS') !== '0'];
    } else {
        [$account, $stablecoins] = ownWallet();
    }
    file_put_contents(ACCOUNT_FILE, $account);

    $config = $om->get(WriterInterface::class);
    foreach ([
        'payment/ledger_direct/use_testnet' => 1,
        'payment/ledger_direct/xrpl_testnet_account' => $account,
        'payment/ledger_direct/quote_expiry' => 300,
        'payment/xrp_payment/active' => 1,
        'payment/xrpl_rlusd_payment/active' => $stablecoins ? 1 : 0,
        'payment/xrpl_usdc_payment/active' => $stablecoins ? 1 : 0,
        // Xahau stays off, on its testnet: whoever switches the XAH method on in the admin pays on the
        // Xahau testnet, with an account they enter there.
        'payment/ledger_direct_xahau/use_testnet' => 1,
        'payment/xahau_xah_payment/active' => 0,
        'carriers/flatrate/active' => 0,
        'carriers/freeshipping/active' => 1,
        'carriers/freeshipping/free_shipping_subtotal' => 0,
        'system/smtp/disable' => 1,
        'general/store_information/name' => 'LedgerDirect demo shop',
        'design/head/default_title' => 'LedgerDirect demo shop',
        // Magento's own demo banner stays off: the payment page leaves Magento's styles out, where it
        // would show as bare text; the home page and the payment page say "testnet" themselves.
        'design/head/demonotice' => 0,
        'design/header/welcome' => 'LedgerDirect demo shop on the XRP Ledger testnet',
        'payment/checkmo/active' => 0,
    ] as $path => $value) {
        $config->save($path, $value);
    }
    $om->get(TypeListInterface::class)->cleanType('config');

    out("receiving account {$account} (" . ($own !== '' ? 'yours' : "the shop's own testnet wallet") . '); XRP'
        . ($stablecoins ? ', RLUSD and USDC' : ' only'));
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
        $product->addImageToMediaGallery(articlePicture(), ['image', 'small_image', 'thumbnail'], true, false);
        $products->save($product);
    }
    $om->get(CategoryLinkManagementInterface::class)->assignProductToCategories(SKU, [(int) $category->getId()]);

    $miniatures = miniatures($om);
    homePage($om, $miniatures);
    out('home page, category "LedgerDirect demo" with the demo article (SKU ' . SKU . ', 1 EUR)');
}

/** A category found by its URL key, or created under the root category. */
function categoryByUrlKey($om, string $urlKey, string $name): \Magento\Catalog\Api\Data\CategoryInterface
{
    $found = $om->get(CategoryCollectionFactory::class)->create()
        ->addAttributeToFilter('url_key', $urlKey)
        ->setPageSize(1)
        ->getFirstItem();
    if ($found->getId()) {
        return $found;
    }
    $category = $om->get(CategoryInterfaceFactory::class)->create();
    $category->setName($name)->setParentId(2)->setIsActive(true)->setIncludeInMenu(true)
        ->setCustomAttribute('url_key', $urlKey);

    return $om->get(CategoryRepositoryInterface::class)->save($category);
}

/**
 * The miniatures from catalog/catalog.json, in the category "Miniatures". Found again by SKU; an
 * existing article gets its name, texts and price from the catalogue, its pictures stay as they are.
 *
 * @return int the category's id
 */
function miniatures($om): int
{
    $catalog = json_decode((string) file_get_contents(CATALOG . '/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $category = categoryByUrlKey($om, $catalog['category']['url_key'], $catalog['category']['en-GB']['name']);
    $products = $om->get(ProductRepositoryInterface::class);
    $links = $om->get(CategoryLinkManagementInterface::class);
    $closing = $catalog['closing']['en-GB'];

    foreach ($catalog['products'] as $item) {
        $text = $item['en-GB'];
        $paragraphs = array_map(
            static fn (string $line): string => '<p>' . htmlspecialchars($line) . '</p>',
            array_values(array_filter(array_map('trim', explode("\n", $text['description']))))
        );
        $facts = array_map(
            static fn (string $key, string $value): string => '<li>' . ucfirst($key) . ': ' . htmlspecialchars($value) . '</li>',
            array_keys($item['facts']),
            array_values($item['facts'])
        );
        $description = implode("\n", $paragraphs)
            . ($facts ? "\n<ul>" . implode('', $facts) . '</ul>' : '')
            . "\n<p><em>" . htmlspecialchars($closing) . '</em></p>';

        try {
            $product = $products->get($item['number'], true, 0, true);
            $isNew = false;
        } catch (NoSuchEntityException $missing) {
            $product = $om->get(ProductInterfaceFactory::class)->create();
            $product->setSku($item['number'])
                ->setAttributeSetId(4)
                ->setTypeId(Type::TYPE_SIMPLE)
                ->setWeight(0.1)
                ->setStatus(Status::STATUS_ENABLED)
                ->setVisibility(Visibility::VISIBILITY_BOTH)
                ->setWebsiteIds([1])
                ->setCustomAttribute('url_key', $item['slug'])
                ->setStockData(['qty' => 100000, 'is_in_stock' => 1, 'manage_stock' => 1, 'use_config_manage_stock' => 1]);
            $isNew = true;
        }
        $product->setName($text['name'])
            ->setPrice((float) $item['price'])
            ->setCustomAttribute('short_description', '<p>' . htmlspecialchars($text['tagline']) . '</p>')
            ->setCustomAttribute('description', $description);

        if ($isNew) {
            foreach ($item['images'] as $number => $image) {
                $product->addImageToMediaGallery(
                    mediaCopy(CATALOG . '/images/' . $image['file']),
                    $number === 0 ? ['image', 'small_image', 'thumbnail'] : [],
                    true,
                    false
                );
            }
            // The alt texts from the catalogue become the pictures' labels, in the same order.
            $gallery = $product->getData('media_gallery');
            foreach (array_keys($gallery['images'] ?? []) as $index => $key) {
                $gallery['images'][$key]['label'] = $item['images'][$index]['alt'] ?? $text['name'];
            }
            $product->setData('media_gallery', $gallery);
        }
        $products->save($product);
        $links->assignProductToCategories($item['number'], [(int) $category->getId()]);
        out(sprintf('%s "%s", %.2f EUR%s', $item['number'], $text['name'], $item['price'], $isNew ? '' : ' (updated)'));
    }

    return (int) $category->getId();
}

/** Magento takes product images only from inside its media directory. */
function mediaCopy(string $source): string
{
    $directory = '/var/www/html/pub/media/import';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $target = $directory . '/' . basename($source);
    copy($source, $target);

    return $target;
}

/** A picture for the demo article, drawn here: no image file with a licence of its own in the image. */
function articlePicture(): string
{
    $size = 800;
    $image = imagecreatetruecolor($size, $size);
    $accent = imagecolorallocate($image, 0x1f, 0x5e, 0xff);
    $white = imagecolorallocate($image, 0xff, 0xff, 0xff);
    imagefilledrectangle($image, 0, 0, $size, $size, $accent);
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $lines = [['LedgerDirect', 64, 330], ['demo article', 44, 420], ['1 EUR · XRP Ledger testnet', 26, 500]];
    foreach ($lines as [$text, $points, $y]) {
        if (is_file($font) && function_exists('imagettftext')) {
            $box = imagettfbbox($points, 0, $font, $text);
            imagettftext($image, $points, 0, (int) (($size - ($box[2] - $box[0])) / 2), $y, $white, $font, $text);
        } else {
            imagestring($image, 5, (int) (($size - 9 * strlen($text)) / 2), $y - 15, $text, $white);
        }
    }
    // Magento takes product images only from inside its media directory.
    $directory = '/var/www/html/pub/media/import';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $path = $directory . '/ledger-direct-demo-article.png';
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

/** Magento's home page explains the demo and shows the miniatures with their cart buttons. */
function homePage($om, int $miniatures): void
{
    $widget = '{{widget type="Magento\\CatalogWidget\\Block\\Product\\ProductsList" show_pager="0" products_count="12"'
        . ' template="Magento_CatalogWidget::product/widget/content/grid.phtml"'
        . ' conditions_encoded="^[`1`:^[`type`:`Magento||CatalogWidget||Model||Rule||Condition||Combine`,'
        . '`aggregator`:`all`,`value`:`1`,`new_child`:``^],`1--1`:^[`type`:`Magento||CatalogWidget||Model||Rule||Condition||Product`,'
        . '`attribute`:`category_ids`,`operator`:`==`,`value`:`' . $miniatures . '`^]^]"}}';
    $content = <<<HTML
<div class="ledger-direct-demo">
<p><strong>A demo shop on the XRP Ledger testnet.</strong> It runs <a href="https://github.com/ledger-direct/ledger-direct-magento2">LedgerDirect for Magento 2</a>:
customers pay with XRP, RLUSD or USDC straight into the shop's own wallet — no payment provider in between.
Nothing here costs real money, and no order is shipped.</p>
<ol>
<li>Put a miniature in the cart and check out as a guest — any name and address will do.</li>
<li>Choose <em>Pay with XRP</em> (or RLUSD or USDC) and place the order.</li>
<li>The payment page shows the amount, the shop's account and a destination tag. Pay it from a testnet wallet,
with the destination tag, or connect the wallet with the buttons on the page. The page notices the payment within seconds.</li>
</ol>
<p>A testnet wallet: <a href="https://xaman.app/">Xaman</a> in testnet mode or <a href="https://crossmark.io/">Crossmark</a>,
with XRP from the <a href="https://xrpl.org/resources/dev-tools/xrp-faucets">XRP testnet faucet</a>. RLUSD and USDC come from the
<a href="https://tryrlusd.com/">RLUSD faucet</a> and the <a href="https://faucet.circle.com/">Circle faucet</a>.</p>
</div>
{$widget}
HTML;
    $page = $om->get(PageFactory::class)->create()->load('home', 'identifier');
    $page->setTitle('LedgerDirect demo shop')->setContentHeading('Pay with LedgerDirect')->setContent($content)->save();
}

$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(State::class)->setAreaCode('adminhtml');

match ($argv[1] ?? '') {
    'config' => configure($om),
    'catalog' => catalog($om),
    default => throw new InvalidArgumentException('usage: install.php config|catalog'),
};
