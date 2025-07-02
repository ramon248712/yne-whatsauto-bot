<?php
// Configuración general
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Capturar datos
$app = $_POST["app"] ?? "";
$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));

if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

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

function buscarDeudor($telefono) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    $telBase = substr(preg_replace('/\D/', '', $telefono), -10);
    while (($line = fgetcsv($fp, 0, ";")) !== false) {
        if (count($line) >= 4) {
            $telCsv = substr(preg_replace('/\D/', '', $line[2]), -10);
            if ($telCsv === $telBase) {
                fclose($fp);
                return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
            }
        }
    }
    fclose($fp);
    return null;
}

function cargarFrases($archivo) {
    return file_exists($archivo) ? array_map("trim", file($archivo)) : [];
}

function getFrasePersonalizada($telefono, $nombre, $monto) {
    $archivo = "uso_frases.json";
    $frases = cargarFrases("frases_iniciales.txt");
    $usadas = file_exists($archivo) ? json_decode(file_get_contents($archivo), true) : [];

    if (!isset($usadas[$telefono])) $usadas[$telefono] = [];
    $pendientes = array_diff($frases, $usadas[$telefono]);

    if (empty($pendientes)) {
        $usadas[$telefono] = [];
        $pendientes = $frases;
    }

    $frase = $pendientes[array_rand($pendientes)];
    $usadas[$telefono][] = $frase;
    file_put_contents($archivo, json_encode($usadas));

    return strtr($frase, [
        "{saludo}" => saludoHora(),
        "{nombre}" => mb_convert_case($nombre, MB_CASE_TITLE, "UTF-8"),
        "{monto}" => "$" . $monto
    ]);
}

function respuestaPorCategoria($categoria) {
    $archivo = "frases_{$categoria}.txt";
    $frases = cargarFrases($archivo);
    return $frases ? $frases[array_rand($frases)] : "";
}

$respuesta = "";

if (preg_match('/\b\d{1,2}\.?\d{3}\.?\d{3}\b/', $message, $coinc)) {
    $dni = preg_replace('/\D/', '', $coinc[0]);  // elimina puntos
    $fp = fopen("deudores.csv", "r");
    $lineas = [];
    $encontrado = null;

    while (($line = fgetcsv($fp, 0, ";")) !== false) {
        if (count($line) >= 4) {
            if (trim($line[1]) == $dni) {
                $line[2] = $telefonoConPrefijo;
                $encontrado = ["nombre" => $line[0], "dni" => $line[1], "deuda" => $line[3]];
            }
            $lineas[] = $line;
        }
    }
    fclose($fp);

    if ($encontrado) {
        $fp = fopen("deudores.csv", "w");
        foreach ($lineas as $l) fputcsv($fp, $l, ";");
        fclose($fp);

        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["asociar", $telefonoConPrefijo, $dni]);
        fclose($fp);

        $respuesta = getFrasePersonalizada($telefonoConPrefijo, $encontrado["nombre"], $encontrado["deuda"]);
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Disculpe, no encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
} else {
    $deudor = buscarDeudor($telefonoConPrefijo);
    if (contiene($message, ["equivocado", "no soy", "falleció", "fallecido", "murió", "número equivocado"])) {
        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["eliminar", $telefonoConPrefijo]);
        fclose($fp);
        $respuesta = "Ok, disculpe";
    } elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
        $respuesta = respuestaPorCategoria("gracias");
    } elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinanciación", "plan", "acuerdo"])) {
        $respuesta = respuestaPorCategoria("cuotas");
    } elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "sin empleo", "desempleado", "desocupado"])) {
        $respuesta = respuestaPorCategoria("sintrabajo");
    } elseif (contiene($message, ["no anda la app", "no puedo entrar", "no funciona", "no puedo ingresar", "no me deja", "no abre", "no carga"])) {
        $respuesta = respuestaPorCategoria("problemaapp");
    } elseif (contiene($message, ["pague", "saldada", "no debo", "ingresé", "pagué", "no devo"])) {
        $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app";
    } elseif ($deudor) {
        if (!yaSaludoHoy($telefonoConPrefijo)) {
            $respuesta = getFrasePersonalizada($telefonoConPrefijo, $deudor["nombre"], $deudor["deuda"]);
            registrarVisita($telefonoConPrefijo);
        } else {
            $respuesta = respuestaPorCategoria("urgencia");
        }
    } else {
        $contenidoLimpio = trim(preg_replace('/[^a-z0-9áéíóúñ ]/i', '', $message));
        if (empty($message) || strlen($contenidoLimpio) < 3) {
            $respuesta = respuestaPorCategoria("urgencia");
        } else {
            $respuesta = "Hola, podrías indicarnos tu DNI (sin puntos) para identificarte? ";
        }
    }
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
