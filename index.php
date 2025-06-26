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

if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

// Utilidades
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

function cargarFrases($archivo) {
    return array_map("trim", file($archivo));
}

function getFraseUnica($telefono, $archivoFrases, $archivoUsos) {
    $frases = cargarFrases($archivoFrases);
    $usadas = file_exists($archivoUsos) ? json_decode(file_get_contents($archivoUsos), true) : [];

    if (!isset($usadas[$telefono])) $usadas[$telefono] = [];
    $pendientes = array_diff($frases, $usadas[$telefono]);

    if (empty($pendientes)) {
        $usadas[$telefono] = [];
        $pendientes = $frases;
    }

    $frase = $pendientes[array_rand($pendientes)];
    $usadas[$telefono][] = $frase;
    file_put_contents($archivoUsos, json_encode($usadas));

    return $frase;
}

function getFrasePersonalizada($telefono, $nombre, $monto) {
    $frase = getFraseUnica($telefono, "frases_iniciales.txt", "uso_frases.json");
    return strtr($frase, [
        "{saludo}" => saludoHora(),
        "{nombre}" => ucfirst(strtolower($nombre)),
        "{monto}" => "$" . $monto
    ]);
}

function getFraseUrgencia($telefono) {
    return getFraseUnica($telefono, "frases_urgencia.txt", "uso_urgencia.json");
}

function respuestaPorCategoria($categoria) {
    $respuestas = [
        "gracias" => ["De nada, estamos para ayudarte", "Un placer ayudarte", "Con gusto", "Siempre a disposición", "Gracias a vos por comunicarte", "Estamos para ayudarte", "Un gusto poder colaborar", "Cualquier cosa, escribinos", "Lo que necesites, consultanos"],
        "cuotas" => ["Entendemos que esté complicado. No trabajamos con planes, pero puede ingresar lo que pueda hoy desde Ualá", "Le informamos que no manejamos acuerdos ni cuotas. El ingreso debe hacerse en la app", "No ofrecemos cuotas. Le sugerimos hacer el esfuerzo hoy mismo desde Ualá", "Para resolverlo, debe ingresar saldo desde su app. Incluso un monto parcial ayuda", "Gracias por consultar. No hacemos acuerdos de pago, el ingreso es directo desde la app de Ualá"],
        "sintrabajo" => ["Entendemos que esté sin trabajo. Le pedimos que igual haga el esfuerzo de ingresar lo que pueda hoy desde Ualá", "Sabemos que la situación puede ser difícil, pero necesitamos que ingrese un monto hoy desde la app de Ualá", "Aunque esté sin trabajo, le pedimos que realice una carga mínima en su cuenta Ualá para evitar gestiones"],
        "problemaapp" => ["Si tiene problemas para acceder a la app de Ualá, comuníquese con soporte de Ualá", "Para problemas con la app, le recomendamos contactar al soporte de Ualá directamente", "Le sugerimos reiniciar la app o comunicarse con soporte de Ualá si persiste el problema"]
    ];
    return $respuestas[$categoria][array_rand($respuestas[$categoria])];
}

// Procesamiento principal
$respuesta = "";

if (preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
    $dni = $coinc[0];
    $fp = fopen("deudores.csv", "r");
    $lineas = [];
    $encontrado = null;

    while (($line = fgetcsv($fp, 0, ";")) !== false) {
        if (count($line) >= 4) {
            if (trim($line[1]) == $dni) {
                $line[2] = $telefonoConPrefijo;
                $encontrado = ["nombre" => $line[0], "dni" => $line[1], "deuda" => $line[3]];
            }
            $lineas[] = $line;
        }
    }
    fclose($fp);

    if ($encontrado) {
        $fp = fopen("deudores.csv", "w");
        foreach ($lineas as $l) fputcsv($fp, $l, ";");
        fclose($fp);

        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["asociar", $telefonoConPrefijo, $dni]);
        fclose($fp);

        $respuesta = getFrasePersonalizada($telefonoConPrefijo, $encontrado["nombre"], $encontrado["deuda"]);
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
} else {
    $deudor = buscarDeudor($telefonoConPrefijo);
    if (contiene($message, ["equivocado", "no soy", "falleció", "fallecido", "murió", "número equivocado"])) {
        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["eliminar", $telefonoConPrefijo]);
        fclose($fp);
        $respuesta = "Ok, disculpe";
    } elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
        $respuesta = respuestaPorCategoria("gracias");
    } elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinanciación", "plan", "acuerdo"])) {
        $respuesta = respuestaPorCategoria("cuotas");
    } elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "sin empleo", "desempleado", "desocupado"])) {
        $respuesta = respuestaPorCategoria("sintrabajo");
    } elseif (contiene($message, ["no anda la app", "no puedo entrar", "uala no funciona", "no puedo ingresar", "uala no me deja", "uala no abre", "uala no carga"])) {
        $respuesta = respuestaPorCategoria("problemaapp");
    } elseif (contiene($message, ["ya pague", "pague", "saldada", "no debo", "no devo"])) {
        $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app de Ualá";
    } elseif ($deudor) {
        if (!yaSaludoHoy($telefonoConPrefijo)) {
            $respuesta = getFrasePersonalizada($telefonoConPrefijo, $deudor["nombre"], $deudor["deuda"]);
            registrarVisita($telefonoConPrefijo);
        } else {
            $respuesta = getFraseUrgencia($telefonoConPrefijo);
        }
    } elseif (empty($message) || strlen(trim(preg_replace('/[^a-z0-9áéíóúñ ]/i', '', $message))) < 3) {
        $respuesta = getFraseUrgencia($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
    }
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
