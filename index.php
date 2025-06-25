<?php
date_default_timezone_set("America/Argentina/Buenos_Aires");
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Función para detectar monto mencionado
function detectarMonto($mensaje) {
    $mensaje = str_replace(['$', '.', ','], ['', '', ''], $mensaje);
    if (preg_match_all('/\b\d{2,}\b/', $mensaje, $coincidencias)) {
        return intval($coincidencias[0][0]);
    }
    return null;
}

// Entrada simulada
$mensajeCliente = strtolower(trim($_GET['mensaje'] ?? 'quiero pagar'));
$telefono = $_GET['telefono'] ?? '+549XXXXXXXXXX';

// Cargar CSV de deudores
$deudores = array_map('str_getcsv', file('deudores.csv'));
array_walk($deudores, function (&$a) { $a = array_map('trim', $a); });
$headers = array_map('strtolower', array_shift($deudores));

// Cargar registro de visitas
$hoy = date('Y-m-d');
$visitas = 'visitas.csv';
$esPrimerMensaje = true;
if (file_exists($visitas)) {
    $v = array_map('str_getcsv', file($visitas));
    foreach ($v as $vi) {
        if ($vi[0] == $telefono && $vi[1] == $hoy) {
            $esPrimerMensaje = false;
            break;
        }
    }
}

// Buscar cliente
$datosCliente = null;
foreach ($deudores as $fila) {
    $registro = array_combine($headers, $fila);
    if ($registro['telefono'] == $telefono) {
        $datosCliente = $registro;
        break;
    }
}

$respuesta = "";
$nombre = strtoupper($datosCliente['nombre'] ?? '');
$montoDetectado = detectarMonto($mensajeCliente);

// Respuesta si no está en el CSV
if (!$datosCliente) {
    $respuesta = "HOLA PARA AYUDARTE NECESITO QUE ME INDIQUES TU NUMERO DE DNI";
}
// Palabras clave
else if (strpos($mensajeCliente, 'gracia') !== false) {
    $respuesta = "DE NADA ESTAMOS PARA AYUDAR";
}
else if (strpos($mensajeCliente, 'cuota') !== false || strpos($mensajeCliente, 'plan') !== false || strpos($mensajeCliente, 'refinanciar') !== false || strpos($mensajeCliente, 'acuerdo') !== false) {
    $respuesta = "NO HACEMOS CUOTAS";
}
else if (strpos($mensajeCliente, 'no tengo trabajo') !== false) {
    $respuesta = "LE PEDIMOS HACER UN ESFUERZO ESTE MES AUNQUE SEA MINIMO PARA EVITAR REPORTES";
}
else if (strpos($mensajeCliente, 'uala') !== false && strpos($mensajeCliente, 'no') !== false) {
    $respuesta = "SI NO PUEDE INGRESAR A LA APP DE UALA LE SUGERIMOS CONTACTARSE CON EL SOPORTE DE LA PLATAFORMA";
}
// Monto detectado
else if ($montoDetectado !== null) {
    if ($montoDetectado < 5000) {
        $respuesta = "ENTENDEMOS LA SITUACION PERO LE PEDIMOS HACER UN ESFUERZO MAYOR ESTE MES PARA EVITAR CONSECUENCIAS";
    } else {
        $respuesta = "PODEMOS REGISTRAR EL MONTO DE " . number_format($montoDetectado, 0, '', '.') . " COMO UN PAGO A CUENTA LE PEDIMOS CONFIRMAR CUANDO PODRIA ABONAR";
    }
}
// Primer mensaje del día
else if ($esPrimerMensaje) {
    $saludo = "BUENAS TARDES";
    $deuda = strtoupper($datosCliente['monto'] ?? '');
    $respuesta = "$saludo $nombre LE INFORMAMOS QUE REGISTRA UN SALDO PENDIENTE DE $deuda POR FAVOR INGRESE SALDO EN LA APP DE UALA ANTES DE FIN DE MES

RODRIGO";
    file_put_contents($visitas, "$telefono,$hoy
", FILE_APPEND);
}
// Resto de casos
else {
    $respuesta = "LE RECORDAMOS QUE DEBE INGRESAR SALDO EN LA APP DE UALA A LA BREVEDAD";
}

echo json_encode(["respuesta" => $respuesta]);
?>
