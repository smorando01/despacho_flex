<?php
// api/delete_scan.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require __DIR__ . '/config.php';
require_api_auth();
require_csrf_token();
date_default_timezone_set('America/Montevideo');

function tipoUiDesdeDb(?string $tipo): string
{
    switch ($tipo) {
        case 'FLEX':
            return 'Flex';
        case 'ETIQUETA':
            return 'Etiqueta Districad';
        case 'COLECTA':
            return 'Colecta';
        default:
            return 'Inválido';
    }
}

$pdo = null;

try {
    $body  = file_get_contents('php://input');
    $input = json_decode($body, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'JSON inválido',
        ]);
        exit;
    }

    $scanId = isset($input['scan_id']) ? (int)$input['scan_id'] : 0;

    if ($scanId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Identificador de lectura inválido',
        ]);
        exit;
    }

    $pdo = get_pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT scans.session_id, sessions.closed_at
        FROM scans
        INNER JOIN sessions ON sessions.id = scans.session_id
        WHERE scans.id = ?
        FOR UPDATE
    ");
    $stmt->execute([$scanId]);
    $scanRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scanRow) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Lectura no encontrada',
        ]);
        exit;
    }

    if ($scanRow['closed_at'] !== null) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La sesión ya está cerrada. No se pueden borrar lecturas.',
        ]);
        exit;
    }

    $sessionId = (int)$scanRow['session_id'];

    $stmt = $pdo->prepare('DELETE FROM scans WHERE id = ?');
    $stmt->execute([$scanId]);

    $pdo->commit();

    // Recalcular métricas
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN tipo = 'FLEX'     AND estado = 'OK' THEN 1 ELSE 0 END) AS flex_ok,
            SUM(CASE WHEN tipo = 'ETIQUETA' AND estado = 'OK' THEN 1 ELSE 0 END) AS etiqueta_ok,
            SUM(CASE WHEN tipo = 'COLECTA'  AND estado = 'OK' THEN 1 ELSE 0 END) AS colecta_ok,
            SUM(CASE WHEN estado = 'INVALIDO' THEN 1 ELSE 0 END)                AS invalidos
        FROM scans
        WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$metrics) {
        $metrics = [
            'total'       => 0,
            'flex_ok'     => 0,
            'etiqueta_ok' => 0,
            'colecta_ok'  => 0,
            'invalidos'   => 0,
        ];
    }

    // Última lectura
    $stmt = $pdo->prepare("
        SELECT id, codigo, tipo, estado, scanned_at
        FROM scans
        WHERE session_id = ?
        ORDER BY scanned_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $lastRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $lastScan = null;

    if ($lastRow) {
        $tipoUi = tipoUiDesdeDb($lastRow['tipo']);

        if ($lastRow['estado'] === 'INVALIDO') {
            $estadoUi = 'CÓDIGO INVÁLIDO';
        } else {
            $estadoUi = $lastRow['estado'];
        }

        $timestamp = strtotime($lastRow['scanned_at']);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $lastScan = [
            'id'     => (int)$lastRow['id'],
            'codigo' => $lastRow['codigo'],
            'tipo'   => $tipoUi,
            'estado' => $estadoUi,
            'ts'     => $timestamp * 1000,
        ];
    }

    echo json_encode([
        'success' => true,
        'metrics' => [
            'total'       => (int)$metrics['total'],
            'flex_ok'     => (int)$metrics['flex_ok'],
            'etiqueta_ok' => (int)$metrics['etiqueta_ok'],
            'colecta_ok'  => (int)$metrics['colecta_ok'],
            'invalidos'   => (int)$metrics['invalidos'],
        ],
        'last_scan' => $lastScan,
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar la lectura.',
        'error'   => $e->getMessage(),
    ]);
}
