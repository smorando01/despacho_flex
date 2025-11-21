<?php
// api/get_history.php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_api_auth();
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

    $whereClauses = ['s.closed_at IS NOT NULL'];
    $params = [];

    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    if ($search !== '') {
        if (preg_match('/^\d+$/', $search)) {
            $whereClauses[] = 's.numero_en_dia = :search_num';
            $params[':search_num'] = (int)$search;
        } else {
            $whereClauses[] = 's.fecha LIKE :search_date';
            $params[':search_date'] = '%' . $search . '%';
        }
    }

    $fromDate = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    if ($fromDate !== '') {
        $fromDateTime = DateTime::createFromFormat('Y-m-d', $fromDate);
        if ($fromDateTime !== false) {
            $whereClauses[] = 's.fecha >= :from_date';
            $params[':from_date'] = $fromDateTime->format('Y-m-d');
        }
    }

    $toDate = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
    if ($toDate !== '') {
        $toDateTime = DateTime::createFromFormat('Y-m-d', $toDate);
        if ($toDateTime !== false) {
            $whereClauses[] = 's.fecha <= :to_date';
            $params[':to_date'] = $toDateTime->format('Y-m-d');
        }
    }

    $whereSql = implode(' AND ', $whereClauses);

    $totalSql = "SELECT COUNT(*) FROM sessions s WHERE $whereSql";
    $totalStmt = $pdo->prepare($totalSql);
    foreach ($params as $key => $value) {
        $totalStmt->bindValue($key, $value);
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
        $stmt->bindValue($key, $value);
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
