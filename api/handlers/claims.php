<?php
// Claim handlers: seeded profile claim requests, dedupe checks, admin review.

function handleClaimsCreate(PDO $pdo): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = apiReadJsonBody();

    $entityType = apiRequireEnum($body['entity_type'] ?? '', ['band', 'venue'], 'entity_type');
    $entityUserId = apiRequireId($body['entity_user_id'] ?? 0, 'entity_user_id');
    if ($current['type'] !== $entityType && !$current['is_admin']) {
        errorResponse('You can only claim profiles that match your account type', 403);
    }
    if ($current['id'] === $entityUserId) {
        errorResponse('This profile already belongs to your account', 409);
    }

    $entity = claimLoadEntityProfile($pdo, $entityType, $entityUserId, false);
    if (!$entity) {
        errorResponse('Profile not found or already archived', 404);
    }
    if (!$entity['is_generic']) {
        errorResponse('Only seeded profiles can be claimed', 409);
    }

    $dupPendingStmt = $pdo->prepare("
        SELECT id
        FROM claim_requests
        WHERE entity_type = ? AND entity_user_id = ? AND claimant_user_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $dupPendingStmt->execute([$entityType, $entityUserId, $current['id']]);
    if ($dupPendingStmt->fetchColumn()) {
        errorResponse('You already have a pending claim for this profile', 409);
    }

    $claimantProfile = claimLoadEntityProfile($pdo, $current['type'], (int)$current['id'], false);
    if ($claimantProfile) {
        $entityFp = claimBuildFingerprint($entity['data'], $entityType, $entity['user_email']);
        $claimantFp = claimBuildFingerprint($claimantProfile['data'], $entityType, $current['email']);
        $selfDup = claimCompareFingerprints($entityFp, $claimantFp, $entityType);
        if ($selfDup['score'] >= 70 && !$claimantProfile['is_generic']) {
            errorResponse('Your account already has a very similar profile. Ask an admin to merge instead.', 409);
        }
    }

    $representativeName = trim((string)($body['representative_name'] ?? ''));
    $representativeRole = trim((string)($body['representative_role'] ?? ''));
    $contactEmail = apiNormalizeEmail($body['contact_email'] ?? $current['email']);
    $contactPhone = trim((string)($body['contact_phone'] ?? ''));
    $website = trim((string)($body['website'] ?? ''));
    $evidenceLinks = trim((string)($body['evidence_links'] ?? ''));
    $supportingInfo = trim((string)($body['supporting_info'] ?? ''));

    if ($representativeName === '') {
        errorResponse('Representative name is required');
    }
    if ($supportingInfo === '') {
        errorResponse('Please provide supporting details for this claim');
    }
    $contactEmail = apiRequireEmail($contactEmail, 'contact_email');

    $entityFp = claimBuildFingerprint($entity['data'], $entityType, $entity['user_email']);
    $dedupe = claimFindDuplicateCandidates($pdo, $entityType, $entityUserId, $entityFp);

    $insert = $pdo->prepare("
        INSERT INTO claim_requests (
            entity_type, entity_user_id, claimant_user_id, status,
            representative_name, representative_role, contact_email, contact_phone,
            website, evidence_links, supporting_info,
            dedupe_score, dedupe_notes, duplicate_candidates
        ) VALUES (
            ?, ?, ?, 'pending',
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?
        )
    ");
    $insert->execute([
        $entityType,
        $entityUserId,
        $current['id'],
        $representativeName,
        $representativeRole,
        $contactEmail,
        $contactPhone,
        $website,
        $evidenceLinks,
        $supportingInfo,
        $dedupe['max_score'],
        $dedupe['notes'],
        json_encode($dedupe['candidates'], JSON_UNESCAPED_UNICODE),
    ]);
    $claimId = (int)$pdo->lastInsertId();

    claimLogAction(
        $pdo,
        $claimId,
        (int)$current['id'],
        'claim_submitted',
        'Claim request submitted by representative',
        [
            'entity_type' => $entityType,
            'entity_user_id' => $entityUserId,
            'dedupe_score' => $dedupe['max_score'],
        ]
    );

    $autoApproved = false;
    $autoReason = '';
    if (claimCanAutoApprove($entity, $current['email'], $contactEmail, $dedupe['max_score'])) {
        $autoReason = 'Auto-approved because claimant email exactly matched profile contact email.';
        claimApproveRequest($pdo, $claimId, null, $autoReason, true);
        $autoApproved = true;
    }

    $claim = claimFetchRequest($pdo, $claimId, (int)$current['id'], false);
    jsonResponse([
        'success' => true,
        'claim' => $claim,
        'auto_approved' => $autoApproved,
        'message' => $autoApproved
            ? 'Claim was auto-approved.'
            : 'Claim submitted and waiting for review.',
    ], 201);
}

function handleClaimsMineList(PDO $pdo): void {
    apiRequireAuth();
    $current = apiCurrentUser();

    $status = trim((string)($_GET['status'] ?? ''));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

    $where = ["c.claimant_user_id = :uid"];
    $params = [':uid' => (int)$current['id']];
    if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'canceled'], true)) {
        $where[] = "c.status = :status";
        $params[':status'] = $status;
    }
    $whereClause = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM claim_requests c WHERE {$whereClause}");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.*,
               eu.email AS entity_user_email,
               cu.email AS claimant_email,
               ru.email AS reviewer_email,
               p.data AS entity_profile_data
        FROM claim_requests c
        JOIN users eu ON eu.id = c.entity_user_id
        JOIN users cu ON cu.id = c.claimant_user_id
        LEFT JOIN users ru ON ru.id = c.reviewed_by_user_id
        LEFT JOIN profiles p ON p.user_id = c.entity_user_id
        WHERE {$whereClause}
        ORDER BY c.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $claims = array_map('claimHydrateRequestRow', $stmt->fetchAll());
    jsonResponse(['claims' => $claims, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
}

function handleClaimsMineGet(PDO $pdo, int $claimId): void {
    apiRequireAuth();
    $current = apiCurrentUser();
    $claim = claimFetchRequest($pdo, $claimId, (int)$current['id'], false);
    if (!$claim) {
        errorResponse('Claim not found', 404);
    }
    jsonResponse(['claim' => $claim]);
}

function handleClaimsCancel(PDO $pdo, int $claimId): void {
    apiRequireAuth();
    apiRequireCsrf();
    $current = apiCurrentUser();

    $claim = claimFetchRawRequest($pdo, $claimId);
    if (!$claim) {
        errorResponse('Claim not found', 404);
    }
    if ((int)$claim['claimant_user_id'] !== (int)$current['id']) {
        errorResponse('Forbidden', 403);
    }
    if ($claim['status'] !== 'pending') {
        errorResponse('Only pending claims can be canceled', 409);
    }

    $stmt = $pdo->prepare("
        UPDATE claim_requests
        SET status = 'canceled', updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$claimId]);

    claimLogAction($pdo, $claimId, (int)$current['id'], 'claim_canceled', 'Claim canceled by claimant');
    jsonResponse(['success' => true]);
}

