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

// Normalización del número
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;
if (strlen($telefonoBase) != 10) exit(json_encode(["reply" => ""]));

// Validación básica del mensaje
if (strlen($message) < 3 || preg_match('/^[^a-zA-Z0-9]+$/', $message)) exit(json_encode(["reply" => ""]));

function saludoHora() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12) return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
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
        foreach (file("visitas.csv") as $linea) {
            [$tel, $fecha] = str_getcsv($linea);
            $visitas[$tel] = $fecha;
        }
    }
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

$deudor = buscarDeudor($telefonoConPrefijo);
$hoy = date("Y-m-d");
$respuesta = "";

if (contiene($message, ["equivocado", "número equivocado", "numero equivocado"])) {
    $fp = fopen("modificaciones.csv", "a");
    fputcsv($fp, ["eliminar", $telefonoConPrefijo]);
    fclose($fp);
    echo json_encode(["reply" => "Entendido. Eliminamos tu número de nuestra base de gestión."]);
    exit;
} elseif (contiene($message, ["gracias", "gracia", "graciah"])) {
    $opciones = [
        "De nada, estamos para ayudarte.", "Un placer ayudarte.", "Con gusto.", "Siempre a disposición.", "Gracias a vos por comunicarte.",
        "Estamos para ayudarte.", "Un gusto poder colaborar.", "Cualquier cosa, escribinos.", "Para eso estamos.", "Lo que necesites, consultanos.",
        "No hay de qué.", "A disposición siempre.", "Quedamos atentos.", "Nos alegra ayudarte."
    ];
    $respuesta = $opciones[array_rand($opciones)];
} elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
    $opciones = [
        "No trabajamos con cuotas, debe ingresar saldo en su app de Ualá.",
        "No manejamos planes de pago. Se requiere que ingrese fondos en Ualá.",
        "Le informamos que no ofrecemos cuotas. Debe ingresar saldo en su cuenta.",
        "No realizamos refinanciaciones, solo se requiere que transfiera a su CVU.",
        "Cumplimos en informarle que no hacemos planes. Ingrese lo que pueda en su cuenta Ualá."
    ];
    $respuesta = $opciones[array_rand($opciones)];
} elseif (contiene($message, ["ya pague", "pague", "pagé", "saldad", "no debo", "no devo"])) {
    $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app de Ualá.";
} elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "desempleado", "desocupado"])) {
    $respuesta = "Entendemos que esté sin trabajo. Le pedimos que igual haga el esfuerzo de ingresar lo que pueda hoy desde Ualá.";
} elseif (contiene($message, ["uala no funciona", "no puedo ingresar", "uala no me deja", "uala no abre", "uala no carga", "problema con uala", "no anda la app", "no puedo entrar"])) {
    $respuesta = "Si tiene problemas para acceder a la app de Ualá, comuníquese con su soporte. El ingreso debe hacerse desde allí.";
} elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    $yaSaludoHoy = file_exists("visitas.csv") && strpos(file_get_contents("visitas.csv"), $telefonoConPrefijo) !== false;
    if (!$yaSaludoHoy) {
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $urgencias = [
            "Le informamos que debe ingresar saldo en la app de Ualá para evitar acciones por falta de cumplimiento.",
            "Le recordamos que debe ingresar dinero en su cuenta de Ualá para evitar complicaciones.",
            "Cumplimos en informarle que es necesario ingresar fondos en su app de Ualá.",
            "Para evitar consecuencias por incumplimiento, ingrese saldo en su app de Ualá cuanto antes.",
            "Debe ingresar fondos en Ualá para evitar acciones por falta de pago."
        ];
        $respuesta = $urgencias[array_rand($urgencias)];
    }
} elseif (preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
    $dni = $coinc[0];
    $fp = fopen("deudores.csv", "r");
    $lines = [];
    $encontrado = null;
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4 && trim($line[1]) == $dni) {
            $line[2] = $telefonoConPrefijo;
            $encontrado = ["nombre" => $line[0], "deuda" => $line[3], "dni" => $line[1]];
        }
        $lines[] = $line;
    }
    fclose($fp);
    if ($encontrado) {
        $fp = fopen("deudores.csv", "w");
        foreach ($lines as $l) fputcsv($fp, $l);
        fclose($fp);
        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["asociar", $telefonoConPrefijo, $dni]);
        fclose($fp);
        $nombre = ucfirst(strtolower($encontrado["nombre"]));
        $saludo = saludoHora();
        $monto = $encontrado["deuda"];
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
