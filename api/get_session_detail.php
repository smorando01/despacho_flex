<?php
// api/get_session_detail.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require __DIR__ . '/config.php';
require_api_auth();
date_default_timezone_set('America/Montevideo');

// 1. Obtener y validar el ID de la sesión desde la URL (?id=...)
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($session_id <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'success' => false,
        'message' => 'ID de sesión inválido.'
    ]);
    exit;
}

try {
    $pdo = get_pdo();

    // 2. Traer los detalles/métricas de ESA sesión
    // (Usamos una consulta similar a la del historial, pero para un ID)
    $stmt = $pdo->prepare("
        SELECT
            s.id, s.fecha, s.numero_en_dia, s.started_at, s.closed_at,
            COUNT(sc.id) AS total_scans,
            SUM(CASE WHEN sc.tipo = 'FLEX' AND sc.estado = 'OK' THEN 1 ELSE 0 END) AS flex_ok,
            SUM(CASE WHEN sc.tipo = 'ETIQUETA' AND sc.estado = 'OK' THEN 1 ELSE 0 END) AS etiqueta_ok,
            SUM(CASE WHEN sc.estado = 'INVALIDO' THEN 1 ELSE 0 END) AS invalidos
        FROM sessions s
        LEFT JOIN scans sc ON s.id = sc.session_id
        WHERE s.id = ?
        GROUP BY s.id
        LIMIT 1
    ");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        http_response_code(404); // Not Found
        echo json_encode([
            'success' => false,
            'message' => 'Sesión no encontrada.'
        ]);
        exit;
    }

    // 3. Traer TODOS los scans individuales de esa sesión
    $stmt = $pdo->prepare("
        SELECT id, codigo, tipo, estado, scanned_at, raw_input
        FROM scans
        WHERE session_id = ?
        ORDER BY scanned_at ASC
    ");
    $stmt->execute([$session_id]);
    $scans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Devolver ambos resultados
    echo json_encode([
        'success' => true,
        'session' => $session, // Contiene las métricas
        'scans'   => $scans    // Contiene la lista de códigos
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor al obtener el detalle.',
        'error'   => $e->getMessage()
    ]);
}
