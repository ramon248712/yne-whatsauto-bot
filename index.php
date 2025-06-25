<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

$app = $_POST["app"] ?? "";
$sender = $_POST["sender"] ?? "";
$message = strtolower(trim($_POST["message"] ?? ""));
$sender = preg_replace('/\D/', '', $sender);
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;
if (strlen($telefonoBase) != 10) exit(json_encode(["reply" => ""]));

if (strlen($message) < 3 || preg_match('/^[^a-zA-Z0-9]+$/', $message)) {
    echo json_encode(["reply" => ""]);
    exit;
}

function saludoHora() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12) return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
}

function buscarDeudorPorTelefono($telefono) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4 && substr(preg_replace('/\D/', '', $line[2]), -10) === substr($telefono, -10)) {
            fclose($fp);
            return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
        }
    }
    fclose($fp);
    return null;
}

function buscarDeudorPorDNI($dni) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4 && trim($line[1]) == $dni) {
            fclose($fp);
            return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
        }
    }
    fclose($fp);
    return null;
}

function actualizarTelefonoPorDNI($dni, $telefono) {
    $archivo = file("deudores.csv");
    $nuevo = [];
    foreach ($archivo as $linea) {
        $cols = str_getcsv($linea);
        if (count($cols) >= 4 && trim($cols[1]) == $dni) {
            $cols[2] = $telefono;
        }
        $nuevo[] = implode(",", $cols);
    }
    file_put_contents("deudores.csv", implode("\n", $nuevo));
}

function contiene($msg, $palabras) {
    foreach ($palabras as $p) {
        if (strpos($msg, $p) !== false) return true;
    }
    return false;
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

function respuestaGracias() {
    $r = ["De nada, estamos para ayudarte.", "Un placer ayudarte.", "Con gusto.",
          "Siempre a disposición.", "Gracias a vos por comunicarte.", "Estamos para ayudarte.",
          "Un gusto poder colaborar.", "Cualquier cosa, escribinos.", "Lo que necesites, consultanos."];
    return $r[array_rand($r)];
}

function registrarVisita($telefono) {
    $visitas = [];
    if (file_exists("visitas.csv")) {
        foreach (file("visitas.csv") as $linea) {
            [$t, $f] = str_getcsv($linea);
            $visitas[$t] = $f;
        }
    }
    $visitas[$telefono] = date("Y-m-d");
    $fp = fopen("visitas.csv", "w");
    foreach ($visitas as $tel => $fecha) {
        fputcsv($fp, [$tel, $fecha]);
    }
    fclose($fp);
}

function yaRecibioSaludoHoy($telefono) {
    if (!file_exists("visitas.csv")) return false;
    foreach (file("visitas.csv") as $linea) {
        [$tel, $fecha] = str_getcsv($linea);
        if ($tel === $telefono && $fecha === date("Y-m-d")) return true;
    }
    return false;
}

$respuesta = "";
$deudor = buscarDeudorPorTelefono($telefonoConPrefijo);

// --- Mensajes especiales ---
if (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = respuestaGracias();
} elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
    $respuesta = "No hacemos cuotas ni acuerdos. El ingreso debe hacerse completo por Ualá.";
} elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "desempleado", "desocupado"])) {
    $respuesta = "Entendemos su situación. Le pedimos que haga el esfuerzo de ingresar lo que pueda hoy desde Ualá.";
} elseif (contiene($message, ["uala no", "no puedo", "no abre", "uala no carga", "uala no funciona"])) {
    $respuesta = "Si tiene problemas con la app de Ualá, por favor contacte con su soporte. El ingreso debe hacerse desde allí.";
}

// --- Si ya está identificado ---
elseif ($deudor) {
    if (!yaRecibioSaludoHoy($telefonoConPrefijo)) {
        $saludo = saludoHora();
        $nombre = ucfirst(strtolower($deudor["nombre"]));
        $monto = $deudor["deuda"];
        $respuesta = "$saludo $nombre. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá.\n\nSoy Rodrigo, abogado del Estudio Cuervo Abogados.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = respuestaUrgente();
    }
}

// --- Si manda DNI ---
elseif (preg_match('/\b\d{7,9}\b/', $message, $coincide)) {
    $dni = $coincide[0];
    $deudorPorDNI = buscarDeudorPorDNI($dni);
    if ($deudorPorDNI) {
        actualizarTelefonoPorDNI($dni, $telefonoConPrefijo);
        $nombre = ucfirst(strtolower($deudorPorDNI["nombre"]));
        $monto = $deudorPorDNI["deuda"];
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá.\n\nSoy Rodrigo, abogado del Estudio Cuervo Abogados.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
}

// --- Si no se identifica aún ---
else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $telefonoConPrefijo => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
