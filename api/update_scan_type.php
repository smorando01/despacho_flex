<?php
// api/update_scan_type.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_api_auth();
require_csrf_token();

require __DIR__ . '/config.php';
date_default_timezone_set('America/Montevideo');

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
    $nuevoTipo = isset($input['nuevo_tipo']) && is_string($input['nuevo_tipo'])
        ? trim($input['nuevo_tipo'])
        : '';

    $mapTipos = [
        'flex'                => 'FLEX',
        'etiqueta districad'  => 'ETIQUETA',
    ];

    $nuevoTipoKey = strtolower($nuevoTipo);
    if ($scanId <= 0 || !isset($mapTipos[$nuevoTipoKey])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Datos inválidos para cambiar el tipo de la lectura',
        ]);
        exit;
    }

    $pdo = get_pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT scans.session_id, scans.raw_input, scans.codigo, scans.estado, scans.tipo, scans.scanned_at, sessions.closed_at
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
            'message' => 'La sesión ya está cerrada. No se pueden editar lecturas.',
        ]);
        exit;
    }

    $sessionId = (int)$scanRow['session_id'];
    $rawInput  = isset($scanRow['raw_input']) ? (string)$scanRow['raw_input'] : '';
    $nuevoTipoDb = $mapTipos[$nuevoTipoKey]; // FLEX / ETIQUETA

    // Reutilizamos el input crudo si existe para recalcular código y estado
    $valorBase = $rawInput !== '' ? $rawInput : (string)$scanRow['codigo'];

    $extraerID = function (string $valor) {
        if (preg_match('/\[id\[[ñÑ]\[(\d{8,15})/i', $valor, $m)) {
            return $m[1];
        }
        if (preg_match('/id[¨\[]+[ñÑ][¨\[]+(\d{8,15})/i', $valor, $m)) {
            return $m[1];
        }
        $normalizado = strtolower(preg_replace('/[^a-z0-9]/i', ',', $valor));
        if (preg_match('/id,+(\d{8,15})/', $normalizado, $m)) {
            return $m[1];
        }
        return null;
    };

    $codigoFinal = $valorBase;
    $estadoDb    = 'OK';

    if ($nuevoTipoDb === 'FLEX') {
        $extraido = $extraerID($valorBase);
        if ($extraido === null) {
            $codigoFinal = $valorBase;
            $estadoDb    = 'INVALIDO';
        } else {
            $codigoFinal = $extraido;
            if (!preg_match('/^\d{8,}$/', $codigoFinal)) {
                $estadoDb = 'INVALIDO';
            }
        }
    } else { // ETIQUETA
        $codigoFinal = $valorBase;
        if (!preg_match('/^\d{8,}$/', $codigoFinal)) {
            $estadoDb = 'INVALIDO';
        }
    }

    // Verificar duplicados con el nuevo código dentro de la sesión
    $stmt = $pdo->prepare("
        SELECT id
        FROM scans
        WHERE session_id = ? AND codigo = ? AND id <> ?
        LIMIT 1
    ");
    $stmt->execute([$sessionId, $codigoFinal, $scanId]);
    $duplicate = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($duplicate) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Ya existe otra lectura con ese código en este despacho. No se actualizó el tipo.',
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE scans
        SET tipo = ?, estado = ?, codigo = ?
        WHERE id = ?
    ");
    $stmt->execute([$nuevoTipoDb, $estadoDb, $codigoFinal, $scanId]);

    $pdo->commit();

    // Recalcular métricas
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN tipo = 'FLEX'     AND estado = 'OK' THEN 1 ELSE 0 END) AS flex_ok,
            SUM(CASE WHEN tipo = 'ETIQUETA' AND estado = 'OK' THEN 1 ELSE 0 END) AS etiqueta_ok,
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
        $tipoUi = 'Inválido';
        if ($lastRow['tipo'] === 'FLEX') {
            $tipoUi = 'Flex';
        } elseif ($lastRow['tipo'] === 'ETIQUETA') {
            $tipoUi = 'Etiqueta Districad';
        }

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

    $ts = strtotime($scanRow['scanned_at']);
    if ($ts === false) {
        $ts = time();
    }

    $registroUi = [
        'id'     => $scanId,
        'codigo' => $codigoFinal,
        'tipo'   => $nuevoTipoDb === 'FLEX' ? 'Flex' : 'Etiqueta Districad',
        'estado' => $estadoDb === 'INVALIDO' ? 'CÓDIGO INVÁLIDO' : 'OK',
        'ts'     => $ts * 1000,
    ];

    echo json_encode([
        'success'  => true,
        'registro' => $registroUi,
        'metrics'  => [
            'total'       => (int)$metrics['total'],
            'flex_ok'     => (int)$metrics['flex_ok'],
            'etiqueta_ok' => (int)$metrics['etiqueta_ok'],
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
        'message' => 'Error al actualizar el tipo de la lectura.',
        'error'   => $e->getMessage(),
    ]);
}
