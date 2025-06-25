<?php
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Entradas
$telefono = $_GET['telefono'] ?? '';
$mensajeCliente = strtolower(trim($_GET['mensaje'] ?? ''));
$hoy = date('Y-m-d');
$visitasFile = 'visitas.csv';
$deudoresFile = 'deudores.csv';
$respuesta = '';
$esPrimerMensaje = true;

// Verificar si ya se envió un mensaje hoy
if (file_exists($visitasFile)) {
    $vFile = fopen($visitasFile, 'r');
    while (($line = fgetcsv($vFile)) !== false) {
        if ($line[0] === $telefono && $line[1] === $hoy) {
            $esPrimerMensaje = false;
            break;
        }
    }
    fclose($vFile);
}

// Buscar datos del cliente
$datosCliente = null;
if (file_exists($deudoresFile)) {
    $csv = fopen($deudoresFile, 'r');
    $headers = fgetcsv($csv);
    while (($data = fgetcsv($csv)) !== false) {
        $registro = array_combine($headers, $data);
        if (trim($registro['Telefono']) === trim($telefono)) {
            $datosCliente = $registro;
            break;
        }
    }
    fclose($csv);
}

// Función para detectar monto
function detectarMonto($msg) {
    $msg = str_replace(['$', '.', ','], ['', '', ''], $msg);
    if (preg_match_all('/\b\d{2,}\b/', $msg, $matches)) {
        return intval($matches[0][0]);
    }
    return null;
}

// Generar respuesta
if (!$datosCliente) {
    $respuesta = "HOLA PARA AYUDARTE NECESITO QUE ME INDIQUES TU NUMERO DE DNI";
} else {
    $nombre = strtoupper($datosCliente['Nombre']);
    $montoDetectado = detectarMonto($mensajeCliente);
    $montoDeuda = strtoupper($datosCliente['Deuda'] ?? '');

// Palabras clave
    if (strpos($mensajeCliente, 'gracia') !== false) {
        $respuesta = "DE NADA ESTAMOS PARA AYUDAR";
    } elseif (strpos($mensajeCliente, 'cuota') !== false || strpos($mensajeCliente, 'plan') !== false || strpos($mensajeCliente, 'refinanciar') !== false || strpos($mensajeCliente, 'acuerdo') !== false) {
        $respuesta = "NO HACEMOS CUOTAS";
    } elseif (strpos($mensajeCliente, 'no tengo trabajo') !== false) {
        $respuesta = "LE PEDIMOS HACER UN ESFUERZO ESTE MES AUNQUE SEA MINIMO PARA EVITAR REPORTES";
    } elseif (strpos($mensajeCliente, 'uala') !== false && strpos($mensajeCliente, 'no') !== false) {
        $respuesta = "SI NO PUEDE INGRESAR A LA APP DE UALA LE SUGERIMOS CONTACTARSE CON EL SOPORTE DE LA PLATAFORMA";
    } elseif ($montoDetectado !== null) {
        if ($montoDetectado < 5000) {
            $respuesta = "ENTENDEMOS LA SITUACION PERO LE PEDIMOS HACER UN ESFUERZO MAYOR ESTE MES PARA EVITAR CONSECUENCIAS";
        } else {
            $respuesta = "PODEMOS REGISTRAR EL MONTO DE " . number_format($montoDetectado, 0, '', '.') . " COMO UN PAGO A CUENTA LE PEDIMOS CONFIRMAR CUANDO PODRIA ABONAR";
        }
    } elseif ($esPrimerMensaje) {
        $saludo = "BUENAS TARDES"; // O podés calcularlo según hora
        $respuesta = "$saludo $nombre LE INFORMAMOS QUE REGISTRA UN SALDO PENDIENTE DE $montoDeuda POR FAVOR INGRESE SALDO EN LA APP DE UALA ANTES DE FIN DE MES

RODRIGO";
        file_put_contents($visitasFile, "$telefono,$hoy
", FILE_APPEND);
    } else {
        $respuesta = "LE RECORDAMOS QUE DEBE INGRESAR SALDO EN LA APP DE UALA A LA BREVEDAD";
    }
}

echo json_encode(["respuesta" => $respuesta]);
exit;
?>
