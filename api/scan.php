<?php
// api/scan.php
header('Content-Type: application/json');

require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_api_auth();
require_csrf_token();
date_default_timezone_set('America/Montevideo');

// Devuelve ID de Colecta si cumple patrón (11 dígitos o QR id[Ñ[...])
function colecta_id(string $raw): ?string
{
    $raw = trim($raw);

    if (preg_match('/^\d{11}$/', $raw) === 1) {
        return $raw;
    }

    if (preg_match('/^id\[[ñÑ]\[(\d{6,20})/u', $raw, $m)) {
        $id = $m[1];
        if (strlen($id) === 11 && ctype_digit($id)) {
            return $id;
        }
    }

    return null;
}

// Analiza Flex: QR/ID largo distinto de 11 dígitos
function analizar_flex(string $raw): array
{
    $raw = trim($raw);
    $codigoFinal = $raw;
    $esValido = false;
    $pareceFlex = false;

    $esQR = preg_match('/ñ|hash|sender\?id|security\?digit|id\[/i', $raw) === 1;

    $extraerID = function (string $valor) {
        if (preg_match('/\[id\[[ñÑ]\[(\d{8,20})/i', $valor, $m)) {
            return $m[1];
        }
        if (preg_match('/id[¨\[]+[ñÑ][¨\[]+(\d{8,20})/i', $valor, $m)) {
            return $m[1];
        }
        $normalizado = strtolower(preg_replace('/[^a-z0-9]/i', ',', $valor));
        if (preg_match('/id,+(\d{8,20})/', $normalizado, $m)) {
            return $m[1];
        }
        return null;
    };

    if ($esQR) {
        $pareceFlex = true;
        $idExtraido = $extraerID($raw);
        if ($idExtraido !== null && preg_match('/^\d{8,}$/', $idExtraido) === 1 && strlen($idExtraido) !== 11) {
            $codigoFinal = $idExtraido;
            $esValido    = true;
        } else {
            $codigoFinal = $idExtraido ?? $raw;
        }
    } elseif (preg_match('/^\d{8,}$/', $raw) === 1 && strlen($raw) !== 11) {
        $pareceFlex   = true;
        $codigoFinal  = $raw;
        $esValido     = true;
    }

    return [
        'valido'      => $esValido,
        'codigo'      => $codigoFinal,
        'parece_flex' => $pareceFlex,
    ];
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

    $colectaId    = colecta_id($raw);
    $flexAnalisis = analizar_flex($raw);

    if ($courier === 'COLECTA') {
        if ($colectaId !== null) {
            $codigo_final = $colectaId;
            $estado_db    = 'OK';
        } elseif ($flexAnalisis['parece_flex']) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Código inválido: este código es de Flex, no de Colecta'
            ]);
            exit;
        } else {
            $codigo_final = $raw;
            $estado_db    = 'INVALIDO';
        }
    } elseif ($courier === 'FLEX') {
        if ($colectaId !== null) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Código inválido: este código es de Colecta, no de Flex'
            ]);
            exit;
        }

        if ($flexAnalisis['valido']) {
            $codigo_final = $flexAnalisis['codigo'];
            $estado_db    = 'OK';
        } else {
            $codigo_final = $flexAnalisis['codigo'] ?? $raw;
            $estado_db    = 'INVALIDO';
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

    $tipo_ui = ($courier === 'COLECTA') ? 'Colecta' : 'Flex';
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
