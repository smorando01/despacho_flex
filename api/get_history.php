<?php
// api/get_history.php
header('Content-Type: application/json');

require __DIR__ . '/config.php';
date_default_timezone_set('America/Montevideo');

try {
    $pdo = get_pdo();

    // 1) Seleccionar todas las sesiones CERRADAS
    // Traemos también métricas calculadas para no tener que procesar los scans
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.fecha,
            s.numero_en_dia,
            s.started_at,
            s.closed_at,
            COUNT(sc.id) AS total_scans,
            SUM(CASE WHEN sc.tipo = 'FLEX' AND sc.estado = 'OK' THEN 1 ELSE 0 END) AS flex_ok,
            SUM(CASE WHEN sc.tipo = 'ETIQUETA' AND sc.estado = 'OK' THEN 1 ELSE 0 END) AS etiqueta_ok,
            SUM(CASE WHEN sc.estado = 'INVALIDO' THEN 1 ELSE 0 END) AS invalidos
        FROM
            sessions s
        LEFT JOIN
            scans sc ON s.id = sc.session_id
        WHERE
            s.closed_at IS NOT NULL
        GROUP BY
            s.id
        ORDER BY
            s.fecha DESC, s.numero_en_dia DESC
    ");
    
    $stmt->execute();
    $sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'sesiones' => $sesiones
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor al obtener el historial.',
        'error'   => $e->getMessage()
    ]);
}