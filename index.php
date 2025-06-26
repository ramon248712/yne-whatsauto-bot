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
if (strlen($sender) < 10) exit(json_encode(["reply" => []]));
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
                return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
            }
        }
    }
    fclose($fp);
    return null;
}

function urgenciaAleatoria() {
    $r = [
        ["LE PEDIMOS INGRESAR SALDO HOY MISMO", "DESDE SU APP DE UALA"],
        ["EVITE NUEVAS GESTIONES", "INGRESE UN MONTO EN UALA CUANTO ANTES"],
        ["LA DEUDA SIGUE VIGENTE", "REGULARICELA DESDE SU CUENTA UALA"],
        ["POR FAVOR TRANSFIERA HOY MISMO", "DESDE UALA PARA EVITAR ACCIONES"],
        ["LE RECORDAMOS QUE PUEDE RESOLVER", "INGRESANDO DINERO EN UALA"],
        ["CUMPLA HOY CON EL INGRESO PENDIENTE", "EN LA APP DE UALA"],
        ["PARA EVITAR CONSECUENCIAS", "HAGA UNA CARGA EN SU APP UALA"],
        ["RESUELVA ESTO HOY", "INGRESANDO LO QUE PUEDA EN SU CUENTA"],
        ["EL EXPEDIENTE SIGUE ACTIVO", "LE SUGERIMOS TRANSFERIR HOY MISMO"],
        ["SE REQUIERE UN INGRESO INMEDIATO", "DESDE SU APP DE UALA"],
        ["NO DEMORE MAS", "TRANSFIERA DESDE UALA CUANTO ANTES"]
    ];
    return $r[array_rand($r)];
}

function respuestaGracias() {
    $r = [
        ["DE NADA", "ESTAMOS PARA AYUDARTE"],
        ["UN PLACER AYUDARTE", "CUALQUIER COSA ESCRIBINOS"],
        ["CON GUSTO", "LO QUE NECESITES CONSULTANOS"],
        ["SIEMPRE A DISPOSICION", "GRACIAS POR TU MENSAJE"],
        ["UN GUSTO PODER COLABORAR", "ESTAMOS A DISPOSICIÓN"]
    ];
    return $r[array_rand($r)];
}

function respuestaNoCuotas() {
    $r = [
        ["NO TRABAJAMOS CON PLANES", "PUEDE INGRESAR LO QUE PUEDA HOY DESDE UALA"],
        ["NO MANEJAMOS ACUERDOS NI CUOTAS", "EL INGRESO DEBE HACERSE EN LA APP"],
        ["NO OFRECEMOS CUOTAS", "LE SUGERIMOS HACER EL ESFUERZO HOY MISMO DESDE UALA"]
    ];
    return $r[array_rand($r)];
}

function respuestaSinTrabajo() {
    return ["ENTENDEMOS QUE ESTE SIN TRABAJO", "HAGA EL ESFUERZO DE INGRESAR LO QUE PUEDA HOY DESDE UALA"];
}

function respuestaProblemaApp() {
    return ["SI TIENE PROBLEMAS PARA ACCEDER A LA APP DE UALA", "COMUNIQUESE CON SOPORTE DE UALA"];
}

function respuestaSaludoInicial($nombre, $monto) {
    $saludo = saludoHora();
    return [
        "$saludo $nombre. SOY RODRIGO ABOGADO DEL ESTUDIO CUERVO ABOGADOS",
        "LE INFORMAMOS QUE MANTIENE UN SALDO PENDIENTE DE \$$monto"
    ];
}

$respuesta = [];
$deudor = buscarDeudor($telefonoConPrefijo);

if (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = respuestaGracias();
} elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinanciación", "plan", "acuerdo"])) {
    $respuesta = respuestaNoCuotas();
} elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "sin empleo", "chagas", "desempleado", "desocupado"])) {
    $respuesta = respuestaSinTrabajo();
} elseif (contiene($message, ["no anda la app", "no puedo entrar", "uala no funciona", "no puedo ingresar", "uala no me deja", "uala no abre", "uala no carga"])) {
    $respuesta = respuestaProblemaApp();
} elseif ($deudor && !yaSaludoHoy($telefonoConPrefijo)) {
    $respuesta = respuestaSaludoInicial(ucfirst(strtolower($deudor["nombre"])), $deudor["deuda"]);
    registrarVisita($telefonoConPrefijo);
} else {
    $respuesta = urgenciaAleatoria();
}

echo json_encode(["reply" => $respuesta]);
exit;
