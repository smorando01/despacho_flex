<?php
// api/scan.php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php'; // Primero config
require_once __DIR__ . '/auth.php';
require_api_auth();
require_csrf_token();
date_default_timezone_set('America/Montevideo');

/**
 * Detecta códigos de Colecta.
 * Regla: exactamente 11 dígitos o cualquier QR que contenga id[Ñ[...]
 */
function detectar_colecta(string $raw): array
{
    $raw = trim($raw);

    $resultado = [
        'es_colecta'   => false,
        'codigo'       => null,
        'contiene_id'  => false,
    ];

    if ($raw === '') {
        return $resultado;
    }

    if (preg_match('/^\d{11}$/', $raw) === 1) {
        $resultado['es_colecta'] = true;
        $resultado['codigo']     = $raw;
        return $resultado;
    }

    $tieneId = preg_match('/id\[[ñÑ]\[/u', $raw) === 1;
    $resultado['contiene_id'] = $tieneId;

    if ($tieneId) {
        $codigo = null;
        if (preg_match('/id\[[ñÑ]\[(\d{11})/u', $raw, $m) === 1) {
            $codigo = $m[1];
        } elseif (preg_match('/\d{11}/', $raw, $m) === 1) {
            $codigo = $m[0];
        }

        $resultado['es_colecta'] = true;
        $resultado['codigo']     = $codigo ?? $raw;
    }

    return $resultado;
}

// Intenta extraer IDs de Flex desde un QR con múltiples formatos
function extraer_id_flex_qr(string $raw): ?string
{
    if (preg_match('/\[id\[[ñÑ]\[(\d{8,20})/i', $raw, $m)) {
        return $m[1];
    }

    if (preg_match('/id[¨\[]+[ñÑ][¨\[]+(\d{8,20})/i', $raw, $m)) {
        return $m[1];
    }

    $normalizado = strtolower(preg_replace('/[^a-z0-9]/i', ',', $raw));
    if (preg_match('/id,+(\d{8,20})/', $normalizado, $m)) {
        return $m[1];
    }

    if (preg_match('/\bid=(\d{8,20})/i', $raw, $m)) {
        return $m[1];
    }

    return null;
}

// Analiza Flex (QR o manual) siguiendo las reglas de 12+ dígitos o QR sin id[Ñ[
function analizar_flex(string $raw): array
{
    $raw = trim($raw);
    $resultado = [
        'valido'      => false,
        'parece_flex' => false,
        'codigo'      => $raw,
        'modo'        => null,
    ];

    if ($raw === '' || preg_match('/id\[[ñÑ]\[/u', $raw) === 1) {
        return $resultado;
    }

    // Manual: numérico 12+ dígitos
    if (preg_match('/^\d{12,}$/', $raw) === 1) {
        $resultado['valido']      = true;
        $resultado['parece_flex'] = true;
        $resultado['codigo']      = $raw;
        $resultado['modo']        = 'MANUAL';
        return $resultado;
    }

    // QR: debe tener pistas de hash/sender/... y NO id[Ñ[
    $esQR = preg_match('/ñ|hash|sender_id|sender\?id|security\?digit|qr|https?:\/\//iu', $raw) === 1;
    if ($esQR) {
        $resultado['parece_flex'] = true;
        $resultado['modo']        = 'QR';

        $idExtraido = extraer_id_flex_qr($raw);
        if ($idExtraido !== null && preg_match('/^\d{8,}$/', $idExtraido) === 1) {
            $resultado['valido'] = true;
            $resultado['codigo'] = $idExtraido;
        } else {
            $resultado['codigo'] = $idExtraido ?? $raw;
        }
    }

    return $resultado;
}

// Districad: numérico 8 a 10 dígitos
function es_districad(string $raw): bool
{
    return preg_match('/^\d{8,10}$/', trim($raw)) === 1;
}

