<?php
// api/cancel_session.php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php'; // Primero config
require_once __DIR__ . '/auth.php';
require_api_auth();
require_csrf_token();
date_default_timezone_set('America/Montevideo');

try {
    $pdo = get_pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->query("
        SELECT id
        FROM sessions
        WHERE closed_at IS NULL
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'No hay ningún despacho abierto para cancelar.'
        ]);
        exit;
    }

    $sessionId = (int)$session['id'];

    $stmt = $pdo->prepare("DELETE FROM scans WHERE session_id = ?");
    $stmt->execute([$sessionId]);

    $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
    $stmt->execute([$sessionId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Despacho cancelado y eliminado.'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor al cancelar el despacho.',
        'error'   => $e->getMessage(),
    ]);
}
