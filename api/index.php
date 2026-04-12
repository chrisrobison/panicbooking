<?php
// LastCall SF — API Entry Point
// All requests routed through here via .htaccess rewrite

// Headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS (same-origin, but allow credentials)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
// Only allow the same host
header('Vary: Origin');
if (!empty($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load dependencies
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/response.php';
require_once __DIR__ . '/handlers/auth.php';
require_once __DIR__ . '/handlers/bands.php';
require_once __DIR__ . '/handlers/venues.php';
require_once __DIR__ . '/handlers/events.php';
require_once __DIR__ . '/handlers/users.php';
require_once __DIR__ . '/handlers/bookings.php';
require_once __DIR__ . '/handlers/scores.php';
require_once __DIR__ . '/handlers/admin.php';
require_once __DIR__ . '/handlers/ticketing.php';
require_once __DIR__ . '/handlers/payments.php';

// Parse route
$requestUri    = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUrl     = parse_url($requestUri);
$path          = $parsedUrl['path'] ?? '/';
$method        = strtoupper($_SERVER['REQUEST_METHOD']);

// Normalize: strip /api prefix and trailing slash
$path = preg_replace('#^/api#', '', $path);
$path = rtrim($path, '/') ?: '/';

// Split into segments
$segments = array_values(array_filter(explode('/', $path)));
// $segments[0] = 'auth'|'bands'|'venues'|'users'
// $segments[1] = sub-action or ID

$resource = $segments[0] ?? '';
$sub      = $segments[1] ?? '';
$id       = is_numeric($sub) ? (int)$sub : null;

// ===================== ROUTING =====================

// --- Dark Nights ---
if ($resource === 'dark-nights' && $method === 'GET') {
    handleDarkNights($pdo);

// --- Bookings ---
} elseif ($resource === 'bookings') {
    if ($sub === 'interests' && $method === 'GET') {
        handleBookingInterestsList($pdo);
    } elseif ($sub === 'interest' && $method === 'POST') {
        handleBookingInterestCreate($pdo);
    } else {
        errorResponse('Not found', 404);
    }

// --- Auth ---
} elseif ($resource === 'auth') {
    if ($sub === 'login' && $method === 'POST') {
        handleAuthLogin($pdo);
    } elseif ($sub === 'signup' && $method === 'POST') {
        handleAuthSignup($pdo);
    } elseif ($sub === 'logout' && $method === 'POST') {
        handleAuthLogout();
    } elseif ($sub === 'me' && $method === 'GET') {
        handleAuthMe();
    } else {
        errorResponse('Not found', 404);
    }

// --- Bands ---
} elseif ($resource === 'bands') {
    if ($id === null) {
        // /api/bands
        if ($method === 'GET') {
            handleBandsList($pdo);
        } else {
            errorResponse('Method not allowed', 405);
        }
    } else {
        // /api/bands/{id}
        if ($method === 'GET') {
            handleBandsGet($pdo, $id);
        } elseif ($method === 'PUT') {
            handleBandsUpdate($pdo, $id);
        } elseif ($method === 'DELETE') {
            handleBandsDelete($pdo, $id);
        } else {
            errorResponse('Method not allowed', 405);
        }
    }

// --- Venues ---
} elseif ($resource === 'venues') {
    if ($id === null) {
        if ($method === 'GET') {
            handleVenuesList($pdo);
        } else {
            errorResponse('Method not allowed', 405);
        }
    } else {
        if ($method === 'GET') {
            handleVenuesGet($pdo, $id);
        } elseif ($method === 'PUT') {
            handleVenuesUpdate($pdo, $id);
        } elseif ($method === 'DELETE') {
            handleVenuesDelete($pdo, $id);
        } else {
            errorResponse('Method not allowed', 405);
        }
    }

// --- Events (Foopee "The List") ---
} elseif ($resource === 'events') {
    if ($id === null) {
        if ($sub === 'stats' && $method === 'GET') {
            handleEventsStats($pdo);
        } elseif ($method === 'GET') {
            handleEventsList($pdo);
        } else {
            errorResponse('Method not allowed', 405);
        }
    } else {
        if ($method === 'GET') {
            handleEventsGet($pdo, $id);
        } else {
            errorResponse('Method not allowed', 405);
        }
    }

// --- Users ---
} elseif ($resource === 'users') {
    if ($sub === 'me') {
        if ($method === 'GET') {
            handleUsersGetMe($pdo);
        } elseif ($method === 'PUT') {
            handleUsersUpdateMe($pdo);
        } elseif ($method === 'DELETE') {
            handleUsersDeleteMe($pdo);
        } else {
            errorResponse('Method not allowed', 405);
        }
    } else {
        errorResponse('Not found', 404);
    }

// --- Scores ---
} elseif ($resource === 'scores') {
    if ($id === null && $sub === '') {
        if ($method === 'GET') handleScoresList($pdo);
        else errorResponse('Method not allowed', 405);
    } elseif ($sub === 'report') {
        if ($method === 'POST') handleShowReportCreate($pdo);
        else errorResponse('Method not allowed', 405);
    } else {
        // $sub is the band name (URL-encoded)
        if ($method === 'GET') handleScoresGet($pdo, urldecode($sub));
        else errorResponse('Method not allowed', 405);
    }

// --- Admin ---
} elseif ($resource === 'admin') {
    // $sub = 'users', $segments[2] = id, $segments[3] = 'admin'
    $adminId  = isset($segments[2]) && is_numeric($segments[2]) ? (int)$segments[2] : null;
    $adminSub = $segments[3] ?? '';

    if ($sub === 'users') {
        if ($adminId === null) {
            if ($method === 'GET')  handleAdminListUsers($pdo);
            elseif ($method === 'POST') handleAdminCreateUser($pdo);
            else errorResponse('Method not allowed', 405);
        } elseif ($adminSub === 'admin') {
            if ($method === 'PUT') handleAdminSetAdminFlag($pdo, $adminId);
            else errorResponse('Method not allowed', 405);
        } else {
            if ($method === 'DELETE') handleAdminDeleteUser($pdo, $adminId);
            else errorResponse('Method not allowed', 405);
        }
    } else {
        errorResponse('Not found', 404);
    }

// --- Ticketing ---
} elseif ($resource === 'ticketing') {
    if ($sub === 'create_event' && $method === 'POST') {
        handleTicketingCreateEvent($pdo);
    } elseif ($sub === 'update_event' && ($method === 'POST' || $method === 'PUT')) {
        handleTicketingUpdateEvent($pdo);
    } elseif ($sub === 'create_ticket_type' && $method === 'POST') {
        handleTicketingCreateTicketType($pdo);
    } elseif ($sub === 'update_ticket_type' && ($method === 'POST' || $method === 'PUT')) {
        handleTicketingUpdateTicketType($pdo);
    } elseif ($sub === 'create_order' && $method === 'POST') {
        handleTicketingCreateOrder($pdo);
    } elseif ($sub === 'mark_order_paid' && $method === 'POST') {
        handleTicketingMarkOrderPaid($pdo);
    } elseif ($sub === 'validate_ticket' && $method === 'POST') {
        handleTicketingValidateTicket($pdo);
    } elseif ($sub === 'check_in_ticket' && $method === 'POST') {
        handleTicketingCheckInTicket($pdo);
    } elseif ($sub === 'search_ticket_by_code' && $method === 'GET') {
        handleTicketingSearchTicketByCode($pdo);
    } else {
        errorResponse('Not found', 404);
    }

// --- Payments ---
} elseif ($resource === 'payments') {
    if ($sub === 'stripe_webhook' && $method === 'POST') {
        handlePaymentsStripeWebhook($pdo);
    } else {
        errorResponse('Not found', 404);
    }

} else {
    errorResponse('API endpoint not found', 404);
}
