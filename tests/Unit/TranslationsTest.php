<?php
declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use Hardcastle\LedgerDirect\Model\PaymentMethods;
use Hardcastle\LedgerDirect\Setup\Patch\Data\AddPaymentIncompleteStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;

/**
 * Every text the module can show exists in every language file, translated.
 *
 * The phrases are collected from the code the way Magento's i18n:collect-phrases does: `__()` in PHP
 * and templates, `translate="…"` in the XML configuration, `{{trans}}` in email templates, `$t()` in
 * scripts, `i18n:`, `$t()` and `<translate>` in Knockout templates. Magento shows a missing phrase
 * as it is, so the customer would read English, or a `LedgerDirect.*` key, instead of a sentence.
 *
 * Then each language file: two columns, nothing empty, the same placeholders and HTML tags as the
 * English, and no English left over unless the text is only names (XRPL, Testnet, …).
 */
class TranslationsTest extends TestCase
{
    private const LOCALES = ['en_US', 'de_DE', 'fr_FR', 'nl_NL', 'es_ES', 'pt_PT'];

    /**
     * Keys the templates complete at runtime, with what follows the literal part: the chain, and
     * for the network name also the network.
     */
    private const COMPOSED = [
        'LedgerDirect.paymentPage.network.' => ['.mainnet', '.testnet'],
        'LedgerDirect.paymentPage.poweredBy.' => [''],
    ];

    /**
     * Names that stay as they are in every language (Documentation/Uebersetzungen-Glossar.md). A
     * translation equal to the English is accepted when nothing else is left of it.
     */
    private const NAMES = [
        'XRP Ledger', 'Ledger Direct', 'LedgerDirect', 'WalletConnect', 'Xaman', 'Xahau', 'XRPL', 'RLUSD',
        'USDC', 'XRP', 'XAH', 'Testnet', 'Mainnet', 'CTID',
    ];

    /**
     * Words that are the same in a language and correct as they are: the glossary keeps
     * "destination tag" in every language and "asset" in German and Dutch.
     */
    private const SAME_AS_ENGLISH = [
        'Destination tag' => ['fr_FR', 'nl_NL', 'es_ES', 'pt_PT'],
        'Asset' => ['de_DE', 'nl_NL'],
        'Transaction' => ['fr_FR'],
    ];

    /** @var string[]|null */
    private static ?array $phrases = null;

    /** @var array<string, string> where => literal part */
    private static array $nonLiteral = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function translations(): iterable
    {
        foreach (self::LOCALES as $locale) {
            if ($locale !== 'en_US') {
                yield $locale => [$locale];
            }
        }
    }

    public function testThereIsALanguageFileForEveryLocaleAndNoOther(): void
    {
        $files = array_map(
            static fn(string $file): string => basename($file, '.csv'),
            glob(self::moduleDir() . '/i18n/*.csv') ?: []
        );
        sort($files);
        $expected = self::LOCALES;
        sort($expected);

        $this->assertSame($expected, $files);
    }

    public function testTheScanFindsWhatItShould(): void
    {
        $phrases = self::phrases();

        // One of each kind of source, so a scanner that stops matching does not pass silently.
        $this->assertContains('LedgerDirect.paymentPage.partialArrived', $phrases, 'phtml __()');
        $this->assertContains('LedgerDirect.paymentPage.network.XAHAU.testnet', $phrases, 'composed key');
        $this->assertContains(
            'The account is not activated yet. A first payment of at least %1 %2 activates it; '
            . 'until then smaller orders in %2 fail, and tokens are not offered.',
            $phrases,
            'PHP __() over concatenated literals'
        );
        $this->assertContains('Quote validity (seconds)', $phrases, 'system.xml label');
        $this->assertContains(
            'How long the exchange rate quoted on the payment page stays valid before it is refreshed.',
            $phrases,
            'system.xml comment'
        );
        $this->assertContains('LedgerDirect payment pending', $phrases, 'email template label');
        $this->assertContains(
            'Your order <span class="no-link">#%increment_id</span> is waiting for its payment in %method.',
            $phrases,
            'email {{trans}}'
        );
        $this->assertContains('Place Order', $phrases, 'Knockout i18n');
        $this->assertContains('Pay with XRP | Ledger Direct', $phrases, 'default title');
        $this->assertContains('XRPL payment incomplete', $phrases, 'order status');
    }

    public function testEveryTranslationTakesALiteral(): void
    {
        self::phrases();
        $this->assertSame(
            [],
            self::$nonLiteral,
            'These translations take a value that is not known before runtime, so no language file can hold it'
        );
    }

