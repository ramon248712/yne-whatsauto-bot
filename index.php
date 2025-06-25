<?php
// Configuración general
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

if (strlen($message) < 2 || preg_match('/^[^a-zA-Z0-9]+$/', $message)) exit(json_encode(["reply" => ""]));

function saludoHora() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12) return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
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

function buscarDeudorPorTelefono($tel) {
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

function buscarDeudorPorDNI($dni) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    $lines = [];
    $encontrado = null;
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4) {
            if (trim($line[1]) == $dni) {
                $line[2] = "+549" . substr($GLOBALS['sender'], -10);
                $encontrado = ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
            }
            $lines[] = $line;
        }
    }
    fclose($fp);
    if ($encontrado) {
        $fp = fopen("deudores.csv", "w");
        foreach ($lines as $linea) fputcsv($fp, $linea);
        fclose($fp);

        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["asociar", $encontrado["telefono"], $encontrado["dni"]]);
        fclose($fp);
    }
    return $encontrado;
}

$deudor = buscarDeudorPorTelefono($telefonoConPrefijo);
$hoy = date("Y-m-d");

$respuesta = "";

if ($deudor) {
    $yaSaludoHoy = false;
    if (file_exists("visitas.csv")) {
        foreach (file("visitas.csv") as $linea) {
            [$tel, $fecha] = str_getcsv($linea);
            if ($tel === $telefonoConPrefijo && $fecha === $hoy) {
                $yaSaludoHoy = true;
                break;
            }
        }
    }
    if (!$yaSaludoHoy) {
        $saludo = saludoHora();
        $respuesta = "$saludo {$deudor['nombre']}. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \${$deudor['deuda']}. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Le recordamos que debe ingresar saldo hoy mismo en la app de Ualá para resolver su situación.";
    }
} elseif (preg_match('/\b\d{7,9}\b/', $message, $coincide)) {
    $dni = $coincide[0];
    $deudor = buscarDeudorPorDNI($dni);
    if ($deudor) {
        $saludo = saludoHora();
        $respuesta = "$saludo {$deudor['nombre']}. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \${$deudor['deuda']}. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podés verificar si está bien escrito?";
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
