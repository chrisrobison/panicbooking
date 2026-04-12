<?php

function apiReadJsonBody(): array {
    if (isset($GLOBALS['API_PARSED_JSON_BODY']) && is_array($GLOBALS['API_PARSED_JSON_BODY'])) {
        return $GLOBALS['API_PARSED_JSON_BODY'];
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function apiSanitizeText($value, int $maxLen = 0): string {
    $text = trim((string)$value);
    if ($maxLen > 0 && strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
    }
    return $text;
}

function apiNormalizeEmail($value): string {
    return strtolower(apiSanitizeText($value, 320));
}

function apiRequireEmail($value, string $field = 'email'): string {
    $email = apiNormalizeEmail($value);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errorResponse($field . ' is invalid', 422);
    }
    return $email;
}

function apiRequireId($value, string $field = 'id'): int {
    $id = (int)$value;
    if ($id <= 0) {
        errorResponse($field . ' is required', 422);
    }
    return $id;
}

function apiRequireEnum($value, array $allowed, string $field): string {
    $normalized = apiSanitizeText($value, 64);
    if (!in_array($normalized, $allowed, true)) {
        errorResponse($field . ' is invalid', 422);
    }
    return $normalized;
}

function apiRequireDateYmd($value, string $field): string {
    $date = apiSanitizeText($value, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        errorResponse($field . ' must be YYYY-MM-DD', 422);
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        errorResponse($field . ' must be a valid date', 422);
    }

    return $date;
}

function apiClampInt($value, int $min, int $max): int {
    $num = (int)$value;
    if ($num < $min) {
        return $min;
    }
    if ($num > $max) {
        return $max;
    }
    return $num;
}
