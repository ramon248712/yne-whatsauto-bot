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
$message = trim($_POST["message"] ?? "");

// Normalizar número
if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

// Buscar deudor por teléfono
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

// Reenvía el mensaje vía WhatAuto
function reenviarViaWhatAuto($destino, $mensaje) {
    $url = "http://localhost:3000/send-message";
    $data = ["number" => $destino, "message" => $mensaje];

    $options = [
        "http" => [
            "header"  => "Content-type: application/json",
            "method"  => "POST",
            "content" => json_encode($data)
        ]
    ];
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// Procesamiento
$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);

if ($deudor && strtolower($deudor["ejecutivo"]) === "rgonzalez") {
    $mensajeParaEjecutivo = "📩 Nuevo mensaje de {$deudor['nombre']} (DNI {$deudor['dni']}) – Tel: {$telefonoConPrefijo}:\n\n\"$message\"";
    reenviarViaWhatAuto("5492615871377", $mensajeParaEjecutivo);
    $respuesta = "Lo estará contactando el ejecutivo a cargo desde el número 5492615871377.";
} else {
    $respuesta = "Gracias por tu mensaje. Será derivado al equipo correspondiente.";
}

// Log y respuesta
file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
