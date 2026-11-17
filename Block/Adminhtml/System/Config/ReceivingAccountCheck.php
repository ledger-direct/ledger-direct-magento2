<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Block\Adminhtml\System\Config;

use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Model\ChainServices;
use Hardcastle\LedgerDirect\Port\MagentoConfigProvider;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;

/**
 * What the saved receiving account of a chain lacks, in that chain's configuration group
 *
 * The core's assessment (INVARIANTS.md, "Receiving account"): a token without a trustline is not
 * offered in the checkout, an account that is not activated takes no small orders yet, deposit
 * authorization lets only pre-authorized senders pay. The settings stay as they are; this only
 * explains. Read afresh from the ledger on every visit, so the result of a save shows at once.
 * Stores nothing.
 */
class ReceivingAccountCheck extends Field
{
    /**
     * The field id in etc/adminhtml/system.xml names the chain
     */
    private const CHAIN_BY_FIELD = [
        'xrpl_account_check' => ChainCatalog::XRPL,
        'xahau_account_check' => ChainCatalog::XAHAU,
    ];

    /**
     * @var ChainServices
     */
    private ChainServices $chains;

    /**
     * @var MagentoConfigProvider
     */
    private MagentoConfigProvider $config;

    /**
     * @var Escaper
     */
    private Escaper $escaper;

    /**
     * @param Context $context
     * @param ChainServices $chains
     * @param MagentoConfigProvider $config
     * @param Escaper $escaper
     * @param array $data
     */
    public function __construct(
        Context $context,
        ChainServices $chains,
        MagentoConfigProvider $config,
        Escaper $escaper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->chains = $chains;
        $this->config = $config;
        $this->escaper = $escaper;
    }

    /**
     * One row across the whole form: the findings, or nothing for a chain without an account
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $chain = self::CHAIN_BY_FIELD[(string) ($element->getFieldConfig()['id'] ?? '')] ?? null;
        if ($chain === null) {
            return '';
        }

        $html = $this->messages($chain);
        if ($html === '') {
            return '';
        }

        return '<tr id="row_' . $this->escaper->escapeHtmlAttr($element->getHtmlId()) . '">'
            . '<td class="label">' . $this->escaper->escapeHtml(__('Receiving account')) . '</td>'
            . '<td class="value" colspan="3">' . $html . '</td></tr>';
    }

    /**
     * The findings for the chain's saved account and the assets that are switched on, as messages
     *
     * @param string $chain
     * @return string
     */
    private function messages(string $chain): string
    {
        $account = $this->config->getDestinationAccount($chain);
        if ($account === '') {
            return '';
        }

        $assets = [];
        foreach (ChainCatalog::chain($chain)->assets as $asset) {
            if ($this->config->isAssetEnabled($chain, $asset->code)) {
                $assets[] = $asset->code;
            }
        }
        if ($assets === []) {
            return '';
        }

        $network = $this->config->getNetwork($chain);
        $native = ChainCatalog::chain($chain)->nativeAsset;
        $assessment = $this->chains->accountCheck($chain)->assess($account, $network, $assets);

        if ($assessment->isClean()) {
            return $this->message('success', (string) __(
                'The receiving account %1 on the %2 can take every asset that is switched on.',
                $account,
                $network
            ));
        }

        $html = '';
        foreach ($assessment->findings as $finding) {
            $kind = $finding->blocksOffer || $finding->code === Finding::ACCOUNT_MISSING ? 'warning' : 'notice';
            $html .= $this->message($kind, $this->text($finding, $native));
        }

        return $html;
    }

    /**
     * A Magento admin message box
     *
     * @param string $kind success | warning | notice
     * @param string $text
     * @return string
     */
    private function message(string $kind, string $text): string
    {
        return '<div class="message message-' . $kind . ' ' . $kind . '"><div>'
            . $this->escaper->escapeHtml($text) . '</div></div>';
    }

    /**
     * What one finding means for the merchant
     *
     * @param Finding $finding
     * @param string $native
     * @return string
     */
    private function text(Finding $finding, string $native): string
    {
        $asset = (string) $finding->asset;
        $issuer = (string) ($finding->context['issuer'] ?? '');

        switch ($finding->code) {
            case Finding::ACCOUNT_MISSING:
                return (string) __(
                    'The account is not activated yet. A first payment of at least %1 %2 activates it; '
                    . 'until then smaller orders in %2 fail, and tokens are not offered.',
                    (string) ($finding->context['reserve'] ?? ''),
                    $native
                );
            case Finding::DEPOSIT_AUTH:
                return (string) __(
                    'Deposit authorization is set on the account: only senders you authorized can pay. '
                    . 'The payment methods stay in the checkout.'
                );
            case Finding::TRUSTLINE_MISSING:
                return (string) __(
                    '%1 is not offered in the checkout: the account has no trustline to the issuer %2.',
                    $asset,
                    $issuer
                );
            case Finding::TRUSTLINE_NOT_AUTHORIZED:
                return (string) __(
                    '%1 is not offered in the checkout: the issuer %2 has not authorized the trustline.',
                    $asset,
                    $issuer
                );
            case Finding::TRUSTLINE_FROZEN:
                return (string) __('%1 is not offered in the checkout: the trustline is frozen.', $asset);
            case Finding::TRUSTLINE_FULL:
                return (string) __('%1 is not offered in the checkout: the trustline has reached its limit.', $asset);
            case Finding::ISSUER_FROZEN:
                return (string) __(
                    '%1 is not offered in the checkout: the issuer %2 has frozen all its trustlines.',
                    $asset,
                    $issuer
                );
            case Finding::NOT_CHECKED:
                return (string) __(
                    'The ledger could not be asked just now. Until it can, tokens are not offered; %1 is.',
                    $native
                );
            default:
                return $finding->code;
        }
    }
}
