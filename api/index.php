<?php
// LastCall SF — API Entry Point
// All requests routed through here via .htaccess rewrite

require_once __DIR__ . '/../lib/security.php';

if (!panicDebugEnabled()) {
    ini_set('display_errors', '0');
}

// Headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS (same-origin only, allow credentials)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Vary: Origin');
if (!empty($origin)) {
    $originHost = (string)(parse_url($origin, PHP_URL_HOST) ?? '');
    $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    $requestHost = strtolower(preg_replace('/:\d+$/', '', $requestHost));
    $originHost = strtolower($originHost);
    if ($originHost !== '' && $originHost === $requestHost) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
    }
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load dependencies
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/response.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/handlers/auth.php';
require_once __DIR__ . '/handlers/bands.php';
require_once __DIR__ . '/handlers/venues.php';
require_once __DIR__ . '/handlers/events.php';
require_once __DIR__ . '/handlers/users.php';
require_once __DIR__ . '/handlers/bookings.php';
require_once __DIR__ . '/handlers/scores.php';
require_once __DIR__ . '/handlers/admin.php';
require_once __DIR__ . '/handlers/claims.php';
require_once __DIR__ . '/handlers/ticketing.php';
require_once __DIR__ . '/handlers/payments.php';

set_exception_handler(static function (Throwable $e): void {
    panicLog('api_unhandled_exception', [
        'path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'message' => $e->getMessage(),
        'type' => get_class($e),
    ], 'error');

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Internal server error']);
    exit;
});

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
    $bookingId = isset($segments[1]) && ctype_digit((string)$segments[1]) ? (int)$segments[1] : null;

    if ($sub === 'interests' && $method === 'GET') {
        handleBookingInterestsList($pdo);
    } elseif ($sub === 'interest' && $method === 'POST') {
        handleBookingInterestCreate($pdo);
    } elseif ($sub === 'opportunities') {
        $oppId = isset($segments[2]) && ctype_digit((string)$segments[2]) ? (int)$segments[2] : null;
        $oppSub = $segments[3] ?? '';

        if ($oppId === null) {
            if ($method === 'GET') {
                handleBookingOpportunityList($pdo);
            } elseif ($method === 'POST') {
                handleBookingOpportunityCreate($pdo);
            } else {
                errorResponse('Method not allowed', 405);
            }
        } else {
            if ($oppSub === '' && $method === 'GET') {
                handleBookingOpportunityGet($pdo, $oppId);
            } elseif ($oppSub === 'inquiries' && $method === 'POST') {
                handleBookingInquiryCreate($pdo, $oppId);
            } elseif ($oppSub === 'status' && ($method === 'POST' || $method === 'PUT')) {
                handleBookingOpportunityStatusUpdate($pdo, $oppId);
            } else {
                errorResponse('Not found', 404);
            }
        }
    } elseif ($sub === 'mine' && $method === 'GET') {
        handleBookingMineList($pdo);
    } elseif ($sub === 'requests' && $method === 'GET') {
        handleBookingRequestsList($pdo);
    } elseif ($bookingId !== null) {
        $bookingSub = $segments[2] ?? '';
        if ($bookingSub === '' && $method === 'GET') {
            handleBookingGet($pdo, $bookingId);
        } elseif ($bookingSub === 'transition' && ($method === 'POST' || $method === 'PUT')) {
            handleBookingTransition($pdo, $bookingId);
        } elseif ($bookingSub === 'notes' && $method === 'POST') {
            handleBookingNoteCreate($pdo, $bookingId);
        } else {
            errorResponse('Not found', 404);
        }
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
    } elseif ($sub === 'password-reset-request' && $method === 'POST') {
        handleAuthPasswordResetRequest($pdo);
    } elseif ($sub === 'password-reset-confirm' && $method === 'POST') {
        handleAuthPasswordResetConfirm($pdo);
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

// --- Event Sync feeds ---
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

// --- Claims ---
} elseif ($resource === 'claims') {
    if ($sub === '' && $method === 'POST') {
        handleClaimsCreate($pdo);
    } elseif ($sub === 'mine' && $method === 'GET') {
        handleClaimsMineList($pdo);
    } elseif ($id !== null) {
        $claimSub = $segments[2] ?? '';
        if ($claimSub === '' && $method === 'GET') {
            handleClaimsMineGet($pdo, $id);
        } elseif ($claimSub === 'cancel' && ($method === 'POST' || $method === 'PUT')) {
            handleClaimsCancel($pdo, $id);
        } else {
            errorResponse('Not found', 404);
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
    // users:  /api/admin/users/{id}/admin
    // claims: /api/admin/claims/{id}/approve
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
    } elseif ($sub === 'claims') {
        if ($adminId === null) {
            if ($method === 'GET') handleAdminClaimsList($pdo);
            else errorResponse('Method not allowed', 405);
        } else {
            if ($adminSub === '' && $method === 'GET') {
                handleAdminClaimGet($pdo, $adminId);
            } elseif ($adminSub === 'approve' && ($method === 'POST' || $method === 'PUT')) {
                handleAdminClaimApprove($pdo, $adminId);
            } elseif ($adminSub === 'reject' && ($method === 'POST' || $method === 'PUT')) {
                handleAdminClaimReject($pdo, $adminId);
            } else {
                errorResponse('Method not allowed', 405);
            }
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
