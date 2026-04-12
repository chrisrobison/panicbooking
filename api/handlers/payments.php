<?php

require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../../lib/ticketing.php';

function paymentsStripeSignatureHeader(): string {
    $header = trim((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    if ($header !== '') {
        return $header;
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Stripe-Signature') === 0) {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function paymentsSquareSignatureHeader(): string {
    $header = trim((string)($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? ''));
    if ($header !== '') {
        return $header;
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'x-square-hmacsha256-signature') === 0) {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function handlePaymentsStripeWebhook(PDO $pdo): void {
    if (paymentProvider() !== 'stripe') {
        errorResponse('Stripe webhook is disabled for current payment mode', 409);
    }

    $payload = file_get_contents('php://input');
    if (!is_string($payload) || trim($payload) === '') {
        errorResponse('Empty webhook payload', 400);
    }

    $signature = paymentsStripeSignatureHeader();
    if ($signature === '') {
        errorResponse('Missing Stripe-Signature header', 400);
    }

    try {
        $result = paymentHandleStripeWebhook($pdo, $payload, $signature);
        jsonResponse(['received' => true, 'result' => $result]);
    } catch (Throwable $e) {
        $message = strtolower((string)$e->getMessage());
        $isClientIssue = str_contains($message, 'signature') || str_contains($message, 'invalid stripe webhook');
        $status = $isClientIssue ? 400 : 500;

        ticketingLog('stripe_webhook_failed', [
            'error' => $e->getMessage(),
            'status' => $status,
        ]);

        errorResponse($isClientIssue ? 'Invalid Stripe webhook request' : 'Failed to process Stripe webhook', $status);
    }
}

function handlePaymentsSquareWebhook(PDO $pdo): void {
    if (paymentProvider() !== 'square') {
        errorResponse('Square webhook is disabled for current payment mode', 409);
    }

    $payload = file_get_contents('php://input');
    if (!is_string($payload) || trim($payload) === '') {
        errorResponse('Empty webhook payload', 400);
    }

    $signature = paymentsSquareSignatureHeader();
    if ($signature === '') {
        errorResponse('Missing x-square-hmacsha256-signature header', 400);
    }

    try {
        $result = paymentHandleSquareWebhook($pdo, $payload, $signature);
        jsonResponse(['received' => true, 'result' => $result]);
    } catch (Throwable $e) {
        $message = strtolower((string)$e->getMessage());
        $isClientIssue = str_contains($message, 'signature') || str_contains($message, 'invalid square webhook');
        $status = $isClientIssue ? 400 : 500;

        ticketingLog('square_webhook_failed', [
            'error' => $e->getMessage(),
            'status' => $status,
        ]);

        errorResponse($isClientIssue ? 'Invalid Square webhook request' : 'Failed to process Square webhook', $status);
    }
}
