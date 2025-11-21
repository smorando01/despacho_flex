<?php
// api/create_session.php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_api_auth();
require_csrf_token();
date_default_timezone_set('America/Montevideo');

$allowedCouriers = ['FLEX', 'COLECTA'];

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

    $courier = isset($input['courier']) && is_string($input['courier'])
        ? strtoupper(trim($input['courier']))
        : '';

    $transportista = isset($input['transportista']) && is_string($input['transportista'])
        ? trim($input['transportista'])
        : '';

    $matricula = isset($input['matricula']) && is_string($input['matricula'])
        ? trim($input['matricula'])
        : '';

    if (!in_array($courier, $allowedCouriers, true) || $transportista === '' || $matricula === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Datos incompletos o inválidos para iniciar el despacho.'
        ]);
        exit;
    }

    $pdo = get_pdo();
    $pdo->beginTransaction();

    // Verificar que no exista otra sesión abierta
    $stmt = $pdo->prepare("
        SELECT id, courier
        FROM sessions
        WHERE closed_at IS NULL
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute();
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Ya hay un despacho abierto. Cerralo antes de iniciar uno nuevo.'
        ]);
        exit;
    }

    $hoy = date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(numero_en_dia), 0) AS max_n
        FROM sessions
        WHERE fecha = ? AND courier = ?
        FOR UPDATE
    ");
    $stmt->execute([$hoy, $courier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $numero_en_dia = ((int)($row['max_n'] ?? 0)) + 1;

    $stmt = $pdo->prepare("
        INSERT INTO sessions (fecha, started_at, courier, numero_en_dia, transportista, matricula)
        VALUES (?, NOW(), ?, ?, ?, ?)
    ");
    $stmt->execute([$hoy, $courier, $numero_en_dia, $transportista, $matricula]);

    $sessionId = (int)$pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'session' => [
            'id'            => $sessionId,
            'courier'       => $courier,
            'transportista' => $transportista,
            'matricula'     => $matricula,
            'numero_en_dia' => $numero_en_dia,
            'fecha'         => $hoy,
            'started_at'    => date('Y-m-d H:i:s'),
        ],
        'csrf_token' => issue_csrf_token(),
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor al iniciar el despacho.',
        'error'   => $e->getMessage(),
    ]);
}
