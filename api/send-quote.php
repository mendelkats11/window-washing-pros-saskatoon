<?php
// Free-quote form handler for Hostinger shared hosting (plain PHP — no
// Node.js "web app" slot required). The Resend API key lives only in
// config.local.php, a file that is NOT committed to git and NOT touched by
// the auto-deploy workflow. See config.local.php.example for setup.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$configPath = __DIR__ . '/config.local.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    error_log('send-quote.php: config.local.php is missing on the server');
    echo json_encode(['ok' => false, 'error' => 'Server misconfiguration']);
    exit;
}
require $configPath; // defines RESEND_API_KEY

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request body']);
    exit;
}

function ww_clean($v) {
    return is_string($v) ? trim($v) : '';
}

function ww_escape($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$name = ww_clean($body['name'] ?? '');
$phone = ww_clean($body['phone'] ?? '');
$email = ww_clean($body['email'] ?? '');
$service = ww_clean($body['service'] ?? '');
$message = ww_clean($body['message'] ?? '');

if ($name === '' || $phone === '' || $email === '' || $service === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid email address']);
    exit;
}

if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
    http_response_code(500);
    error_log('send-quote.php: RESEND_API_KEY is not configured');
    echo json_encode(['ok' => false, 'error' => 'Server misconfiguration']);
    exit;
}

$serviceLabels = [
    'residential' => 'Residential Window Cleaning',
    'commercial' => 'Commercial Window Cleaning',
    'gutter' => 'Gutter Cleaning',
    'pressure' => 'Pressure & Soft Washing',
    'solar' => 'Solar Panel Cleaning',
    'screens' => 'Screen & Track Detailing',
    'post-construction' => 'Post-Construction Cleanup',
    'other' => 'Not Sure / Other',
];
$serviceLabel = $serviceLabels[$service] ?? ww_escape($service);

$html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#171310;line-height:1.6;">'
    . '<h2 style="margin:0 0 16px;">New Free Quote Request</h2>'
    . '<p style="margin:0 0 8px;"><strong>Name:</strong> ' . ww_escape($name) . '</p>'
    . '<p style="margin:0 0 8px;"><strong>Phone:</strong> ' . ww_escape($phone) . '</p>'
    . '<p style="margin:0 0 8px;"><strong>Email:</strong> ' . ww_escape($email) . '</p>'
    . '<p style="margin:0 0 8px;"><strong>Service:</strong> ' . ww_escape($serviceLabel) . '</p>'
    . '<p style="margin:16px 0 4px;"><strong>Details:</strong></p>'
    . '<p style="margin:0; white-space:pre-wrap;">' . nl2br(ww_escape($message !== '' ? $message : '(none provided)')) . '</p>'
    . '<hr style="margin:24px 0; border:none; border-top:1px solid #e6ded1;">'
    . '<p style="margin:0; font-size:12px; color:#6b6258;">Submitted from the Window Washing Pros of Saskatoon website contact form.</p>'
    . '</div>';

$payload = json_encode([
    'from' => 'Window Washing Pros of Saskatoon <onboarding@resend.dev>',
    'to' => ['mendelkat10@gmail.com'],
    'reply_to' => $email,
    'subject' => 'New Quote Request — ' . $name . ' (' . $serviceLabel . ')',
    'html' => $html,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    error_log('send-quote.php: Resend API error ' . $httpCode . ' ' . $curlErr . ' ' . $response);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Failed to send email']);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true]);
