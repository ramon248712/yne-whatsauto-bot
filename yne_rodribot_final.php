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
$message = strtolower(trim($_POST["message"] ?? ""));
$sender = preg_replace('/\D/', '', $sender);
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

if (strlen($telefonoBase) != 10) exit(json_encode(["reply" => ""]));

// Saludo según hora
function saludoHora() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12) return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
}

// Lectura de archivos
$visitas = [];
if (file_exists("visitas.csv")) {
    foreach (file("visitas.csv") as $linea) {
        [$tel, $fecha] = str_getcsv($linea);
        $visitas[$tel] = $fecha;
    }
}

function registrarVisita($telefono) {
    global $visitas;
    $visitas[$telefono] = date("Y-m-d");
    $fp = fopen("visitas.csv", "w");
    foreach ($visitas as $t => $f) fputcsv($fp, [$t, $f]);
    fclose($fp);
}

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

function contiene($msg, $palabras) {
    foreach ($palabras as $p) {
        if (strpos($msg, $p) !== false) return true;
    }
    return false;
}

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

// Lógica principal
$deudor = buscarDeudor($telefonoConPrefijo);
$hoy = date("Y-m-d");
$respuesta = "";

if ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    $yaSaludoHoy = isset($visitas[$telefonoConPrefijo]) && $visitas[$telefonoConPrefijo] === $hoy;

    if (!$yaSaludoHoy) {
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá.";
        registrarVisita($telefonoConPrefijo);
    } elseif (contiene($message, ["quiero"])) {
        $respuesta = "¿Con cuánto podrías comprometerte hoy para comenzar a saldar la deuda?";
    } elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
        $respuesta = respuestaGracias();
    } elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
        $respuesta = respuestaNoCuotas();
    } elseif (contiene($message, ["ya pague", "pague", "pagé", "saldad", "no debo", "no devo"])) {
        $respuesta = respuestaYaPago();
    } elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "desempleado", "desocupado"])) {
        $respuesta = respuestaSinTrabajo();
    } elseif (contiene($message, ["no anda la app", "no puedo entrar", "uala no funciona", "no puedo ingresar", "uala no me deja", "uala no abre", "uala no carga"])) {
        $respuesta = respuestaProblemaApp();
    } else {
        $respuesta = respuestaUrgente();
    }
} else {
    $respuesta = "Hola. Para ayudarte necesito que me indiques tu número de DNI.";
}

echo json_encode(["reply" => $respuesta]);
exit;
?>
