<?php 
ini_set('display_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

$app = $_POST["app"] ?? "";
$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));

$telefonoBase = substr($sender, -10);
if (strlen($telefonoBase) != 10) exit(json_encode(["reply" => ""]));
$telefonoConPrefijo = "+549" . $telefonoBase;

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

function normalizarTelefono($telCrudo) {
    $tel = preg_replace('/\D/', '', $telCrudo);
    return "+549" . substr($tel, -10);
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
    while (($line = fgetcsv($fp, 0, ";")) !== false) {
        if (count($line) >= 4) {
            $telefonoCSV = normalizarTelefono($line[2]);
            if (substr($telefonoCSV, -10) === substr($tel, -10)) {
                fclose($fp);
                return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $telefonoCSV, "deuda" => $line[3]];
            }
        }
    }
    fclose($fp);
    return null;
}

$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);

if (contiene($message, ["equivocado", "número equivocado", "numero equivocado"])) {
    registrarVisita($telefonoConPrefijo);
    echo json_encode(["reply" => "Entendido. Eliminamos tu número de nuestra base de gestión."]);
    exit;
} elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = "Gracias a vos por comunicarte. Estamos para ayudarte.";
} elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    $saludo = saludoHora();
    $respuesta = "$saludo $nombre, soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá. Estamos a disposición por cualquier duda.";
    registrarVisita($telefonoConPrefijo);
} elseif (preg_match('/\b(\d{1,2}\.?\d{3}\.?\d{3})\b|\b\d{7,9}\b/', $message, $coinc)) {
    $dni = preg_replace('/\D/', '', $coinc[0]);
    $deudaEncontrada = null;
    $lineas = [];
    if (file_exists("deudores.csv")) {
        $fp = fopen("deudores.csv", "r");
        while (($line = fgetcsv($fp, 0, ";")) !== false) {
            if (count($line) >= 4) {
                if (trim($line[1]) == $dni) {
                    $line[2] = $telefonoConPrefijo;
                    $deudaEncontrada = ["nombre" => $line[0], "deuda" => $line[3]];
                }
                $lineas[] = $line;
            }
        }
        fclose($fp);
    }
    if ($deudaEncontrada) {
        $fp = fopen("deudores.csv", "w");
        foreach ($lineas as $l) fputcsv($fp, $l, ";");
        fclose($fp);
        $nombre = ucfirst(strtolower($deudaEncontrada["nombre"]));
        $saludo = saludoHora();
        $monto = $deudaEncontrada["deuda"];
        $respuesta = "$saludo $nombre, soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Por favor, regularice ingresando saldo en la app de Ualá. Estamos a disposición por cualquier duda.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
