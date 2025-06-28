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

// Normalizar número
if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

// Funciones auxiliares
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
                return [
                    "nombre" => $line[0],
                    "dni" => $line[1],
                    "telefono" => $line[2],
                    "deuda" => $line[3],
                    "ejecutivo" => $line[4] ?? ""
                ];
            }
        }
    }
    fclose($fp);
    return null;
}

function elegirFrase($telefono, $clave, $frases) {
    $archivo = "frases_usadas.csv";
    $usos = [];

    if (file_exists($archivo)) {
        foreach (file($archivo, FILE_IGNORE_NEW_LINES) as $linea) {
            list($tel, $tipo, $lista) = array_pad(explode("|", $linea), 3, '');
            $usos[$tel][$tipo] = $lista === '' ? [] : explode(",", $lista);
        }
    }

    $usadas = $usos[$telefono][$clave] ?? [];
    $todos = range(0, count($frases) - 1);
    $disponibles = array_diff($todos, $usadas);
    if (empty($disponibles)) {
        $usadas = [];
        $disponibles = $todos;
    }

    $indice = array_values($disponibles)[array_rand($disponibles)];
    $usadas[] = $indice;
    $usos[$telefono][$clave] = $usadas;

    $fp = fopen($archivo, "w");
    foreach ($usos as $tel => $tipos) {
        foreach ($tipos as $tipo => $lista) {
            fwrite($fp, "$tel|$tipo|" . implode(",", $lista) . "\n");
        }
    }
    fclose($fp);

    return $frases[$indice];
}

function respuestaInicialPersonalizada($telefono, $nombre, $monto) {
    $frases = [
        "$nombre, ¿cómo estás? Respecto a la deuda devengada de \$$monto con Ualá, estás a tiempo de abonarla desde la app. – Rodrigo",
        "Te escribo por el saldo pendiente de \$$monto registrado en tu cuenta Ualá. Podés cancelarlo directamente desde la app. – Rodrigo",
        "Le informamos que mantiene un saldo impago de \$$monto en Ualá. Le recomendamos regularizarlo desde la aplicación cuanto antes. – Rodrigo",
        "Se detecta una deuda activa de \$$monto con Ualá. Recordá que podés saldarla cargando saldo en la app. – Rodrigo"
    ];
    $saludo = saludoHora();
    return "$saludo " . elegirFrase($telefono, "inicial", $frases);
}

function reenviarViaWhatAuto($destino, $mensaje) {
    $url = "http://192.168.0.101:3000/send-message";
    $data = ["number" => $destino, "message" => $mensaje];

    $options = [
        "http" => [
            "header"  => "Content-type: application/json",
            "method"  => "POST",
            "content" => json_encode($data)
        ]
    ];
    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
}

// Procesamiento
$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);

if ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];

    if (!yaSaludoHoy($telefonoConPrefijo)) {
        $respuesta = respuestaInicialPersonalizada($telefonoConPrefijo, $nombre, $monto);
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "LE RECORDAMOS INGRESAR SALDO HOY EN LA APP DE UALA";
    }

    if (strtolower($deudor["ejecutivo"] ?? '') === "rgonzalez") {
        $mensajeParaEjecutivo = "📩 Nuevo mensaje de {$deudor['nombre']} (DNI {$deudor['dni']}) – Tel: {$telefonoConPrefijo}:\n\n\"$message\"";
        reenviarViaWhatAuto("2615871377", $mensajeParaEjecutivo);
        $respuesta .= "\n\nLo estará contactando el ejecutivo a cargo desde el número 2615871377.";
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
