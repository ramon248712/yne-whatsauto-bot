<?php
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));
$telefonoConPrefijo = "+549" . substr($sender, -10);

function buscarDeudor($telefono) {
    if (!file_exists("deudores.csv")) return null;
    $telBase = substr(preg_replace('/\D/', '', $telefono), -10);
    $fp = fopen("deudores.csv", "r");
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
    $result = @file_get_contents($url, false, $context);
    file_put_contents("whatauto_log.txt", json_encode([
        "fecha" => date("Y-m-d H:i"),
        "to" => $destino,
        "mensaje" => $mensaje,
        "resultado" => $result,
        "error" => error_get_last()
    ]) . "\n", FILE_APPEND);
}

$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);

if ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $respuesta = "Lo estará contactando el ejecutivo a cargo desde el número 2615871377.";

    if (strtolower($deudor["ejecutivo"]) === "rgonzalez") {
        $mensajeParaEjecutivo = "📩 Nuevo mensaje de {$deudor['nombre']} (DNI {$deudor['dni']}) – Tel: {$telefonoConPrefijo}:\n\n\"$message\"";
        file_put_contents("debug_log.txt", "REENVIO A R.GONZALEZ: $mensajeParaEjecutivo\n", FILE_APPEND);
        reenviarViaWhatAuto("2615871377", $mensajeParaEjecutivo);
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

file_put_contents("debug_log.txt", "RESPUESTA: $respuesta\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
