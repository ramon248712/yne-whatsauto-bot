<?php
// Configuración
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Entradas
$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

// CSV: Buscar deudor
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
                    "ejecutivo" => trim($line[4] ?? "")
                ];
            }
        }
    }
    fclose($fp);
    return null;
}

// CURL: Enviar mensaje a WhatAuto
function reenviarViaWhatAuto($numeroDestino, $mensaje) {
    $url = "http://192.168.0.100:3000/send-message";
    $payload = json_encode([
        "number" => $numeroDestino,
        "message" => $mensaje
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    file_put_contents("whatauto_log.txt", date("Y-m-d H:i") . " → Enviado a $numeroDestino\nMensaje: $mensaje\nResultado: $result\nError: $error\n\n", FILE_APPEND);
    return $result;
}

// Procesar mensaje
$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);

if ($deudor) {
    $respuesta = "Lo estará contactando el ejecutivo a cargo desde el número 2615871377.";

    if (strtolower($deudor["ejecutivo"]) === "rgonzalez") {
        // Enviar mensaje al ejecutivo
        $mensaje = "📩 Nuevo mensaje de {$deudor['nombre']} (DNI {$deudor['dni']}) – Tel: {$telefonoConPrefijo}:\n\n\"$message\"";
        reenviarViaWhatAuto("+5492615871377", $mensaje);  // CORRECTO: con +549
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

echo json_encode(["reply" => $respuesta]);
exit;
