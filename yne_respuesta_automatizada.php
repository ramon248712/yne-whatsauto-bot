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

// Simulamos entrada del cliente
$mensajeCliente = strtolower(trim($_GET['mensaje'] ?? 'tengo 2000 pesos'));

// Carga de CSV base de deudores
$deudores = array_map('str_getcsv', file('deudores.csv'));
array_walk($deudores, function (&$a) { $a = array_map('trim', $a); });
$headers = array_map('strtolower', array_shift($deudores));

// Buscar por número de teléfono
$telefono = $_GET['telefono'] ?? '+549XXXXXXXXXX';
$hoy = date('Y-m-d');
$visitas = 'visitas.csv';
$esPrimerMensaje = true;

// Cargar visitas
if (file_exists($visitas)) {
    $v = array_map('str_getcsv', file($visitas));
    foreach ($v as $vi) {
        if ($vi[0] == $telefono && $vi[1] == $hoy) {
            $esPrimerMensaje = false;
            break;
        }
    }
}

// Obtener datos del cliente
$datosCliente = null;
foreach ($deudores as $fila) {
    $registro = array_combine($headers, $fila);
    if ($registro['telefono'] == $telefono) {
        $datosCliente = $registro;
        break;
    }
}

// Generar respuesta
$respuesta = "";
$nombre = strtoupper($datosCliente['nombre'] ?? '');

// Detectar monto si lo hay
$montoDetectado = detectarMonto($mensajeCliente);

if (!$datosCliente) {
    $respuesta = "HOLA PARA AYUDARTE NECESITO QUE ME INDIQUES TU NUMERO DE DNI";
} else if (strpos($mensajeCliente, 'gracia') !== false) {
    $respuesta = "DE NADA ESTAMOS PARA AYUDAR";
} else if (strpos($mensajeCliente, 'cuota') !== false || strpos($mensajeCliente, 'plan') !== false || strpos($mensajeCliente, 'refinanciar') !== false || strpos($mensajeCliente, 'acuerdo') !== false) {
    $respuesta = "NO HACEMOS CUOTAS";
} else if ($montoDetectado !== null) {
    if ($montoDetectado < 5000) {
        $respuesta = "ENTENDEMOS LA SITUACION PERO LE PEDIMOS HACER UN ESFUERZO MAYOR ESTE MES PARA EVITAR CONSECUENCIAS";
    } else {
        $respuesta = "PODEMOS REGISTRAR EL MONTO DE " . number_format($montoDetectado, 0, '', '.') . " COMO UN APORTE A CUENTA LE PEDIMOS CONFIRMAR CUANDO PUEDE ABONAR EL RESTO";
    }
} else if ($esPrimerMensaje) {
    $saludo = "BUENAS TARDES";
    $deuda = strtoupper($datosCliente['monto'] ?? '');
    $respuesta = "$saludo $nombre LE INFORMAMOS QUE REGISTRA UN SALDO PENDIENTE DE $deuda POR FAVOR INGRESE SALDO EN LA APP DE UALA ANTES DE FIN DE MES";
    file_put_contents($visitas, "$telefono,$hoy
", FILE_APPEND);
} else {
    $respuesta = "LE RECORDAMOS QUE DEBE INGRESAR SALDO EN LA APP DE UALA A LA BREVEDAD";
}

echo json_encode(["respuesta" => $respuesta]);
?>
