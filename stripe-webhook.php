<?php
require_once __DIR__ . '/api/includes/db.php';
require_once __DIR__ . '/api/includes/payment.php';
require_once __DIR__ . '/lib/ticketing.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (paymentMode() !== 'stripe') {
    http_response_code(409);
    echo json_encode(['error' => 'Stripe webhook is disabled for current payment mode']);
    exit;
}

$payload = file_get_contents('php://input');
if (!is_string($payload) || trim($payload) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty webhook payload']);
    exit;
}

$signature = trim((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
if ($signature === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string)$name, 'Stripe-Signature') === 0) {
                $signature = trim((string)$value);
                break;
            }
        }
    }
}

if ($signature === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Stripe-Signature header']);
    exit;
}

try {
    $result = paymentHandleStripeWebhook($pdo, $payload, $signature);
    echo json_encode(['received' => true, 'result' => $result]);
} catch (Throwable $e) {
    $message = strtolower((string)$e->getMessage());
    $isClientIssue = str_contains($message, 'signature') || str_contains($message, 'invalid stripe webhook');
    $status = $isClientIssue ? 400 : 500;

    ticketingLog('stripe_webhook_failed', [
        'error' => $e->getMessage(),
        'status' => $status,
    ]);

    http_response_code($status);
    echo json_encode(['error' => $isClientIssue ? 'Invalid Stripe webhook request' : 'Failed to process Stripe webhook']);
}
