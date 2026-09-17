<?php
// GitHub webhook receiver — auto-deploys the latest `main` branch onto this
// server whenever GitHub notifies us of a push. No FTP, no GitHub Actions:
// this script downloads the latest code over plain HTTPS (like visiting a
// webpage) and writes it to disk with normal PHP file operations, since it's
// already running on this same server.
//
// SECURITY: every request is verified against DEPLOY_WEBHOOK_SECRET (in
// config.local.php) using the signature GitHub sends. Anything that fails
// verification is rejected immediately, before any deploy logic runs.

header('Content-Type: application/json');

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$configPath = __DIR__ . '/config.local.php';
if (!file_exists($configPath)) {
    error_log('deploy.php: config.local.php is missing on the server');
    respond(500, ['ok' => false, 'error' => 'Server misconfiguration']);
}
require $configPath; // defines RESEND_API_KEY and DEPLOY_WEBHOOK_SECRET

if (!defined('DEPLOY_WEBHOOK_SECRET') || DEPLOY_WEBHOOK_SECRET === '') {
    error_log('deploy.php: DEPLOY_WEBHOOK_SECRET is not configured');
    respond(500, ['ok' => false, 'error' => 'Server misconfiguration']);
}

$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, DEPLOY_WEBHOOK_SECRET);

if ($signatureHeader === '' || !hash_equals($expected, $signatureHeader)) {
    error_log('deploy.php: invalid webhook signature');
    respond(401, ['ok' => false, 'error' => 'Invalid signature']);
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    respond(200, ['ok' => true, 'message' => 'pong']);
}
if ($event !== 'push') {
    respond(200, ['ok' => true, 'message' => 'Ignored event: ' . $event]);
}

$payload = json_decode($rawBody, true);
$ref = is_array($payload) ? ($payload['ref'] ?? '') : '';
if ($ref !== 'refs/heads/main') {
    respond(200, ['ok' => true, 'message' => 'Ignored ref: ' . $ref]);
}

$repoOwner = 'mendelkats11';
$repoName = 'window-washing-pros-saskatoon';
// Public repo — plain archive download, no auth needed.
$zipUrl = "https://github.com/{$repoOwner}/{$repoName}/archive/refs/heads/main.zip";

if (!class_exists('ZipArchive')) {
    error_log('deploy.php: ZipArchive extension is not available');
    respond(500, ['ok' => false, 'error' => 'Server missing zip support']);
}

$workId = bin2hex(random_bytes(6));
$tmpDir = sys_get_temp_dir() . '/ww_deploy_' . $workId;
$zipPath = $tmpDir . '.zip';

$ch = curl_init($zipUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERAGENT => 'window-washing-deploy-webhook',
]);
$zipData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($zipData === false || $httpCode !== 200) {
    error_log("deploy.php: failed to download zip (HTTP $httpCode) $curlErr");
    respond(502, ['ok' => false, 'error' => 'Failed to download update']);
}

file_put_contents($zipPath, $zipData);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    @unlink($zipPath);
    error_log('deploy.php: failed to open downloaded zip');
    respond(500, ['ok' => false, 'error' => 'Failed to open update archive']);
}

mkdir($tmpDir, 0755, true);
$zip->extractTo($tmpDir);
$zip->close();

// GitHub archive zips wrap everything in one top-level folder, e.g.
// "window-washing-pros-saskatoon-main" — find it dynamically rather than
// hardcoding the name.
$topLevel = null;
foreach (scandir($tmpDir) as $entry) {
    if ($entry !== '.' && $entry !== '..' && is_dir($tmpDir . '/' . $entry)) {
        $topLevel = $tmpDir . '/' . $entry;
        break;
    }
}

if (!$topLevel) {
    ww_deploy_rrmdir($tmpDir);
    @unlink($zipPath);
    error_log('deploy.php: could not locate extracted source folder');
    respond(500, ['ok' => false, 'error' => 'Failed to read update archive']);
}

// Files that must never be overwritten by a deploy — belt-and-suspenders,
// since these are also gitignored and never actually present in the zip.
$protectedRelativePaths = [
    'api/config.local.php',
];

$targetRoot = dirname(__DIR__); // public_html

function ww_deploy_copy($src, $dest, $targetRoot, $protected) {
    foreach (scandir($src) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $srcPath = $src . '/' . $entry;
        $destPath = $dest . '/' . $entry;
        $relative = ltrim(str_replace($targetRoot, '', $destPath), '/\\');

        if (in_array($relative, $protected, true)) {
            continue;
        }

        if (is_dir($srcPath)) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
            ww_deploy_copy($srcPath, $destPath, $targetRoot, $protected);
        } else {
            copy($srcPath, $destPath);
        }
    }
}

function ww_deploy_rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? ww_deploy_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

ww_deploy_copy($topLevel, $targetRoot, $targetRoot, $protectedRelativePaths);

ww_deploy_rrmdir($tmpDir);
@unlink($zipPath);

respond(200, ['ok' => true, 'message' => 'Deployed successfully']);
