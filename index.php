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
        ["Le pedimos ingresar saldo hoy mismo", "Desde su app de Ualá"],
        ["Evite nuevas gestiones", "Ingrese un monto en Ualá cuanto antes"],
        ["La deuda sigue vigente", "Regularícela desde su cuenta Ualá"],
        ["Por favor transfiera hoy mismo", "Desde Ualá para evitar acciones"],
        ["Le recordamos que puede resolver", "Ingresando dinero en Ualá"],
        ["Cumpla hoy con el ingreso pendiente", "En la app de Ualá"],
        ["Para evitar consecuencias", "Haga una carga en su app Ualá"],
        ["Resuelva esto hoy", "Ingresando lo que pueda en su cuenta"],
        ["El expediente sigue activo", "Le sugerimos transferir hoy mismo"],
        ["Se requiere un ingreso inmediato", "Desde su app de Ualá"],
        ["No demore más", "Transfiera desde Ualá cuanto antes"]
    ];
    return $r[array_rand($r)];
}

function respuestaGracias() {
    $r = [
        ["De nada", "Estamos para ayudarte"],
        ["Un placer ayudarte", "Cualquier cosa, escribinos"],
        ["Con gusto", "Lo que necesites, consultanos"],
        ["Siempre a disposición", "Gracias por tu mensaje"],
        ["Un gusto poder colaborar", "Estamos a disposición"]
    ];
    return $r[array_rand($r)];
}

function respuestaNoCuotas() {
    $r = [
        ["No trabajamos con planes", "Puede ingresar lo que pueda hoy desde Ualá"],
        ["No manejamos acuerdos ni cuotas", "El ingreso debe hacerse en la app"],
        ["No ofrecemos cuotas", "Le sugerimos hacer el esfuerzo hoy mismo desde Ualá"]
    ];
    return $r[array_rand($r)];
}

function respuestaSinTrabajo() {
    return ["Entendemos que esté sin trabajo", "Haga el esfuerzo de ingresar lo que pueda hoy desde Ualá"];
}

function respuestaProblemaApp() {
    return ["Si tiene problemas para acceder a la app de Ualá", "Comuníquese con soporte de Ualá"];
}

function respuestaSaludoInicial($nombre, $monto) {
    $saludo = saludoHora();
    return [
        "$saludo $nombre",
        "Soy Rodrigo, abogado del Estudio Cuervo Abogados",
        "Le informamos que mantiene un saldo pendiente de \$$monto",
        "Ingrese saldo desde su app de Ualá para resolverlo"
    ];
}

// Procesamiento
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
