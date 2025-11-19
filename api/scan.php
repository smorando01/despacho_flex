<?php
// api/scan.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require __DIR__ . '/config.php';
require_api_auth();
require_csrf_token();
date_default_timezone_set('America/Montevideo');

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

    // ------------------------------------------------------------------
    // 1) Buscar o crear sesión abierta (courier FLEX)
    // ------------------------------------------------------------------
    $hoy     = date('Y-m-d');
    $courier = 'FLEX';

    $session_id    = null;
    $numero_en_dia = null;

    $pdo->beginTransaction();
    try {
        // Sesión abierta para FLEX (sin importar fecha original)
        $stmt = $pdo->prepare("
            SELECT id, numero_en_dia
            FROM sessions
            WHERE courier = ? AND closed_at IS NULL
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$courier]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session) {
            $session_id    = (int)$session['id'];
            $numero_en_dia = (int)$session['numero_en_dia'];
        } else {
            // calcular número de despacho del día actual
            $stmt = $pdo->prepare("
                SELECT COALESCE(MAX(numero_en_dia), 0) AS max_n
                FROM sessions
                WHERE fecha = ? AND courier = ?
                FOR UPDATE
            ");
            $stmt->execute([$hoy, $courier]);
            $row           = $stmt->fetch(PDO::FETCH_ASSOC);
            $numero_en_dia = ((int)$row['max_n']) + 1;

            $stmt = $pdo->prepare("
                INSERT INTO sessions (fecha, started_at, courier, numero_en_dia)
                VALUES (?, NOW(), ?, ?)
            ");
            $stmt->execute([$hoy, $courier, $numero_en_dia]);

            $session_id = (int)$pdo->lastInsertId();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // ------------------------------------------------------------------
    // 2) Determinar tipo, código final y estado base
    // ------------------------------------------------------------------
    $tipo_db      = null;   // FLEX / ETIQUETA / INVALIDO
    $codigo_final = null;   // lo que usamos para duplicados
    $estado_db    = null;   // OK / INVALIDO

    $esQR = preg_match('/ñ|hash|sender\?id|security\?digit|id\[/i', $raw) === 1;

    $extraerID = function (string $valor) {
        // Patrón nuevo
        if (preg_match('/\[id\[[ñÑ]\[(\d{8,15})/i', $valor, $m)) {
            return $m[1];
        }
        // Compatibilidad viejo
        if (preg_match('/id[¨\[]+[ñÑ][¨\[]+(\d{8,15})/i', $valor, $m)) {
            return $m[1];
        }
        // Fallback universal
        $normalizado = strtolower(preg_replace('/[^a-z0-9]/i', ',', $valor));
        if (preg_match('/id,+(\d{8,15})/', $normalizado, $m)) {
            return $m[1];
        }
        return null;
    };

    if ($esQR) {
        $idExtraido = $extraerID($raw);
        if ($idExtraido === null) {
            // QR mal formado
            $tipo_db      = 'INVALIDO';
            $codigo_final = $raw;     // guardamos bruto para duplicados
            $estado_db    = 'INVALIDO';
        } else {
            $tipo_db      = 'FLEX';
            $codigo_final = $idExtraido;
            if (!preg_match('/^\d{8,}$/', $codigo_final)) {
                $estado_db = 'INVALIDO';
            } else {
                $estado_db = 'OK';
            }
        }
    } else {
        // Etiqueta Districad (código de barras)
        $tipo_db      = 'ETIQUETA';
        $codigo_final = $raw;
        if (!preg_match('/^\d{8,}$/', $codigo_final)) {
            $estado_db = 'INVALIDO';
        } else {
            $estado_db = 'OK';
        }
    }

    // ------------------------------------------------------------------
    // 3) Duplicados (incluye inválidos) dentro de la sesión
    // ------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT id
        FROM scans
        WHERE session_id = ? AND codigo = ?
        LIMIT 1
    ");
    $stmt->execute([$session_id, $codigo_final]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $estado_respuesta = null;
    $scanned_at       = date('Y-m-d H:i:s');
    $inserted_id      = null;

    if ($existing) {
        // Ya escaneado en este despacho: DUPLICADO
        $estado_respuesta = 'DUPLICADO';
    } else {
        // Insertar scan (OK o INVALIDO)
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

    // ------------------------------------------------------------------
    // 4) Métricas de la sesión (los duplicados no suman)
    // ------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN tipo = 'FLEX'     AND estado = 'OK' THEN 1 ELSE 0 END) AS flex_ok,
            SUM(CASE WHEN tipo = 'ETIQUETA' AND estado = 'OK' THEN 1 ELSE 0 END) AS etiqueta_ok,
            SUM(CASE WHEN estado = 'INVALIDO' THEN 1 ELSE 0 END)                AS invalidos
        FROM scans
        WHERE session_id = ?
    ");
    $stmt->execute([$session_id]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$metrics) {
        $metrics = [
            'total'       => 0,
            'flex_ok'     => 0,
            'etiqueta_ok' => 0,
            'invalidos'   => 0,
        ];
    }

    // ------------------------------------------------------------------
    // 5) Armar respuesta para el frontend
    // ------------------------------------------------------------------
    if ($estado_respuesta === 'INVALIDO' || $tipo_db === 'INVALIDO') {
        $tipo_ui = 'Inválido';
    } else {
        if ($tipo_db === 'FLEX') {
            $tipo_ui = 'Flex';
        } elseif ($tipo_db === 'ETIQUETA') {
            $tipo_ui = 'Etiqueta Districad';
        } else {
            $tipo_ui = $tipo_db;
        }
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
            'total'       => (int)$metrics['total'],
            'flex_ok'     => (int)$metrics['flex_ok'],
            'etiqueta_ok' => (int)$metrics['etiqueta_ok'],
            'invalidos'   => (int)$metrics['invalidos'],
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor',
        'error'   => $e->getMessage()
    ]);
}