    /**
     * Magento never translates a configuration value; the title handler translates the defaults.
     * Each default title therefore has to be one of the phrases the handler knows.
     */
    public function testEveryDefaultTitleIsAPhrase(): void
    {
        $xml = new SimpleXMLElement((string) file_get_contents(self::moduleDir() . '/etc/config.xml'));
        $titles = array_map('strval', $xml->xpath('/config/default/payment/*/title') ?: []);

        $this->assertNotEmpty($titles);
        foreach ($titles as $title) {
            $this->assertContains(
                $title,
                self::phrases(),
                'Gateway/Config/TitleValueHandler does not translate ' . $title
            );
        }
    }

    /**
     * @dataProvider locales
     */
    #[DataProvider('locales')]
    public function testTheFileIsTwoColumnsWithoutGaps(string $locale): void
    {
        $handle = fopen(self::file($locale), 'rb');
        $line = 0;
        $seen = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;
            $where = sprintf('%s.csv line %d', $locale, $line);
            $this->assertCount(2, $row, $where);
            $this->assertNotSame('', trim((string) $row[0]), $where . ': empty phrase');
            $this->assertNotSame('', trim((string) $row[1]), $where . ': empty translation');
            $this->assertTrue(mb_check_encoding((string) $row[1], 'UTF-8'), $where . ': not UTF-8');
            $this->assertArrayNotHasKey($row[0], $seen, $where . ': phrase twice');
            $seen[$row[0]] = true;
        }
        fclose($handle);

