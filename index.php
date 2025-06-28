<?php
// Configuración general
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Debug inicial
file_put_contents("debug_log.txt", "ENTRÓ AL SCRIPT: " . date("Y-m-d H:i") . "\n", FILE_APPEND);

// Capturar datos
$app = $_POST["app"] ?? "";
$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));
file_put_contents("debug_log.txt", "SENDER: $sender | MESSAGE: $message\n", FILE_APPEND);

if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

// Función para buscar deudor en CSV
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
                    "nombre"    => $line[0],
                    "dni"       => $line[1],
                    "telefono"  => $line[2],
                    "deuda"     => $line[3],
                    "ejecutivo" => $line[4] ?? ""
                ];
            }
        }
    }
    fclose($fp);
    return null;
}

// Función para reenviar mensaje por WhatAuto
function reenviarViaWhatAuto($destino, $mensaje) {
    $url = "http://192.168.0.100:3000/send-message"; // IP del celular con WhatAuto

    $data = ["number" => $destino, "message" => $mensaje];

    $options = [
        "http" => [
            "header"  => "Content-type: application/json",
            "method"  => "POST",
            "content" => json_encode($data),
            "timeout" => 5
        ]
    ];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    file_put_contents("whatauto_log.txt", json_encode([
        "fecha"    => date("Y-m-d H:i"),
        "to"       => $destino,
        "mensaje"  => $mensaje,
        "resultado" => $result,
        "error"     => error_get_last()
    ]) . "\n", FILE_APPEND);

    return $result;
}

// Procesar
$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);
file_put_contents("debug_log.txt", "DEUDOR: " . print_r($deudor, true) . "\n", FILE_APPEND);

if ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $respuesta = "Lo estará contactando el ejecutivo a cargo desde el número 2615871377.";

    if (strtolower(trim($deudor["ejecutivo"])) === "rgonzalez") {
        $mensajeParaEjecutivo = "📩 Nuevo mensaje de {$deudor['nombre']} (DNI {$deudor['dni']}) – Tel: {$telefonoConPrefijo}:\n\n\"$message\"";
        reenviarViaWhatAuto("2615871377", $mensajeParaEjecutivo);
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

// Registrar en historial
file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
