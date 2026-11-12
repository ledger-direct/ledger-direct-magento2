<?php
/**
 * The try-it shop's testnet wallet, kept apart from Magento: its own vendor/ under
 * /opt/ledger-direct/wallet (hardcastle/xrpl_php) and its own process, so its dependencies never meet
 * Magento's. The wallet file holds the account's seed — testnet only, mode 0600, in the stack's volume,
 * never printed. What is printed is the address.
 *
 *   wallet.php create <file>           a fresh, funded account from the testnet faucet
 *   wallet.php exists <file>           exit 3 if the account is gone (the testnet was reset)
 *   wallet.php trust  <file> <json>    TrustSet for each LimitAmount in the JSON array, once each
 *   wallet.php xahau  <file>           the shop's own Xahau testnet account: made here, funded by the Xahau
 *                                      faucet; exit 4 while it is not on the Xahau testnet (yet)
 */
declare(strict_types=1);

require '/opt/ledger-direct/wallet/vendor/autoload.php';

use Hardcastle\XRPL_PHP\Client\JsonRpcClient;
use Hardcastle\XRPL_PHP\Models\Account\AccountInfoRequest;
use Hardcastle\XRPL_PHP\Models\ErrorResponse;
use Hardcastle\XRPL_PHP\Wallet\Wallet;

[$script, $command, $file] = $argv + [null, null, null];
$client = new JsonRpcClient('testnet', null, null, 20.0);

/** The Xahau testnet and its faucet, which funds a given address with 1000 XAH. */
const XAHAU_RPC = 'https://xahau-test.net';
const XAHAU_FAUCET = 'https://xahau-test.net/accounts';
// XAH is optional for the shop's start: the whole 'xahau' command stays within about two minutes
// (10 s per RPC call, 30 s for the faucet, 60 s for the account to appear), far inside the image's
// 600 s start period, so a slow Xahau testnet never holds up the rest of the shop.
const XAHAU_RPC_TIMEOUT = 10.0;
const XAHAU_FAUCET_TIMEOUT = 30;
const XAHAU_APPEAR_SECONDS = 60;

function out(string $message): void
{
    fwrite(STDOUT, '[ledger-direct-try] ' . $message . "\n");
}

/** @return array{address: string, seed: string, trust_lines?: list<string>} */
function load(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['address'], $data['seed'])) {
        throw new RuntimeException("{$file} is not a wallet file");
    }

    return $data;
}

function save(string $file, array $data): void
{
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
    chmod($tmp, 0600);
    rename($tmp, $file);
}

/** Whether the account exists on the validated testnet ledger; null when the node cannot say. */
function accountExists(JsonRpcClient $client, string $address): ?bool
{
    try {
        $response = $client->syncRequest(new AccountInfoRequest(account: $address, ledgerIndex: 'validated'));
    } catch (Throwable $unreachable) {
        return null;
    }
    if ($response instanceof ErrorResponse) {
        return $response->getError() === 'actNotFound' ? false : null;
    }

    return true;
}

switch ($command) {
    case 'create':
        for ($attempt = 1; ; $attempt++) {
            try {
                $wallet = $client->fundWallet();
                break;
            } catch (Throwable $faucet) {
                if ($attempt === 5) {
                    throw new RuntimeException('The testnet faucet is unreachable; set LD_TESTNET_ACCOUNT to an address of your own.', 0, $faucet);
                }
                out("testnet faucet did not answer (attempt {$attempt}), retrying");
                sleep(5 * $attempt);
            }
        }
        save($file, ['address' => $wallet->getAddress(), 'seed' => $wallet->getSeed()]);
        echo $wallet->getAddress(), "\n";
        exit(0);

    case 'exists':
        exit(accountExists($client, load($file)['address']) === false ? 3 : 0);

    case 'trust':
        $data = load($file);
        $wallet = Wallet::fromSeed($data['seed']);
        $lines = json_decode((string) ($argv[3] ?? '[]'), true) ?: [];
        $done = $data['trust_lines'] ?? [];
        $pending = array_values(array_filter(
            $lines,
            static fn (array $limit): bool => !in_array($limit['currency'] . '.' . $limit['issuer'], $done, true)
        ));

        // Recorded once, set for good: a restart needs no node at all, so a node that is down or slow
        // cannot switch RLUSD and USDC off.
        if ($pending === []) {
            exit(0);
        }

        // A faucet account takes a ledger or two to show up as validated; autofill needs it.
        for ($wait = 0; accountExists($client, $data['address']) !== true; $wait++) {
            if ($wait === 15) {
                throw new RuntimeException('the new testnet account did not appear on the ledger');
            }
            sleep(2);
        }

        foreach ($pending as $limit) {
            $key = $limit['currency'] . '.' . $limit['issuer'];
            $response = $client->submitAndWait([
                'TransactionType' => 'TrustSet',
                'Account' => $wallet->getAddress(),
                'LimitAmount' => $limit,
            ], autofill: true, wallet: $wallet);
            $result = $response->getResult()['meta']['TransactionResult'] ?? 'unknown';
            if ($result !== 'tesSUCCESS') {
                throw new RuntimeException("TrustSet to {$limit['issuer']} failed: {$result}");
            }
            $done[] = $key;
            $data['trust_lines'] = $done;
            save($file, $data);
        }
        exit(0);

    case 'xahau':
        // An account of its own on Xahau, not the XRP Ledger one: the same r-address is a different
        // account on each chain, and a payment sent on the wrong chain must not reach the shop
        // (one account per chain). The seed is made here and never leaves the volume; the faucet
        // gets the address only. After a Xahau testnet reset the same address is funded again.
        if (!is_file($file)) {
            $generated = Wallet::generate();
            save($file, ['address' => $generated->getAddress(), 'seed' => $generated->getSeed()]);
        }
        $address = load($file)['address'];
        $xahau = new JsonRpcClient(XAHAU_RPC, null, null, XAHAU_RPC_TIMEOUT);
        $exists = accountExists($xahau, $address);
        if ($exists === null) {
            out('the Xahau testnet does not answer');
            exit(4);
        }
        if ($exists === true) {
            exit(0);
        }
        // The faucet answers a refusal with 200 and an "error" field (it rate-limits per address and IP).
        try {
            $answer = json_decode((string) (new GuzzleHttp\Client(['timeout' => XAHAU_FAUCET_TIMEOUT]))->post(XAHAU_FAUCET, [
                'json' => ['destination' => $address],
                'http_errors' => false,
            ])->getBody(), true);
        } catch (Throwable $unreachable) {
            $answer = null;
        }
        if (!is_array($answer) || isset($answer['error'])) {
            out('the Xahau testnet faucet did not fund the account' . (isset($answer['error']) ? ': ' . $answer['error'] : ''));
            exit(4);
        }
        $deadline = time() + XAHAU_APPEAR_SECONDS;
        while (accountExists($xahau, $address) !== true) {
            if (time() >= $deadline) {
                out('the account did not appear on the Xahau testnet');
                exit(4);
            }
            sleep(2);
        }
        exit(0);

    default:
        fwrite(STDERR, "usage: wallet.php create|exists|trust|xahau <file> [json]\n");
        exit(2);
}