try {
    $body  = file_get_contents('php://input');
    $input = json_decode($body, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'JSON inválido'
        ]);
        exit;
    }

    $raw = isset($input['raw']) && is_string($input['raw'])
        ? trim($input['raw'])
        : '';

    if ($raw === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Sin datos de código'
        ]);
        exit;
    }

    $pdo = get_pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->query("
        SELECT id, numero_en_dia, courier
        FROM sessions
        WHERE closed_at IS NULL
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'No hay despacho iniciado'
        ]);
        exit;
    }

    $session_id    = (int)$session['id'];
    $numero_en_dia = (int)$session['numero_en_dia'];
    $courier       = strtoupper((string)$session['courier']);

    $tipo_db      = $courier;
    $codigo_final = $raw;
    $estado_db    = 'INVALIDO';

    $colectaAnalisis = detectar_colecta($raw);
    $flexAnalisis    = analizar_flex($raw);
    $esDistricad     = es_districad($raw);

    if ($courier === 'COLECTA') {
        if ($flexAnalisis['parece_flex'] || $esDistricad) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Sesión exclusiva de Colecta'
            ]);
            exit;
        }

        if ($colectaAnalisis['es_colecta']) {
            $codigo_final = $colectaAnalisis['codigo'] ?? $raw;
            $estado_db    = 'OK';
            $tipo_db      = 'COLECTA';
        }
    } elseif ($courier === 'FLEX') {
        if ($colectaAnalisis['es_colecta']) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Paquete de Colecta en sesión Flex'
            ]);
            exit;
        }

        if ($flexAnalisis['valido']) {
            $codigo_final = $flexAnalisis['codigo'];
            $estado_db    = 'OK';
            $tipo_db      = 'FLEX';
        } elseif ($esDistricad) {
            $codigo_final = $raw;
            $estado_db    = 'OK';
            $tipo_db      = 'ETIQUETA';
        } else {
            $codigo_final = $flexAnalisis['codigo'] ?? $raw;
            $estado_db    = 'INVALIDO';
            $tipo_db      = 'FLEX';
        }
    } else {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Courier no soportado'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM scans
        WHERE session_id = ? AND codigo = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$session_id, $codigo_final]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $estado_respuesta = null;
    $scanned_at       = date('Y-m-d H:i:s');
    $inserted_id      = null;

    if ($existing) {
        $estado_respuesta = 'DUPLICADO';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO scans (session_id, codigo, tipo, estado, raw_input, scanned_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $session_id,
            $codigo_final,
            $tipo_db,
            $estado_db,
            $raw,
            $scanned_at,
        ]);
        $inserted_id      = (int)$pdo->lastInsertId();
        $estado_respuesta = $estado_db;
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN estado = 'OK' THEN 1 ELSE 0 END) AS ok,
            SUM(CASE WHEN estado = 'INVALIDO' THEN 1 ELSE 0 END) AS invalidos
        FROM scans
        WHERE session_id = ?
    ");
    $stmt->execute([$session_id]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$metrics) {
        $metrics = [
            'total'     => 0,
            'ok'        => 0,
            'invalidos' => 0,
        ];
    }

    $pdo->commit();

    $tipo_ui = 'Flex';
    if ($tipo_db === 'COLECTA') {
        $tipo_ui = 'Colecta';
    } elseif ($tipo_db === 'ETIQUETA') {
        $tipo_ui = 'Etiqueta';
    }

    if ($estado_respuesta === 'OK') {
        $estado_ui = 'OK';
    } elseif ($estado_respuesta === 'INVALIDO') {
        $estado_ui = 'CÓDIGO INVÁLIDO';
    } elseif ($estado_respuesta === 'DUPLICADO') {
        $estado_ui = 'DUPLICADO';
    } else {
        $estado_ui = $estado_respuesta;
    }

    $registro = [
        'id'            => $inserted_id,
        'session_id'    => $session_id,
        'codigo'        => $codigo_final,
        'codigo_raw'    => $raw,
        'tipo_db'       => $tipo_db,
        'estado_db'     => $estado_respuesta,
        'tipo'          => $tipo_ui,
        'estado'        => $estado_ui,
        'scanned_at'    => $scanned_at,
        'numero_en_dia' => $numero_en_dia,
    ];

    echo json_encode([
        'success'  => true,
        'registro' => $registro,
        'metrics'  => [
            'total'     => (int)$metrics['total'],
            'ok'        => (int)$metrics['ok'],
            'invalidos' => (int)$metrics['invalidos'],
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor',
        'error'   => $e->getMessage()
    ]);
}
