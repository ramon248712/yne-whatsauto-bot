<?php
// Configuración general
date_default_timezone_set("America/Argentina/Buenos_Aires");
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

// Captura de datos
$app = $_POST["app"] ?? "";
$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));
$telefono = "+549" . substr($sender, -10);
$hoy = date("Y-m-d");

// Funciones auxiliares
function saludoHora() {
    $h = (int)date("H");
    return ($h < 12) ? "Buen día" : (($h < 19) ? "Buenas tardes" : "Buenas noches");
}

function contiene($msg, $palabras) {
    foreach ($palabras as $p) {
        if (strpos($msg, $p) !== false) return true;
    }
    return false;
}

function registrarVisita($telefono) {
    $visitas = [];
    if (file_exists("visitas.csv")) {
        foreach (file("visitas.csv") as $l) {
            [$tel, $fecha] = str_getcsv($l);
            $visitas[$tel] = $fecha;
        }
    }
    $visitas[$telefono] = date("Y-m-d");
    $fp = fopen("visitas.csv", "w");
    foreach ($visitas as $tel => $fecha) fputcsv($fp, [$tel, $fecha]);
    fclose($fp);
}

function yaSaludoHoy($telefono) {
    if (!file_exists("visitas.csv")) return false;
    foreach (file("visitas.csv") as $linea) {
        [$tel, $fecha] = str_getcsv($linea);
        if ($tel === $telefono && $fecha === date("Y-m-d")) return true;
    }
    return false;
}

function buscarDeudorPorTelefono($telefono) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    while (($line = fgetcsv($fp, 0, ";")) !== false) {
        if (count($line) >= 4 && substr(preg_replace('/\D/', '', $line[2]), -10) === substr($telefono, -10)) {
            fclose($fp);
            return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
        }
    }
    fclose($fp);
    return null;
}

function urgenciaAleatoria() {
    $mensajes = [
        "LE RECORDAMOS INGRESAR SALDO HOY MISMO EN UALA PARA EVITAR GESTIONES.",
        "CUMPLA CON EL INGRESO DE SALDO EN LA APP PARA EVITAR CONSECUENCIAS.",
        "SE REQUIERE INGRESO DE SALDO URGENTE EN UALA.",
        "EVITE COMPLICACIONES: INGRESE DINERO EN UALA HOY.",
        "CARGUE SALDO EN SU CUENTA DE UALA PARA SALDAR LA DEUDA.",
        "REALICE LA TRANSFERENCIA EN SU APP DE UALA A LA BREVEDAD.",
        "RECUERDE ABONAR INGRESANDO SALDO EN UALA.",
        "EVITE PROBLEMAS FUTUROS CARGANDO FONDOS HOY MISMO.",
        "REGULARICE LA DEUDA INGRESANDO SALDO EN UALA YA.",
        "LA SITUACIÓN SIGUE ACTIVA: INGRESE HOY DESDE LA APP."
    ];
    return $mensajes[array_rand($mensajes)];
}

// --- Procesamiento ---
$respuesta = "";
$deudor = buscarDeudorPorTelefono($telefono);

if (contiene($message, ["equivocado", "numero equivocado"])) {
    $fp = fopen("modificaciones.csv", "a");
    fputcsv($fp, ["eliminar", $telefono]);
    fclose($fp);
    $respuesta = "Entendido. Eliminamos tu número de nuestra base de gestión.";
}
elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = "De nada, estamos para ayudarte.";
}
elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
    $respuesta = "No trabajamos con cuotas, debe ingresar saldo en su app de Ualá.";
}
elseif (contiene($message, ["ya pague", "pague", "saldad", "no debo", "no devo"])) {
    $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo que figura en la app de Ualá.";
}
elif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    if (!yaSaludoHoy($telefono)) {
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefono);
    } else {
        $respuesta = urgenciaAleatoria();
    }
}
elseif (preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
    $dni = $coinc[0];
    $lineas = file("deudores.csv");
    $encontrado = null;
    $nuevas = [];

    foreach ($lineas as $l) {
        $cols = str_getcsv($l, ";");
        if (count($cols) >= 4) {
            if (trim($cols[1]) == $dni) {
                $cols[2] = $telefono;
                $encontrado = ["nombre" => $cols[0], "deuda" => $cols[3]];
            }
        }
        $nuevas[] = $cols;
    }

    if ($encontrado) {
        $fp = fopen("deudores.csv", "w");
        foreach ($nuevas as $n) fputcsv($fp, $n, ";");
        fclose($fp);

        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["asociar", $telefono, $dni]);
        fclose($fp);

        $saludo = saludoHora();
        $nombre = ucfirst(strtolower($encontrado["nombre"]));
        $monto = $encontrado["deuda"];
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefono);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

// Historial
file_put_contents("historial.txt", date("Y-m-d H:i") . " | $telefono => $message\n", FILE_APPEND);

// Respuesta final
echo json_encode(["reply" => $respuesta]);
exit;
?>
