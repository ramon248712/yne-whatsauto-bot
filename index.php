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
                return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
            }
        }
    }
    fclose($fp);
    return null;
}

function urgenciaAleatoria() {
    $r = [
        "Le pedimos ingresar saldo hoy mismo desde su app de Ualá",
        "Evite nuevas gestiones, ingrese un monto en Ualá cuanto antes",
        "La deuda sigue vigente, regularícela desde su cuenta Ualá",
        "Por favor, transfiera hoy mismo desde Ualá para evitar acciones",
        "Le recordamos que puede resolver ingresando dinero en Ualá",
        "Cumpla hoy con el ingreso pendiente en la app de Ualá",
        "Para evitar consecuencias, haga una carga en su app Ualá",
        "Resuelva esto hoy ingresando lo que pueda en su cuenta",
        "El expediente sigue activo, le sugerimos transferir hoy mismo",
        "Se requiere un ingreso inmediato desde su app de Ualá",
        "No demore más, transfiera desde Ualá cuanto antes",
        "Cierre esta gestión hoy mismo desde su cuenta Ualá",
        "Con un ingreso hoy en Ualá, evita nuevas derivaciones",
        "Sugerimos resolver esto ahora desde la app de Ualá",
        "Debe ingresar saldo hoy para evitar pasos legales",
        "El estudio le solicita una transferencia desde la app de Ualá",
        "Hoy puede ser el último aviso, regularice en la app",
        "Con una carga hoy desde Ualá, evita complicaciones",
        "Aún está a tiempo de resolver, transfiera desde Ualá",
        "No espere más, ingrese lo que pueda hoy mismo",
        "El saldo sigue estando pendiente, transfiera en la app",
        "Le pedimos prioridad con esta gestión. Ingrese hoy el saldo en la app",
        "Transfiera el saldo a su cuenta de Ualá para resolverlo",
        "Resolver esto depende de usted. Ingrese el saldo en la app",
        "Es urgente ingresar saldo hoy en Ualá"
    ];
    return $r[array_rand($r)];
}

function respuestaGracias() {
    $r = ["De nada, estamos para ayudarte", "Un placer ayudarte", "Con gusto",
          "Siempre a disposición", "Gracias a vos por comunicarte", "Estamos para ayudarte",
          "Un gusto poder colaborar", "Cualquier cosa, escribinos", "Lo que necesites, consultanos"];
    return $r[array_rand($r)];
}

function respuestaNoCuotas() {
    $r = ["Entendemos que esté complicado. No trabajamos con planes, pero puede ingresar lo que pueda hoy desde Ualá",
          "Le informamos que no manejamos acuerdos ni cuotas. El ingreso debe hacerse en la app",
          "No ofrecemos cuotas. Le sugerimos hacer el esfuerzo hoy mismo desde Ualá",
          "Para resolverlo, debe ingresar saldo desde su app. Incluso un monto parcial ayuda",
          "Gracias por consultar. No hacemos acuerdos de pago, el ingreso es directo desde la app de Ualá"];
    return $r[array_rand($r)];
}

function respuestaSinTrabajo() {
    return "Entendemos que esté sin trabajo. Le pedimos que igual haga el esfuerzo de ingresar lo que pueda hoy desde Ualá";
}

function respuestaProblemaApp() {
    return "Si tiene problemas para acceder a la app de Ualá, comuníquese con soporte de Ualá";
}

// Procesamiento
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

        $nombre = ucfirst(strtolower($encontrado["nombre"]));
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \${$encontrado["deuda"]}. Ingrese saldo desde su app de Ualá para resolverlo";
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
        $respuesta = respuestaGracias();

    } elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinanciación", "plan", "acuerdo"])) {
        $respuesta = respuestaNoCuotas();

    } elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "desempleado", "desocupado"])) {
        $respuesta = respuestaSinTrabajo();

    } elseif (contiene($message, ["no anda la app", "no puedo entrar", "uala no funciona", "no puedo ingresar", "uala no me deja", "uala no abre", "uala no carga"])) {
        $respuesta = respuestaProblemaApp();

    } elseif (contiene($message, ["ya pague", "pague", "saldada", "no debo", "no devo"])) {
        $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app de Ualá";

    } elseif ($deudor) {
        $nombre = ucfirst(strtolower($deudor["nombre"]));
        $monto = $deudor["deuda"];
        if (!yaSaludoHoy($telefonoConPrefijo)) {
            $saludo = saludoHora();
            $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo";
            registrarVisita($telefonoConPrefijo);
        } else {
            $respuesta = urgenciaAleatoria();
        }

    } elseif (empty($message) || strlen(trim(preg_replace('/[^a-z0-9áéíóúñ ]/i', '', $message))) < 3) {
        $respuesta = urgenciaAleatoria();

    } else {
        $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
    }
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
