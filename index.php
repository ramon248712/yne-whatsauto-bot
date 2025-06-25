<?php
// Configuración general
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Datos del POST
$app = $_POST["app"] ?? "";
$sender = $_POST["sender"] ?? "";
$message = strtolower($_POST["message"] ?? "");
$sender = preg_replace('/\D/', '', $sender);

// Normalización del número a formato CSV (solo los 10 dígitos finales)
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

if (strlen($telefonoBase) != 10) exit(json_encode(["reply" => ""]));

// Saludo según la hora
function saludoHora() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12) return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
}

// Cargar visitas
$visitas = [];
if (file_exists("visitas.csv")) {
    foreach (file("visitas.csv") as $linea) {
        [$tel, $fecha] = str_getcsv($linea);
        $visitas[$tel] = $fecha;
    }
}

// Registrar visita
function registrarVisita($telefono) {
    global $visitas;
    $visitas[$telefono] = date("Y-m-d");
    $fp = fopen("visitas.csv", "w");
    foreach ($visitas as $t => $f) fputcsv($fp, [$t, $f]);
    fclose($fp);
}

// Ejecutivos formales
$ejecutivos = ["Julieta", "Francisco", "Camila", "Agustina", "Valentina", "Donato", "Milagros", "Lucia", "Santiago", "Ana", "Matias"];

// Buscar deudor
function buscarDeudor($tel) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4 && substr(preg_replace('/\D/', '', $line[2]), -10) === substr($tel, -10)) {
            fclose($fp);
            return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
        }
    }
    fclose($fp);
    return null;
}

// Detección de mensajes
function contiene($msg, $palabras) {
    foreach ($palabras as $p) {
        if (strpos($msg, $p) !== false) return true;
    }
    return false;
}

// Respuestas
function respuestaGracias() {
    $r = ["De nada, estamos para ayudarte.", "Un placer ayudarte.", "Con gusto.",
          "Siempre a disposición.", "Gracias a vos por comunicarte.", "Estamos para ayudarte.",
          "Un gusto poder colaborar.", "Cualquier cosa, escribinos.", "Lo que necesites, consultanos."];
    return $r[array_rand($r)];
}

function respuestaNoCuotas() {
    $r = ["Entendemos que esté complicado. No trabajamos con planes, pero puede ingresar lo que pueda hoy desde Ualá.",
          "Le informamos que no manejamos acuerdos ni cuotas. El ingreso debe hacerse en la app.",
          "No ofrecemos cuotas. Le sugerimos hacer el esfuerzo hoy mismo desde Ualá.",
          "Para resolverlo, debe ingresar saldo desde su app. Incluso un monto parcial ayuda.",
          "Gracias por consultar. No hacemos acuerdos de pago, el ingreso es directo desde la app de Ualá."];
    return $r[array_rand($r)];
}

function respuestaYaPago() {
    return "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app de Ualá.";
}

function respuestaSinTrabajo() {
    return "Entendemos que esté sin trabajo. Le pedimos que igual haga el esfuerzo de ingresar lo que pueda hoy desde Ualá.";
}

function respuestaProblemaApp() {
    return "Si tiene problemas para acceder a la app de Ualá, comuníquese con su soporte. El ingreso debe hacerse desde allí.";
}

function respuestaUrgente() {
    $r = [
        "Le recordamos que debe ingresar saldo en Ualá hoy mismo para evitar acciones.",
        "Cumplimos en informarle que su situación sigue activa. Ingrese saldo desde su app.",
        "Por favor, regularice hoy ingresando fondos en la app de Ualá.",
        "Evite complicaciones: transfiera desde Ualá a su CVU hoy mismo.",
        "Se solicita el ingreso de saldo hoy en Ualá para evitar nuevas gestiones."
    ];
    return $r[array_rand($r)];
}

// --- Lógica principal ---
$deudor = buscarDeudor($telefonoConPrefijo);
$hoy = date("Y-m-d");
$respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";

if (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = respuestaGracias();
} elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
    $respuesta = respuestaNoCuotas();
} elseif (contiene($message, ["ya pague", "pague", "pagé", "saldad", "no debo", "no devo"])) {
    $respuesta = respuestaYaPago();
} elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "desempleado", "desocupado"])) {
    $respuesta = respuestaSinTrabajo();
} elseif (contiene($message, ["no anda la app", "no puedo entrar", "uala no funciona", "no puedo ingresar"])) {
    $respuesta = respuestaProblemaApp();
} elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    $yaSaludoHoy = isset($visitas[$telefonoConPrefijo]) && $visitas[$telefonoConPrefijo] === $hoy;
    if (!$yaSaludoHoy) {
        $saludo = saludoHora();
        $ejecutivo = $ejecutivos[array_rand($ejecutivos)];
        $respuesta = "$saludo $nombre. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá.\n\n$ejecutivo – Ejecutivo de Cuentas, Cuervo Abogados";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = respuestaUrgente();
    }
} elseif (preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
    $dni = $coinc[0];
    if (file_exists("deudores.csv")) {
        $fp = fopen("deudores.csv", "r+");
        $lines = [];
        $encontrado = null;
        while (($line = fgetcsv($fp)) !== false) {
            if (count($line) >= 4 && trim($line[1]) == $dni) {
                $line[2] = $telefonoConPrefijo;
                $encontrado = ["nombre" => $line[0], "deuda" => $line[3]];
            }
            $lines[] = $line;
        }
        fclose($fp);
        $fp = fopen("deudores.csv", "w");
        foreach ($lines as $l) fputcsv($fp, $l);
        fclose($fp);
        if ($encontrado) {
            $nombre = ucfirst(strtolower($encontrado["nombre"]));
            $saludo = saludoHora();
            $monto = $encontrado["deuda"];
            $ejecutivo = $ejecutivos[array_rand($ejecutivos)];
            $respuesta = "$saludo $nombre. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá.\n\n$ejecutivo – Ejecutivo de Cuentas, Cuervo Abogados";
            registrarVisita($telefonoConPrefijo);
        } else {
            $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
        }
    }
}

// Historial
file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);

// Enviar respuesta
echo json_encode(["reply" => $respuesta]);
exit;
?>