function handleAdminClaimsList(PDO $pdo): void {
    apiRequireAdmin();

    $status = trim((string)($_GET['status'] ?? 'pending'));
    $q = trim((string)($_GET['q'] ?? ''));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

    $where = [];
    $params = [];

    if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'canceled'], true)) {
        $where[] = "c.status = :status";
        $params[':status'] = $status;
    }
    if ($q !== '') {
        $where[] = "(c.representative_name LIKE :q OR c.contact_email LIKE :q2 OR p.data LIKE :q3)";
        $params[':q'] = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM claim_requests c
        LEFT JOIN profiles p ON p.user_id = c.entity_user_id
        {$whereClause}
    ");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.*,
               eu.email AS entity_user_email,
               cu.email AS claimant_email,
               ru.email AS reviewer_email,
               p.data AS entity_profile_data
        FROM claim_requests c
        JOIN users eu ON eu.id = c.entity_user_id
        JOIN users cu ON cu.id = c.claimant_user_id
        LEFT JOIN users ru ON ru.id = c.reviewed_by_user_id
        LEFT JOIN profiles p ON p.user_id = c.entity_user_id
        {$whereClause}
        ORDER BY
            CASE c.status
                WHEN 'pending' THEN 0
                WHEN 'rejected' THEN 1
                WHEN 'approved' THEN 2
                ELSE 3
            END,
            c.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $claims = array_map('claimHydrateRequestRow', $stmt->fetchAll());
    jsonResponse(['claims' => $claims, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
}

function handleAdminClaimGet(PDO $pdo, int $claimId): void {
    apiRequireAdmin();
    $claim = claimFetchRequest($pdo, $claimId, null, true);
    if (!$claim) {
        errorResponse('Claim not found', 404);
    }
    jsonResponse(['claim' => $claim]);
}

function handleAdminClaimApprove(PDO $pdo, int $claimId): void {
    apiRequireAdmin();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = apiReadJsonBody();
    $reviewNotes = trim((string)($body['review_notes'] ?? ''));

    claimApproveRequest($pdo, $claimId, (int)$current['id'], $reviewNotes, false);
    jsonResponse(['success' => true]);
}

function handleAdminClaimReject(PDO $pdo, int $claimId): void {
    apiRequireAdmin();
    apiRequireCsrf();
    $current = apiCurrentUser();
    $body = apiReadJsonBody();
    $reviewNotes = trim((string)($body['review_notes'] ?? ''));
    if ($reviewNotes === '') {
        errorResponse('Rejection reason is required');
    }

    $claim = claimFetchRawRequest($pdo, $claimId);
    if (!$claim) {
        errorResponse('Claim not found', 404);
    }
    if ($claim['status'] !== 'pending') {
        errorResponse('Only pending claims can be rejected', 409);
    }

    $stmt = $pdo->prepare("
        UPDATE claim_requests
        SET status = 'rejected',
            review_notes = ?,
            reviewed_by_user_id = ?,
            reviewed_at = CURRENT_TIMESTAMP,
            rejected_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$reviewNotes, (int)$current['id'], $claimId]);

    claimLogAction($pdo, $claimId, (int)$current['id'], 'claim_rejected', $reviewNotes);

    $notify = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, data)
        VALUES (?, 'claim_rejected', 'Claim Rejected', ?, ?)
    ");
    $notify->execute([
        (int)$claim['claimant_user_id'],
        'Your claim request was reviewed and rejected. See notes for details.',
        json_encode(['claim_request_id' => (int)$claimId], JSON_UNESCAPED_UNICODE),
    ]);

    jsonResponse(['success' => true]);
}

