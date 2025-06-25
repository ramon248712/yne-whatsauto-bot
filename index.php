<?php
date_default_timezone_set("America/Argentina/Buenos_Aires");
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

$app = $_POST["app"] ?? "";
$sender = $_POST["sender"] ?? "";
$message = $_POST["message"] ?? "";

// Normalizar número entrante (solo 10 dígitos finales)
$sender = preg_replace('/\D/', '', $sender);
if (strlen($sender) < 8) exit(json_encode(["reply" => ""]));
$numero10 = substr($sender, -10);
$senderCompleto = "+549$numero10";

// Cargar visitas
$visitas = [];
if (file_exists("visitas.csv")) {
    $fp = fopen("visitas.csv", "r");
    while (($linea = fgetcsv($fp)) !== false) {
        if (isset($linea[0], $linea[1])) {
            $visitas[$linea[0]] = $linea[1];
        }
    }
    fclose($fp);
}

// Buscar deudor en CSV codificado en latin1
function buscarDeudor($telefono10) {
    if (!file_exists("deudores.csv")) return null;
    $archivo = fopen("deudores.csv", "r");
    while (($datos = fgetcsv($archivo, 0, ';')) !== false) {
        if (count($datos) >= 4) {
            $numeroCsv = preg_replace('/\D/', '', $datos[2]);
            $numeroCsv10 = substr($numeroCsv, -10);
            if ($numeroCsv10 === $telefono10) {
                fclose($archivo);
                return [
                    "nombre" => $datos[0],
                    "dni" => $datos[1],
                    "telefono" => $numeroCsv10,
                    "deuda" => str_replace(',', '.', $datos[3])
                ];
            }
        }
    }
    fclose($archivo);
    return null;
}

function registrarVisita($telefono) {
    $visitas = [];
    if (file_exists("visitas.csv")) {
        $fp = fopen("visitas.csv", "r");
        while (($linea = fgetcsv($fp)) !== false) {
            if (isset($linea[0], $linea[1])) {
                $visitas[$linea[0]] = $linea[1];
            }
        }
        fclose($fp);
    }
    $visitas[$telefono] = date("Y-m-d");
    $fp = fopen("visitas.csv", "w");
    foreach ($visitas as $num => $fecha) {
        fputcsv($fp, [$num, $fecha]);
    }
    fclose($fp);
}

function horaSaludo() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12) return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
}

function respuestaGracias() {
    $opciones = [
        "De nada, estamos para ayudarte.", "Un placer ayudarte.", "Con gusto.",
        "Siempre a disposición.", "Gracias a vos por comunicarte.", "Estamos para ayudarte.",
        "Un gusto poder colaborar.", "Cualquier cosa, escribinos.", "Para eso estamos.",
        "Lo que necesites, consultanos.", "No hay de qué.", "A disposición siempre.",
        "Quedamos atentos.", "Nos alegra ayudarte."
    ];
    return $opciones[array_rand($opciones)];
}

function respuestaNoCuotas() {
    $opciones = [
        "No trabajamos con cuotas, debe ingresar saldo en su app de Ualá.",
        "No manejamos planes de pago. Se requiere que ingrese fondos en Ualá.",
        "Le informamos que no ofrecemos cuotas. Debe ingresar saldo en su cuenta.",
        "No realizamos refinanciaciones, solo se requiere que transfiera a su CVU.",
        "Cumplimos en informarle que no hacemos planes. Ingrese lo que pueda en su cuenta Ualá."
    ];
    return $opciones[array_rand($opciones)];
}

function respuestaYaPago() {
    return "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo que figura en la app de Ualá.";
}

function respuestaUrgencia() {
    $opciones = [
        "Le informamos que debe ingresar saldo en la app de Ualá para evitar acciones por falta de cumplimiento.",
        "Le recordamos que debe ingresar dinero en su cuenta de Ualá para evitar complicaciones.",
        "Cumplimos en informarle que es necesario ingresar fondos en su app de Ualá.",
        "Para evitar consecuencias por incumplimiento, ingrese saldo en su app de Ualá cuanto antes.",
        "Debe ingresar fondos en Ualá para evitar acciones por falta de pago.",
        "Le pedimos que transfiera a su propio CVU desde la app de Ualá cuanto antes.",
        "Le recordamos que debe abonar ingresando saldo en Ualá para regularizar su situación.",
        "Evite gestiones adicionales ingresando dinero en su cuenta Ualá.",
        "Cumplimos en informarle que debe cargar fondos hoy mismo en la app.",
        "Le informamos que sigue activa su deuda, regularícela ingresando saldo en Ualá."
    ];
    return $opciones[array_rand($opciones)];
}

// --- Procesar mensaje ---
$msg = strtolower($message);
$hoy = date("Y-m-d");
$deudor = buscarDeudor($numero10);

if (strpos($msg, 'gracia') !== false) {
    $respuesta = respuestaGracias();
} elseif (strpos($msg, 'cuota') !== false || strpos($msg, 'refinanciar') !== false || strpos($msg, 'plan') !== false) {
    $respuesta = respuestaNoCuotas();
} elseif (strpos($msg, 'ya pagué') !== false || strpos($msg, 'pagué') !== false || strpos($msg, 'saldad') !== false || strpos($msg, 'no debo') !== false) {
    $respuesta = respuestaYaPago();
} elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    $yaSaludoHoy = isset($visitas[$senderCompleto]) && $visitas[$senderCompleto] === $hoy;

    if (!$yaSaludoHoy) {
        $saludo = horaSaludo();
        $respuesta = "$saludo $nombre. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá.";
        registrarVisita($senderCompleto);
    } else {
        $respuesta = respuestaUrgencia();
    }
} elseif (preg_match('/\d{7,9}/', $msg, $coincidencia)) {
    $dniIngresado = $coincidencia[0];
    if (file_exists("deudores.csv")) {
        $fp = fopen("deudores.csv", "r+");
        $lineas = [];
        $deudor = null;
        while (($linea = fgetcsv($fp, 0, ';')) !== false) {
            if (count($linea) >= 4 && trim($linea[1]) === $dniIngresado) {
                $linea[2] = $numero10;
                $deudor = ["nombre" => $linea[0], "dni" => $linea[1], "telefono" => $numero10, "deuda" => str_replace(',', '.', $linea[3])];
            }
            $lineas[] = $linea;
        }
        fclose($fp);
        $fp = fopen("deudores.csv", "w");
        foreach ($lineas as $l) {
            fputcsv($fp, $l, ';');
        }
        fclose($fp);

        if ($deudor) {
            $nombre = ucfirst(strtolower($deudor["nombre"]));
            $monto = $deudor["deuda"];
            $saludo = horaSaludo();
            $respuesta = "$saludo $nombre. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá.";
            registrarVisita($senderCompleto);
        } else {
            $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podés verificar si está bien escrito?";
        }
    } else {
        $respuesta = "No se encuentra el archivo de deudores.";
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

// Guardar historial
file_put_contents("historial.txt", date("Y-m-d H:i") . " | $senderCompleto => $message\n", FILE_APPEND);

// Respuesta final
echo json_encode(["reply" => $respuesta]);
exit;
?>
