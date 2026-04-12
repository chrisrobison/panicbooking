<?php

require_once __DIR__ . '/../../lib/ticketing.php';

function paymentMode(): string {
    $mode = strtolower(trim((string)(getenv('PB_PAYMENT_MODE') ?: 'demo')));
    if (!in_array($mode, ['demo', 'stripe'], true)) {
        $mode = 'demo';
    }
    return $mode;
}

function paymentCreateOrder(PDO $pdo, array $orderInput): array {
    return ticketingCreateOrder($pdo, $orderInput);
}

function paymentFinalizeSuccessfulOrder(PDO $pdo, int $orderId, ?string $paymentReference = null): array {
    $mode = paymentMode();

    if ($mode === 'demo') {
        return ticketingMarkOrderPaid($pdo, $orderId, 'demo', $paymentReference ?: ('demo_' . time()));
    }

    // Stripe mode is intentionally left as a future integration hook.
    throw new RuntimeException('Stripe payment mode is configured but not implemented in this MVP');
}
