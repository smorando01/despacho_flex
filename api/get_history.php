<?php
// api/get_history.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_api_auth();

require __DIR__ . '/config.php';
date_default_timezone_set('America/Montevideo');

try {
    $pdo = get_pdo();

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    if ($limit < 1) {
        $limit = 1;
    }
    $limit = min($limit, 100);

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }

    $offset = ($page - 1) * $limit;

    $conditions = ['s.closed_at IS NOT NULL'];
    $params = [];

    $fromRaw = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    if ($fromRaw !== '') {
        $fromDate = DateTime::createFromFormat('Y-m-d', $fromRaw);
        if ($fromDate && $fromDate->format('Y-m-d') === $fromRaw) {
            $conditions[] = 's.fecha >= :from_date';
            $params[':from_date'] = $fromRaw;
        }
    }

    $toRaw = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    if ($toRaw !== '') {
        $toDate = DateTime::createFromFormat('Y-m-d', $toRaw);
        if ($toDate && $toDate->format('Y-m-d') === $toRaw) {
            $conditions[] = 's.fecha <= :to_date';
            $params[':to_date'] = $toRaw;
        }
    }

    $whereSql = implode(' AND ', $conditions);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM sessions s WHERE $whereSql");
    foreach ($params as $key => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $totalStmt->bindValue($key, $value, $type);
    }
    $totalStmt->execute();
    $totalRows = (int)$totalStmt->fetchColumn();

    // 1) Seleccionar sesiones cerradas paginadas
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
            $whereSql
        GROUP BY
            s.id
        ORDER BY
            s.fecha DESC, s.numero_en_dia DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $type);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $sesiones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'sesiones' => $sesiones,
        'pagination' => [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => $totalRows,
            'totalPages' => max(1, $limit > 0 ? (int)ceil($totalRows / $limit) : 1),
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor al obtener el historial.',
        'error'   => $e->getMessage()
    ]);
}