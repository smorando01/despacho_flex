<?php
// api/close_session.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require __DIR__ . '/config.php';
require_api_auth();
require_csrf_token();
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
            'success' => false,
            'message' => 'No hay ningún despacho abierto para cerrar.'
        ]);
        exit;
    }

    $session_id    = (int)$session['id'];
    $numero_en_dia = (int)$session['numero_en_dia'];
    $fechaSesion   = $session['fecha'];
    $courier       = strtoupper((string)$session['courier']);
    $transportista = $session['transportista'] ?? '';
    $matricula     = $session['matricula'] ?? '';
    $tipoUi        = $courier === 'COLECTA' ? 'Colecta' : 'Flex';

    $stmt = $pdo->prepare("
        SELECT codigo, tipo, estado, scanned_at
        FROM scans
        WHERE session_id = ?
        ORDER BY scanned_at ASC, id ASC
    ");
    $stmt->execute([$session_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode([
            'success' => false,
            'message' => 'El despacho está vacío, no hay códigos para resumir.'
        ]);
        exit;
    }

    $total     = 0;
    $okCount   = 0;
    $invalidos = 0;

    $primeraHora = null;
    $ultimaHora  = null;

    $listado = [];

    foreach ($rows as $r) {
        $total++;

        if ($r['estado'] === 'OK') {
            $okCount++;
        }
        if ($r['estado'] === 'INVALIDO') {
            $invalidos++;
        }

        $ts = strtotime($r['scanned_at']);
        if ($primeraHora === null || $ts < $primeraHora) {
            $primeraHora = $ts;
        }
        if ($ultimaHora === null || $ts > $ultimaHora) {
            $ultimaHora = $ts;
        }

        $hora = date('H:i:s', $ts);

        $tipoLinea = ($r['tipo'] === 'COLECTA') ? 'Colecta' : (($r['tipo'] === 'FLEX') ? 'Flex' : $r['tipo']);
        $estadoUi  = ($r['estado'] === 'OK') ? 'OK' : (($r['estado'] === 'INVALIDO') ? 'CÓDIGO INVÁLIDO' : $r['estado']);

        $listado[] = "> {$r['codigo']}  /  {$hora}  /  {$tipoLinea}  /  {$estadoUi}";
    }

    $duracionSeg = max(0, $ultimaHora - $primeraHora);
    $dh = str_pad(floor($duracionSeg / 3600), 2, '0', STR_PAD_LEFT);
    $dm = str_pad(floor(($duracionSeg % 3600) / 60), 2, '0', STR_PAD_LEFT);
    $ds = str_pad($duracionSeg % 60, 2, '0', STR_PAD_LEFT);
    $duracionStr = "{$dh}:{$dm}:{$ds}";

    $velocidad = $duracionSeg > 0
        ? number_format($total / ($duracionSeg / 60), 2, ',', '')
        : '-';

    $stmt = $pdo->prepare("
        UPDATE sessions
        SET closed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$session_id]);

    $fechaHoyFmt  = date('d/m/Y', strtotime($fechaSesion));
    $horaCierre   = date('H:i:s');
    $subject      = "📦 Despacho {$tipoUi} - {$fechaHoyFmt} #{$numero_en_dia}";

    $lineas = [];
    $lineas[] = "Tipo de Despacho: {$tipoUi}";
    $lineas[] = "Empresa de Transporte: " . ($transportista !== '' ? $transportista : 'N/A');
    $lineas[] = "Matrícula del Vehículo: " . ($matricula !== '' ? $matricula : 'N/A');
    $lineas[] = "";
    $lineas[] = "Resumen del Despacho día {$fechaHoyFmt} / Hora de Cierre: {$horaCierre}";
    $lineas[] = "Despacho #: {$numero_en_dia}";
    $lineas[] = "";
    $lineas[] = "Paquetes registrados: {$total}";
    $lineas[] = "- OK: {$okCount}";
    $lineas[] = "- Inválidos: {$invalidos}";
    $lineas[] = "";
    $lineas[] = "Duración del Despacho: {$duracionStr}";
    $lineas[] = "Velocidad promedio: {$velocidad} paquetes/min";
    $lineas[] = "";
    $lineas[] = "Listado de ventas despachadas:";
    $lineas[] = "";
    $lineas   = array_merge($lineas, $listado);

    $body = implode("\r\n", $lineas);

    $to = 'santiago@amvuy.com, prueba@amvuy.com, prueba2@amvstore.com.uy';
    $headers  = "From: Despacho Flex <no-reply@amvstore.com.uy>\r\n";
    $headers .= "Reply-To: santiago@amvuy.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $mailOk = @mail($to, $subject, $body, $headers);

    echo json_encode([
        'success'       => true,
        'mail_sent'     => $mailOk ? true : false,
        'message'       => $mailOk ? 'Despacho cerrado y mail enviado.' : 'Despacho cerrado, pero el mail no se pudo enviar.',
        'metrics'       => [
            'total'         => $total,
            'ok'            => $okCount,
            'invalidos'     => $invalidos,
            'fecha'         => $fechaSesion,
            'numero_en_dia' => $numero_en_dia,
            'courier'       => $courier,
            'transportista' => $transportista,
            'matricula'     => $matricula,
        ],
        'numero_en_dia' => $numero_en_dia
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en servidor al cerrar el despacho.',
        'error'   => $e->getMessage()
    ]);
}