function claimApproveRequest(PDO $pdo, int $claimId, ?int $reviewerId, string $reviewNotes, bool $isAuto): void {
    $claim = claimFetchRawRequest($pdo, $claimId);
    if (!$claim) {
        errorResponse('Claim not found', 404);
    }
    if ($claim['status'] !== 'pending') {
        errorResponse('Only pending claims can be approved', 409);
    }

    $entityType = (string)$claim['entity_type'];
    $entityUserId = (int)$claim['entity_user_id'];
    $claimantUserId = (int)$claim['claimant_user_id'];

    $entity = claimLoadEntityProfile($pdo, $entityType, $entityUserId, false);
    if (!$entity) {
        errorResponse('Claimed profile no longer exists', 404);
    }
    if (!$entity['is_generic']) {
        errorResponse('Only seeded profiles can be transferred through claim flow', 409);
    }

    $userStmt = $pdo->prepare("SELECT id, email, type FROM users WHERE id = ?");
    $userStmt->execute([$claimantUserId]);
    $claimant = $userStmt->fetch();
    if (!$claimant) {
        errorResponse('Claimant account not found', 404);
    }
    if ($claimant['type'] !== $entityType) {
        errorResponse('Claimant account type no longer matches this profile', 409);
    }

    $claimantProfile = claimLoadEntityProfile($pdo, $entityType, $claimantUserId, false);
    $claimantData = $claimantProfile['data'] ?? [];
    $mergedData = claimMergeProfileData($entity['data'], $claimantData);

    $pdo->beginTransaction();
    try {
        if ($claimantProfile) {
            $updateClaimantProfile = $pdo->prepare("
                UPDATE profiles
                SET data = ?,
                    type = ?,
                    is_generic = 0,
                    is_claimed = 1,
                    is_archived = 0,
                    archived_at = NULL,
                    archived_reason = '',
                    claimed_by_user_id = ?,
                    claimed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
            ");
            $updateClaimantProfile->execute([
                json_encode($mergedData, JSON_UNESCAPED_UNICODE),
                $entityType,
                $claimantUserId,
                $claimantUserId,
            ]);
        } else {
            $insertClaimantProfile = $pdo->prepare("
                INSERT INTO profiles (
                    user_id, type, data, is_generic, is_claimed, is_archived, claimed_by_user_id, claimed_at
                ) VALUES (?, ?, ?, 0, 1, 0, ?, CURRENT_TIMESTAMP)
            ");
            $insertClaimantProfile->execute([
                $claimantUserId,
                $entityType,
                json_encode($mergedData, JSON_UNESCAPED_UNICODE),
                $claimantUserId,
            ]);
        }

        $archiveEntityProfile = $pdo->prepare("
            UPDATE profiles
            SET is_archived = 1,
                archived_at = CURRENT_TIMESTAMP,
                archived_reason = 'claimed_transfer',
                is_claimed = 1,
                claimed_by_user_id = ?,
                claimed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        $archiveEntityProfile->execute([$claimantUserId, $entityUserId]);

        if (panicDbIsMysql($pdo)) {
            $linkEntity = $pdo->prepare("
                INSERT INTO entity_links (
                    entity_type, source_user_id, target_user_id, link_type, notes, created_by_user_id
                ) VALUES (?, ?, ?, 'claim_transfer', ?, ?)
                ON DUPLICATE KEY UPDATE
                    target_user_id = VALUES(target_user_id),
                    notes = VALUES(notes),
                    created_by_user_id = VALUES(created_by_user_id),
                    created_at = CURRENT_TIMESTAMP
            ");
        } else {
            $linkEntity = $pdo->prepare("
                INSERT INTO entity_links (
                    entity_type, source_user_id, target_user_id, link_type, notes, created_by_user_id
                ) VALUES (?, ?, ?, 'claim_transfer', ?, ?)
                ON CONFLICT(entity_type, source_user_id, link_type)
                DO UPDATE SET
                    target_user_id = excluded.target_user_id,
                    notes = excluded.notes,
                    created_by_user_id = excluded.created_by_user_id,
                    created_at = CURRENT_TIMESTAMP
            ");
        }
        $linkEntity->execute([
            $entityType,
            $entityUserId,
            $claimantUserId,
            $isAuto ? 'Auto-approved claim transfer' : 'Admin-approved claim transfer',
            $reviewerId,
        ]);

        claimTransferReferences($pdo, $entityType, $entityUserId, $claimantUserId);

        $updateClaim = $pdo->prepare("
            UPDATE claim_requests
            SET status = 'approved',
                review_notes = ?,
                reviewed_by_user_id = ?,
                reviewed_at = CURRENT_TIMESTAMP,
                approved_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $updateClaim->execute([
            $reviewNotes,
            $reviewerId,
            $claimId,
        ]);

        claimLogAction(
            $pdo,
            $claimId,
            $reviewerId,
            $isAuto ? 'claim_auto_approved' : 'claim_approved',
            $reviewNotes !== '' ? $reviewNotes : ($isAuto ? 'Auto-approved claim' : 'Claim approved'),
            [
                'entity_user_id' => $entityUserId,
                'claimant_user_id' => $claimantUserId,
                'entity_type' => $entityType,
            ]
        );

        $notify = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data)
            VALUES (?, 'claim_approved', 'Claim Approved', ?, ?)
        ");
        $notify->execute([
            $claimantUserId,
            'Your claim was approved. The seeded profile is now linked to your account.',
            json_encode(['claim_request_id' => $claimId, 'entity_type' => $entityType], JSON_UNESCAPED_UNICODE),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        errorResponse('Failed to approve claim', 500);
    }
}

function claimTransferReferences(PDO $pdo, string $entityType, int $fromUserId, int $toUserId): void {
    if ($entityType === 'venue') {
        $pdo->prepare("UPDATE opportunities SET venue_user_id = ? WHERE venue_user_id = ?")->execute([$toUserId, $fromUserId]);
        $pdo->prepare("UPDATE booking_requests SET venue_user_id = ? WHERE venue_user_id = ?")->execute([$toUserId, $fromUserId]);
        $pdo->prepare("UPDATE bookings SET venue_user_id = ? WHERE venue_user_id = ?")->execute([$toUserId, $fromUserId]);
        $pdo->prepare("UPDATE events SET venue_id = ? WHERE venue_id = ?")->execute([$toUserId, $fromUserId]);
    } else {
        $pdo->prepare("UPDATE booking_requests SET band_user_id = ? WHERE band_user_id = ?")->execute([$toUserId, $fromUserId]);
        $pdo->prepare("UPDATE bookings SET band_user_id = ? WHERE band_user_id = ?")->execute([$toUserId, $fromUserId]);
        $pdo->prepare("UPDATE performer_scores SET band_profile_id = ? WHERE band_profile_id = ?")->execute([$toUserId, $fromUserId]);
        $pdo->prepare("UPDATE show_reports SET band_profile_id = ? WHERE band_profile_id = ?")->execute([$toUserId, $fromUserId]);
    }

    $pdo->prepare("UPDATE notifications SET user_id = ? WHERE user_id = ?")->execute([$toUserId, $fromUserId]);
    $pdo->prepare("UPDATE orders SET user_id = ? WHERE user_id = ?")->execute([$toUserId, $fromUserId]);
    $pdo->prepare("UPDATE tickets SET user_id = ? WHERE user_id = ?")->execute([$toUserId, $fromUserId]);
}

function claimFindDuplicateCandidates(PDO $pdo, string $entityType, int $entityUserId, array $entityFp): array {
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, p.data
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE u.type = ?
          AND p.is_archived = 0
          AND u.id != ?
    ");
    $stmt->execute([$entityType, $entityUserId]);
    $rows = $stmt->fetchAll();

    $candidates = [];
    foreach ($rows as $row) {
        $profileData = json_decode((string)($row['data'] ?? '{}'), true) ?: [];
        $candidateFp = claimBuildFingerprint($profileData, $entityType, (string)$row['email']);
        $comparison = claimCompareFingerprints($entityFp, $candidateFp, $entityType);
        if ($comparison['score'] < 45) {
            continue;
        }

        $candidates[] = [
            'user_id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'name' => (string)($profileData['name'] ?? ''),
            'score' => $comparison['score'],
            'reasons' => $comparison['reasons'],
            'merge_note' => 'Potential duplicate. Review and merge profile details before approving if this account is already managed.',
        ];
    }

    usort($candidates, static function(array $a, array $b): int {
        return (int)$b['score'] <=> (int)$a['score'];
    });

    $top = array_slice($candidates, 0, 5);
    $maxScore = $top ? (int)$top[0]['score'] : 0;
    $notes = $top
        ? 'Potential duplicates detected: ' . implode('; ', array_map(static function(array $c): string {
            $reasons = implode(', ', $c['reasons']);
            return '#' . $c['user_id'] . ' (' . ($c['name'] !== '' ? $c['name'] : $c['email']) . ') via ' . $reasons;
        }, $top))
        : '';

    return [
        'max_score' => $maxScore,
        'notes' => $notes,
        'candidates' => $top,
    ];
}

function claimBuildFingerprint(array $data, string $entityType, string $userEmail): array {
    $name = claimNormalizeText((string)($data['name'] ?? ''));
    $nameCompact = str_replace(' ', '', $name);

    if ($entityType === 'venue') {
        $cityRaw = (string)($data['city'] ?? '');
        if ($cityRaw === '') {
            $cityRaw = claimExtractCityFromAddress((string)($data['address'] ?? ''));
        }
    } else {
        $cityRaw = (string)($data['location'] ?? '');
    }
    $city = claimNormalizeText($cityRaw);

    $emails = [];
    foreach ([(string)($data['contact_email'] ?? ''), $userEmail] as $email) {
        $normalized = claimNormalizeEmail($email);
        if ($normalized !== '') {
            $emails[$normalized] = true;
        }
    }

    $urlFields = ['website'];
    $socialFields = $entityType === 'band'
        ? ['facebook', 'instagram', 'spotify', 'youtube']
        : ['facebook', 'instagram'];

    $urls = [];
    foreach ($urlFields as $field) {
        $v = claimNormalizeUrl((string)($data[$field] ?? ''));
        if ($v !== '') {
            $urls[$v] = true;
        }
    }

    $socials = [];
    foreach ($socialFields as $field) {
        $v = claimNormalizeUrl((string)($data[$field] ?? ''));
        if ($v !== '') {
            $socials[$v] = true;
        }
    }

    return [
        'name' => $name,
        'name_compact' => $nameCompact,
        'city' => $city,
        'emails' => array_keys($emails),
        'urls' => array_keys($urls),
        'socials' => array_keys($socials),
    ];
}

function claimCompareFingerprints(array $a, array $b, string $entityType): array {
    $score = 0;
    $reasons = [];

    if ($a['name'] !== '' && $a['name'] === $b['name']) {
        $score += 35;
        $reasons[] = 'name';
    } elseif ($a['name'] !== '' && $b['name'] !== '') {
        similar_text($a['name'], $b['name'], $pct);
        if ($pct >= 88) {
            $score += 18;
            $reasons[] = 'similar_name';
        }
    }

    if ($a['name_compact'] !== '' && $a['name_compact'] === $b['name_compact']) {
        $score += 10;
        if (!in_array('normalized_name', $reasons, true)) {
            $reasons[] = 'normalized_name';
        }
    }

    if ($a['city'] !== '' && $a['city'] === $b['city']) {
        $score += 10;
        $reasons[] = 'city';
    }

    $emailMatches = array_values(array_intersect($a['emails'], $b['emails']));
    if ($emailMatches) {
        $score += 30;
        $reasons[] = 'email';
    }

    $urlMatches = array_values(array_intersect($a['urls'], $b['urls']));
    if ($urlMatches) {
        $score += 25;
        $reasons[] = 'website';
    }

    $socialMatches = array_values(array_intersect($a['socials'], $b['socials']));
    if ($socialMatches) {
        $score += 20;
        $reasons[] = 'social';
    }

    if ($entityType === 'venue' && $a['city'] !== '' && $b['city'] !== '' && $a['city'] === $b['city'] && $a['name'] !== '' && $a['name'] === $b['name']) {
        $score += 8;
    }

    return [
        'score' => min(100, $score),
        'reasons' => array_values(array_unique($reasons)),
    ];
}

function claimNormalizeText(string $value): string {
    $v = strtolower(trim($value));
    if ($v === '') {
        return '';
    }
    $v = preg_replace('/[^a-z0-9]+/', ' ', $v);
    return trim((string)$v);
}

function claimNormalizeEmail(string $value): string {
    $v = strtolower(trim($value));
    if ($v === '' || !filter_var($v, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    return $v;
}

function claimNormalizeUrl(string $value): string {
    $v = trim($value);
    if ($v === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $v)) {
        $v = 'https://' . $v;
    }
    $parts = parse_url($v);
    if (!$parts || empty($parts['host'])) {
        return '';
    }
    $host = strtolower((string)$parts['host']);
    $host = preg_replace('/^www\./', '', $host);
    $path = strtolower(trim((string)($parts['path'] ?? '')));
    $path = rtrim($path, '/');
    return $host . $path;
}

function claimExtractCityFromAddress(string $address): string {
    $parts = array_map('trim', explode(',', $address));
    if (count($parts) >= 2) {
        return $parts[1];
    }
    return $address;
}

function claimMergeProfileData(array $seedData, array $claimantData): array {
    $merged = $seedData;
    foreach ($claimantData as $key => $value) {
        if (!array_key_exists($key, $merged) || claimValueIsEmpty($merged[$key])) {
            $merged[$key] = $value;
        }
    }
    return $merged;
}

function claimValueIsEmpty($value): bool {
    if (is_array($value)) {
        return count($value) === 0;
    }
    if (is_bool($value)) {
        return false;
    }
    if ($value === null) {
        return true;
    }
    if (is_numeric($value)) {
        return (float)$value === 0.0;
    }
    return trim((string)$value) === '';
}

function claimCanAutoApprove(array $entity, string $claimantEmail, string $submittedEmail, int $dedupeScore): bool {
    if ($dedupeScore >= 65) {
        return false;
    }
    $entityContact = strtolower(trim((string)($entity['data']['contact_email'] ?? '')));
    $claimantEmail = strtolower(trim($claimantEmail));
    $submittedEmail = strtolower(trim($submittedEmail));
    if ($entityContact === '' || !filter_var($entityContact, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return $entityContact === $claimantEmail && $entityContact === $submittedEmail;
}

function claimLoadEntityProfile(PDO $pdo, string $entityType, int $userId, bool $includeArchived): ?array {
    $sql = "
        SELECT u.id AS user_id,
               u.email AS user_email,
               u.type AS user_type,
               p.id AS profile_id,
               p.type AS profile_type,
               p.data,
               p.is_generic,
               p.is_claimed,
               COALESCE(p.is_archived, 0) AS is_archived
        FROM users u
        JOIN profiles p ON p.user_id = u.id
        WHERE u.id = :uid
          AND u.type = :type
    ";
    if (!$includeArchived) {
        $sql .= " AND COALESCE(p.is_archived, 0) = 0";
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId, ':type' => $entityType]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'user_id' => (int)$row['user_id'],
        'user_email' => (string)$row['user_email'],
        'user_type' => (string)$row['user_type'],
        'profile_id' => (int)$row['profile_id'],
        'profile_type' => (string)$row['profile_type'],
        'data' => json_decode((string)$row['data'], true) ?: [],
        'is_generic' => (bool)$row['is_generic'],
        'is_claimed' => (bool)$row['is_claimed'],
        'is_archived' => (bool)$row['is_archived'],
    ];
}

function claimFetchRawRequest(PDO $pdo, int $claimId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM claim_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$claimId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function claimFetchRequest(PDO $pdo, int $claimId, ?int $claimantUserId, bool $isAdmin): ?array {
    $where = ["c.id = :id"];
    $params = [':id' => $claimId];
    if (!$isAdmin) {
        $where[] = "c.claimant_user_id = :uid";
        $params[':uid'] = (int)$claimantUserId;
    }
    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT c.*,
               eu.email AS entity_user_email,
               cu.email AS claimant_email,
               ru.email AS reviewer_email,
               p.data AS entity_profile_data
        FROM claim_requests c
        JOIN users eu ON eu.id = c.entity_user_id
        JOIN users cu ON cu.id = c.claimant_user_id
        LEFT JOIN users ru ON ru.id = c.reviewed_by_user_id
        LEFT JOIN profiles p ON p.user_id = c.entity_user_id
        WHERE {$whereClause}
        LIMIT 1
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return claimHydrateRequestRow($row);
}

function claimHydrateRequestRow(array $row): array {
    $entityProfile = json_decode((string)($row['entity_profile_data'] ?? '{}'), true) ?: [];
    $duplicateCandidates = json_decode((string)($row['duplicate_candidates'] ?? '[]'), true);
    if (!is_array($duplicateCandidates)) {
        $duplicateCandidates = [];
    }

    return [
        'id' => (int)$row['id'],
        'entity_type' => (string)$row['entity_type'],
        'entity_user_id' => (int)$row['entity_user_id'],
        'entity_name' => (string)($entityProfile['name'] ?? ''),
        'entity_contact_email' => (string)($entityProfile['contact_email'] ?? ''),
        'entity_user_email' => (string)($row['entity_user_email'] ?? ''),
        'claimant_user_id' => (int)$row['claimant_user_id'],
        'claimant_email' => (string)($row['claimant_email'] ?? ''),
        'status' => (string)$row['status'],
        'representative_name' => (string)($row['representative_name'] ?? ''),
        'representative_role' => (string)($row['representative_role'] ?? ''),
        'contact_email' => (string)($row['contact_email'] ?? ''),
        'contact_phone' => (string)($row['contact_phone'] ?? ''),
        'website' => (string)($row['website'] ?? ''),
        'evidence_links' => (string)($row['evidence_links'] ?? ''),
        'supporting_info' => (string)($row['supporting_info'] ?? ''),
        'dedupe_score' => (int)($row['dedupe_score'] ?? 0),
        'dedupe_notes' => (string)($row['dedupe_notes'] ?? ''),
        'duplicate_candidates' => $duplicateCandidates,
        'review_notes' => (string)($row['review_notes'] ?? ''),
        'reviewed_by_user_id' => isset($row['reviewed_by_user_id']) ? (int)$row['reviewed_by_user_id'] : null,
        'reviewer_email' => (string)($row['reviewer_email'] ?? ''),
        'reviewed_at' => $row['reviewed_at'] ?? null,
        'approved_at' => $row['approved_at'] ?? null,
        'rejected_at' => $row['rejected_at'] ?? null,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function claimLogAction(
    PDO $pdo,
    int $claimRequestId,
    ?int $actorUserId,
    string $action,
    string $notes = '',
    array $metadata = []
): void {
    $stmt = $pdo->prepare("
        INSERT INTO claim_action_logs (claim_request_id, actor_user_id, action, notes, metadata)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $claimRequestId,
        $actorUserId,
        $action,
        $notes,
        json_encode($metadata, JSON_UNESCAPED_UNICODE),
    ]);
}
