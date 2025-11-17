<?php
// api/current_session.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_api_auth();

require __DIR__ . '/config.php';
date_default_timezone_set('America/Montevideo');

try {
    $pdo = get_pdo();

    $hoy     = date('Y-m-d');
    $courier = 'FLEX';

    $stmt = $pdo->prepare("
        SELECT id, numero_en_dia, fecha, started_at
        FROM sessions
        WHERE fecha = ? AND courier = ? AND closed_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$hoy, $courier]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([
            'success'    => true,
            'session'    => null,
            'scans'      => [],
            'metrics'    => [
                'total'       => 0,
                'flex_ok'     => 0,
                'etiqueta_ok' => 0,
                'invalidos'   => 0,
            ],
            'last_scan'  => null,
            'csrf_token' => issue_csrf_token(),
        ]);
        exit;
    }

    $session = [
        'id'            => (int)$session['id'],
        'numero_en_dia' => (int)$session['numero_en_dia'],
        'fecha'         => $session['fecha'],
        'started_at'    => $session['started_at'],
    ];

    $stmt = $pdo->prepare("
        SELECT id, codigo, tipo, estado, scanned_at
        FROM scans
        WHERE session_id = ?
        ORDER BY scanned_at ASC, id ASC
    ");
    $stmt->execute([$session['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $metrics = [
        'total'       => 0,
        'flex_ok'     => 0,
        'etiqueta_ok' => 0,
        'invalidos'   => 0,
    ];
    $scans = [];
    $lastScan = null;

    foreach ($rows as $row) {
        $metrics['total']++;

        if ($row['tipo'] === 'FLEX' && $row['estado'] === 'OK') {
            $metrics['flex_ok']++;
        }
        if ($row['tipo'] === 'ETIQUETA' && $row['estado'] === 'OK') {
            $metrics['etiqueta_ok']++;
        }
        if ($row['estado'] === 'INVALIDO') {
            $metrics['invalidos']++;
        }

        $tipoUi = 'Inválido';
        if ($row['tipo'] === 'FLEX') {
            $tipoUi = 'Flex';
        } elseif ($row['tipo'] === 'ETIQUETA') {
            $tipoUi = 'Etiqueta Districad';
        }

        if ($row['estado'] === 'INVALIDO') {
            $estadoUi = 'CÓDIGO INVÁLIDO';
        } else {
            $estadoUi = $row['estado'];
        }

        $timestamp = strtotime($row['scanned_at']);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $scan = [
            'id'     => (int)$row['id'],
            'codigo' => $row['codigo'],
            'tipo'   => $tipoUi,
            'estado' => $estadoUi,
            'ts'     => $timestamp * 1000,
        ];

        $scans[]  = $scan;
        $lastScan = $scan;
    }

    echo json_encode([
        'success'    => true,
        'session'    => $session,
        'scans'      => $scans,
        'metrics'    => $metrics,
        'last_scan'  => $lastScan,
        'csrf_token' => issue_csrf_token(),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor al sincronizar la sesión.',
        'error'   => $e->getMessage(),
    ]);
}
