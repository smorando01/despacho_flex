<?php
// api/current_session.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require __DIR__ . '/config.php';
require_api_auth();
date_default_timezone_set('America/Montevideo');

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare("
        SELECT id, numero_en_dia, fecha, started_at, courier, transportista, matricula
        FROM sessions
        WHERE closed_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([
            'success'    => true,
            'session'    => null,
            'scans'      => [],
            'metrics'    => [
                'total'     => 0,
                'ok'        => 0,
                'invalidos' => 0,
            ],
            'last_scan'  => null,
            'csrf_token' => issue_csrf_token(),
        ]);
        exit;
    }

    $sessionData = [
        'id'            => (int)$session['id'],
        'numero_en_dia' => (int)$session['numero_en_dia'],
        'fecha'         => $session['fecha'],
        'started_at'    => $session['started_at'],
        'courier'       => strtoupper($session['courier']),
        'transportista' => $session['transportista'] ?? '',
        'matricula'     => $session['matricula'] ?? '',
    ];

    $stmt = $pdo->prepare("
        SELECT id, codigo, tipo, estado, scanned_at
        FROM scans
        WHERE session_id = ?
        ORDER BY scanned_at ASC, id ASC
    ");
    $stmt->execute([$sessionData['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $metrics = [
        'total'     => 0,
        'ok'        => 0,
        'invalidos' => 0,
    ];
    $scans = [];
    $lastScan = null;
    $tipoUi = $sessionData['courier'] === 'COLECTA' ? 'Colecta' : 'Flex';

    foreach ($rows as $row) {
        $metrics['total']++;

        if ($row['estado'] === 'OK') {
            $metrics['ok']++;
        }
        if ($row['estado'] === 'INVALIDO') {
            $metrics['invalidos']++;
        }

        $estadoUi = ($row['estado'] === 'INVALIDO') ? 'CÓDIGO INVÁLIDO' : $row['estado'];

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
        'session'    => $sessionData,
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