        $this->assertGreaterThan(0, $line);
    }

    /**
     * @dataProvider locales
     */
    #[DataProvider('locales')]
    public function testEveryPhraseIsInTheFileAndNothingElse(string $locale): void
    {
        $inFile = array_keys(self::read($locale));
        $phrases = self::phrases();

        $this->assertSame([], array_values(array_diff($phrases, $inFile)), $locale . '.csv lacks these phrases');
        $this->assertSame(
            [],
            array_values(array_diff($inFile, $phrases)),
            $locale . '.csv has phrases the module does not use'
        );
    }

    /**
     * @dataProvider locales
     */
    #[DataProvider('locales')]
    public function testPlaceholdersAndTagsMatchTheEnglish(string $locale): void
    {
        $english = self::read('en_US');
        $wrong = [];
        foreach (self::read($locale) as $phrase => $translation) {
            $reference = str_starts_with($phrase, 'LedgerDirect.') ? ($english[$phrase] ?? $phrase) : $phrase;
            if (self::placeholders($reference) !== self::placeholders($translation)) {
                $wrong[] = $phrase;
            }
        }

        $this->assertSame([], $wrong, $locale . '.csv: placeholders or tags differ from the English');
    }

    public function testNoKeyIsLeftAsItsOwnEnglishText(): void
    {
        $left = array_keys(array_filter(
            self::read('en_US'),
            static fn(string $text): bool => str_starts_with($text, 'LedgerDirect.')
        ));

        $this->assertSame([], $left);
    }

    /**
     * @dataProvider translations
     */
    #[DataProvider('translations')]
    public function testNothingIsLeftInEnglish(string $locale): void
    {
        $english = self::read('en_US');
        $untranslated = [];
        foreach (self::read($locale) as $phrase => $translation) {
            $reference = $english[$phrase] ?? $phrase;
            if ($translation !== $reference || self::onlyNames($reference)) {
                continue;
            }
            if (in_array($locale, self::SAME_AS_ENGLISH[$reference] ?? [], true)) {
                continue;
            }
            $untranslated[] = $phrase;
        }

        $this->assertSame([], $untranslated, $locale . '.csv: still in English');
    }

    /**
     * @param string $locale
     * @return array<string, string> phrase => translation
     */
    private static function read(string $locale): array
    {
        $rows = [];
        $handle = fopen(self::file($locale), 'rb');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[(string) $row[0]] = (string) ($row[1] ?? '');
        }
        fclose($handle);

        return $rows;
    }

    private static function file(string $locale): string
    {
        return self::moduleDir() . '/i18n/' . $locale . '.csv';
    }

    /**
     * %1, %store_name, %network%, and every HTML tag, sorted
     *
     * @param string $text
     * @return string[]
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%\d+|%[a-z_]+%?|<\/?[a-z][^>]*>/i', $text, $found);
        $found = $found[0];
        sort($found);

        return $found;
    }

    private static function onlyNames(string $text): bool
    {
        $rest = (string) preg_replace('/%\d+|%[a-z_]+%?|<[^>]*>/i', ' ', $text);
        $rest = str_replace(self::NAMES, ' ', $rest);

        return preg_match('/\p{L}{2,}/u', $rest) !== 1;
    }

    /**
     * Every phrase the module's code asks to translate
     *
     * @return string[]
     */
    private static function phrases(): array
    {
        if (self::$phrases !== null) {
            return self::$phrases;
        }

        $root = self::moduleDir();
        $phrases = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('#^(tests|dev|vendor|docs|node_modules)/#', $relative)) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (preg_match('/\.(php|phtml)$/', $relative)) {
                $phrases[] = self::fromPhp($content, $relative);
            } elseif (preg_match('#^etc/.*\.xml$#', $relative)) {
                $phrases[] = self::fromXml($content);
            } elseif (preg_match('#^view/[^/]+/email/.*\.html$#', $relative)) {
                $phrases[] = self::fromEmail($content);
            } elseif (preg_match('#^view/[^/]+/web/template/.*\.html$#', $relative)) {
                $phrases[] = self::fromKnockout($content);
            } elseif (preg_match('#^view/[^/]+/web/.*\.js$#', $relative) && !str_ends_with($relative, '.min.js')) {
                $phrases[] = self::fromScript($content);
            }
        }

        // An order status label is stored in the database; Magento translates it on display.
        $phrases[] = [AddPaymentIncompleteStatus::LABEL];

        $phrases = array_values(array_unique(array_merge(...$phrases)));
        sort($phrases);

        return self::$phrases = $phrases;
    }

    /**
     * The first argument of every __() call, when it is a literal or literals joined by "."
     *
     * @param string $code
     * @param string $file
     * @return string[]
     */
    private static function fromPhp(string $code, string $file): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn($token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $phrases = [];
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__' || $tokens[$i + 1] !== '(') {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $literal = '';
            $complete = true;
            for ($j = $i + 2; $j < $count; $j++) {
                $part = $tokens[$j];
                if (is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literal .= self::unquote($part[1]);
                } elseif ($part === ',' || $part === ')') {
                    break;
                } elseif ($part !== '.') {
                    $complete = false;
                    break;
                }
            }

            if ($complete && $literal !== '') {
                $phrases[] = $literal;
            } elseif (isset(self::COMPOSED[$literal])) {
                foreach (self::COMPOSED[$literal] as $suffix) {
                    foreach ((new PaymentMethods())->supportedChains() as $chain) {
                        $phrases[] = $literal . $chain . $suffix;
                    }
                }
            } else {
                self::$nonLiteral[$file . ':' . $token[2]] = $literal;
            }
        }

        return $phrases;
    }

    private static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
            : stripcslashes($body);
    }

    /**
     * The children named in translate="…", e.g. a field's label and comment in system.xml, and the
     * label of an email template, which Magento translates when it lists the templates
     *
     * @param string $xml
     * @return string[]
     */
    private static function fromXml(string $xml): array
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $phrases = [];
        foreach ($xpath->query('//*[@translate]') ?: [] as $element) {
            /** @var DOMElement $element */
            $names = preg_split('/\s+/', trim($element->getAttribute('translate'))) ?: [];
            if ($names === ['true']) {
                $phrases[] = trim($element->textContent);
                continue;
            }
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->nodeName, $names, true)) {
                    $phrases[] = trim($child->textContent);
                }
            }
        }
        foreach ($xpath->query('/config/template[@label]') ?: [] as $template) {
            /** @var DOMElement $template */
            $phrases[] = $template->getAttribute('label');
        }

        return $phrases;
    }

    /**
     * @param string $html
     * @return string[]
     */
    private static function fromEmail(string $html): array
    {
        preg_match_all('/\{\{trans\s+(["\'])((?:\\\\.|(?!\1).)*)\1/s', $html, $found);

        return array_map('stripslashes', $found[2]);
    }

    /**
     * @param string $html
     * @return string[]
     */
    private static function fromKnockout(string $html): array
    {
        $phrases = [self::fromScript($html)];
        $patterns = [
            '/\bi18n:\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s',
            '/<translate\s+args="\s*([\'])((?:\\\\.|(?!\1).)*)\1\s*"/s',
            '/\stranslate="\s*([\'])((?:\\\\.|(?!\1).)*)\1\s*"/s',
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $found);
            $phrases[] = array_map('stripslashes', $found[2]);
        }

        return array_merge(...$phrases);
    }

    /**
     * $t('…') with a literal; $t(variable) translates what the server already translated
     *
     * @param string $script
     * @return string[]
     */
    private static function fromScript(string $script): array
    {
        preg_match_all('/\$t\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*\)/s', $script, $found);

        return array_map('stripslashes', $found[2]);
    }

    private static function moduleDir(): string
    {
        return (string) realpath(__DIR__ . '/../..');
    }
}
