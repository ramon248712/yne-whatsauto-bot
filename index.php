<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

$app = $_POST["app"] ?? "";
$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));
$telefono = "+549" . substr($sender, -10);
$hoy = date("Y-m-d");

if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));

function saludoHora() {
    $h = date("H");
    if ($h >= 6 && $h < 12) return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
}

// VISITAS
$visitas = [];
if (file_exists("visitas.csv")) {
    foreach (file("visitas.csv") as $l) {
        [$t, $f] = str_getcsv($l);
        $visitas[$t] = $f;
    }
}
function registrarVisita($tel) {
    global $visitas;
    $visitas[$tel] = date("Y-m-d");
    $fp = fopen("visitas.csv", "w");
    foreach ($visitas as $k => $v) fputcsv($fp, [$k, $v]);
    fclose($fp);
}

function contiene($m, $pals) {
    foreach ($pals as $p) if (strpos($m, $p) !== false) return true;
    return false;
}

function buscarDeudor($tel) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    while ($l = fgetcsv($fp, 0, ";")) {
        if (count($l) >= 4 && substr(preg_replace("/\D/", "", $l[2]), -10) === substr($tel, -10)) {
            fclose($fp);
            return ["nombre" => $l[0], "dni" => $l[1], "telefono" => $l[2], "deuda" => $l[3]];
        }
    }
    fclose($fp);
    return null;
}

function actualizarTelefonoPorDNI($dni, $nuevoTel) {
    $encontrado = false;
    $lineas = [];
    if (!file_exists("deudores.csv")) return false;
    $fp = fopen("deudores.csv", "r");
    while ($l = fgetcsv($fp, 0, ";")) {
        if ($l[1] === $dni) {
            $l[2] = $nuevoTel;
            $encontrado = $l;
        }
        $lineas[] = $l;
    }
    fclose($fp);
    if ($encontrado) {
        $fp = fopen("deudores.csv", "w");
        foreach ($lineas as $l) fputcsv($fp, $l, ";");
        fclose($fp);
    }
    return $encontrado;
}

function respuestaUrgente() {
    $r = [
        "Cumplimos en informarle que su situación sigue activa. Ingrese saldo desde su app.",
        "Le recordamos que debe ingresar saldo hoy mismo en Ualá para regularizar.",
        "Por favor, ingrese saldo desde la app de Ualá para evitar más gestiones.",
        "Evite complicaciones: transfiera desde Ualá a su CVU hoy mismo.",
        "Su deuda sigue activa. Le pedimos que ingrese lo antes posible desde la app."
    ];
    return $r[array_rand($r)];
}

$respuesta = "";

// PEDIDO DE DNI SI NO SE LO CONOCE
$deudor = buscarDeudor($telefono);
if (!$deudor && !preg_match('/\b\d{7,9}\b/', $message)) {
    echo json_encode(["reply" => "Hola. ¿Podrías indicarnos tu DNI para identificarte?"]);
    exit;
}

// SI MANDA DNI, LO CRUZAMOS Y ACTUALIZAMOS
if (!$deudor && preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
    $dni = $coinc[0];
    $nuevo = actualizarTelefonoPorDNI($dni, $telefono);
    if ($nuevo) {
        $nombre = ucfirst(strtolower($nuevo[0]));
        $monto = $nuevo[3];
        $saludo = saludoHora();
        registrarVisita($telefono);
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podés verificar si está bien escrito?";
    }
}
// SI YA ESTÁ IDENTIFICADO
elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    $saludo = saludoHora();
    $yaSaludoHoy = isset($visitas[$telefono]) && $visitas[$telefono] === $hoy;
    if (!$yaSaludoHoy) {
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefono);
    } else {
        $respuesta = respuestaUrgente();
    }
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $telefono => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
