<?php
// api/close_session.php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_api_auth();
require_csrf_token();

require __DIR__ . '/config.php';
date_default_timezone_set('America/Montevideo');

try {
    $pdo = get_pdo();

    $hoy     = date('Y-m-d');
    $courier = 'FLEX';

    // 1) Buscar sesión abierta de hoy
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
            'success' => false,
            'message' => 'No hay ningún despacho abierto para cerrar.'
        ]);
        exit;
    }

    $session_id    = (int)$session['id'];
    $numero_en_dia = (int)$session['numero_en_dia'];
    $fechaSesion   = $session['fecha'];

    // 2) Traer todos los scans de la sesión
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

    // 3) Calcular métricas
    $total       = 0;
    $flex_ok     = 0;
    $etiqueta_ok = 0;
    $invalidos   = 0;

    $primeraHora = null;
    $ultimaHora  = null;

    $listado = [];

    foreach ($rows as $r) {
        $total++;

        if ($r['tipo'] === 'FLEX' && $r['estado'] === 'OK') {
            $flex_ok++;
        }
        if ($r['tipo'] === 'ETIQUETA' && $r['estado'] === 'OK') {
            $etiqueta_ok++;
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

        // texto tipo: "> 12345678901  /  12:34:56  /  Flex  /  OK"
        $tipoUi = ($r['tipo'] === 'FLEX')
            ? 'Flex'
            : (($r['tipo'] === 'ETIQUETA') ? 'Etiqueta Districad' : 'Inválido');

        $estadoUi = ($r['estado'] === 'OK')
            ? 'OK'
            : (($r['estado'] === 'INVALIDO') ? 'CÓDIGO INVÁLIDO' : $r['estado']);

        $listado[] = "> {$r['codigo']}  /  {$hora}  /  {$tipoUi}  /  {$estadoUi}";
    }

    // duración
    $duracionSeg = max(0, $ultimaHora - $primeraHora);
    $dh = str_pad(floor($duracionSeg / 3600), 2, '0', STR_PAD_LEFT);
    $dm = str_pad(floor(($duracionSeg % 3600) / 60), 2, '0', STR_PAD_LEFT);
    $ds = str_pad($duracionSeg % 60, 2, '0', STR_PAD_LEFT);
    $duracionStr = "{$dh}:{$dm}:{$ds}";

    $velocidad = $duracionSeg > 0
        ? number_format($total / ($duracionSeg / 60), 2, ',', '')
        : '-';

    // 4) Cerrar la sesión
    $stmt = $pdo->prepare("
        UPDATE sessions
        SET closed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$session_id]);

    // 5) Armar cuerpo del mail
    $fechaHoyFmt  = date('d/m/Y', strtotime($fechaSesion));
    $horaCierre   = date('H:i:s');
    $subject      = "📦 Despacho Flex - {$fechaHoyFmt} #{$numero_en_dia}";

    $lineas = [];
    $lineas[] = "Resumen del Despacho día {$fechaHoyFmt} / Hora de Cierre: {$horaCierre}";
    $lineas[] = "Despacho #: {$numero_en_dia}";
    $lineas[] = "";
    $lineas[] = "Paquetes Despachados: {$total}";
    $lineas[] = "- FLEX OK: {$flex_ok}";
    $lineas[] = "- ETIQUETA OK: {$etiqueta_ok}";
    $lineas[] = "- INVÁLIDOS: {$invalidos}";
    $lineas[] = "";
    $lineas[] = "Duración del Despacho: {$duracionStr}";
    $lineas[] = "Velocidad promedio: {$velocidad} paquetes/min";
    $lineas[] = "";
    $lineas[] = "Listado de ventas despachadas:";
    $lineas[] = "";
    $lineas   = array_merge($lineas, $listado);

    $body = implode("\r\n", $lineas);

    // 6) Enviar mail
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
            'flex_ok'       => $flex_ok,
            'etiqueta_ok'   => $etiqueta_ok,
            'invalidos'     => $invalidos,
            'fecha'         => $fechaSesion,
            'numero_en_dia' => $numero_en_dia,
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
