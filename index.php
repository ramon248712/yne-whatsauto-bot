<?php
// Configuración general
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Datos del POST
$app = $_POST["app"] ?? "";
$sender = $_POST["sender"] ?? "";
$message = strtolower(trim($_POST["message"] ?? ""));
$sender = preg_replace('/\D/', '', $sender);

// Normalización del número
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;
if (strlen($telefonoBase) != 10) exit(json_encode(["reply" => ""]));

// Validación básica del mensaje
if (strlen($message) < 3 || preg_match('/^[^a-zA-Z0-9]+$/', $message)) exit(json_encode(["reply" => ""]));

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
    foreach ($visitas as $t => $f) fputcsv($fp, [$t, $f]);
    fclose($fp);
}

function buscarDeudor($tel) {
    $telFinal = substr(preg_replace('/\D/', '', $tel), -10);
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4) {
            $lineTel = substr(preg_replace('/\D/', '', $line[2]), -10);
            if ($lineTel === $telFinal) {
                fclose($fp);
                return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
            }
        }
    }
    fclose($fp);
    return null;
}

function urgenciaAleatoria() {
    $urgencias = [
        "Por favor, ingrese saldo hoy mismo desde su app de Ualá para evitar futuras complicaciones.",
        "Le recordamos que debe regularizar su situación cargando saldo en Ualá cuanto antes.",
        "Evite problemas mayores ingresando cualquier monto en su cuenta de Ualá.",
        "Es fundamental que transfiera fondos a su CVU hoy desde Ualá.",
        "No demore más: ingrese dinero en Ualá para resolver su situación.",
        "Se requiere un ingreso inmediato en su cuenta Ualá para evitar nuevas gestiones.",
        "Cumplimos en recordarle que debe cargar saldo hoy desde la app.",
        "Su deuda continúa activa. Regularícela ingresando saldo desde Ualá.",
        "Es urgente que ingrese saldo en Ualá hoy mismo.",
        "Le solicitamos que realice una transferencia a su CVU desde Ualá cuanto antes.",
        "Le pedimos colaboración ingresando cualquier monto desde su app.",
        "Por favor, evite complicaciones futuras cargando saldo hoy en Ualá.",
        "La situación requiere un ingreso inmediato desde Ualá.",
        "Evite gestiones adicionales con un simple ingreso en su app.",
        "Transfiera desde su app de Ualá para resolver su deuda.",
        "Aún puede evitar derivaciones: cargue saldo hoy desde Ualá.",
        "Saldar la deuda es fácil: transfiera a su CVU desde la app.",
        "Hágalo hoy: cargue su cuenta desde la app de Ualá.",
        "Regularice su situación cuanto antes ingresando saldo.",
        "Aún puede solucionarlo ingresando fondos en su app.",
        "Recomendamos no demorar más y transferir desde su cuenta.",
        "Un ingreso hoy evita gestiones innecesarias.",
        "Actúe ahora: transfiera desde Ualá.",
        "Debe regularizar el saldo hoy para evitar nuevas acciones.",
        "Por favor, transfiera a su cuenta desde la app.",
        "No postergue más: ingrese dinero desde Ualá.",
        "Recuerde que el ingreso debe hacerse desde su app.",
        "Evite que avancemos con nuevas gestiones. Ingrese saldo.",
        "Debe cumplir hoy con la carga de saldo en su app.",
        "Transferir desde Ualá resuelve la situación.",
        "Le recomendamos ingresar aunque sea un monto parcial.",
        "La deuda puede saldarse con un solo ingreso en Ualá.",
        "Su colaboración hoy evitará futuras gestiones.",
        "No deje pasar más tiempo. Regularice la situación desde la app.",
        "Evite reportes: transfiera hoy a su CVU.",
        "Su deuda sigue activa. Ingrese saldo desde Ualá.",
        "Es necesario cargar fondos en la app para resolver la deuda.",
        "La solución está a su alcance: transfiera desde su app.",
        "No es necesario pagar todo, pero sí mostrar voluntad.",
        "Le pedimos que hoy mismo ingrese lo que pueda desde Ualá.",
        "Evite derivaciones innecesarias con un ingreso desde la app.",
        "Un solo paso: transfiera desde su app y resuelva.",
        "Por favor, tome acción hoy ingresando saldo desde Ualá.",
        "Su cuenta sigue en gestión. Transfiera cuanto antes.",
        "No demore: con un ingreso parcial puede detener las gestiones.",
        "Cargar saldo desde la app es todo lo que hace falta.",
        "Evite consecuencias cargando dinero hoy mismo.",
        "Estamos esperando su ingreso en Ualá para cerrar la gestión.",
        "Le pedimos compromiso. Transfiera hoy desde Ualá."
    ];
    return $urgencias[array_rand($urgencias)];
}

$deudor = buscarDeudor($telefonoConPrefijo);
$hoy = date("Y-m-d");
$respuesta = "";

if (contiene($message, ["equivocado", "número equivocado", "numero equivocado"])) {
    $fp = fopen("modificaciones.csv", "a");
    fputcsv($fp, ["eliminar", $telefonoConPrefijo]);
    fclose($fp);
    echo json_encode(["reply" => "Entendido. Eliminamos tu número de nuestra base de gestión."]);
    exit;
} elseif (contiene($message, ["gracias", "gracia", "graciah"])) {
    $opciones = [
        "De nada, estamos para ayudarte.", "Un placer ayudarte.", "Con gusto.", "Siempre a disposición.", "Gracias a vos por comunicarte.",
        "Estamos para ayudarte.", "Un gusto poder colaborar.", "Cualquier cosa, escribinos.", "Para eso estamos.", "Lo que necesites, consultanos.",
        "No hay de qué.", "A disposición siempre.", "Quedamos atentos.", "Nos alegra ayudarte."
    ];
    $respuesta = $opciones[array_rand($opciones)];
} elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
    $opciones = [
        "No trabajamos con cuotas, debe ingresar saldo en su app de Ualá.",
        "No manejamos planes de pago. Se requiere que ingrese fondos en Ualá.",
        "Le informamos que no ofrecemos cuotas. Debe ingresar saldo en su cuenta.",
        "No realizamos refinanciaciones, solo se requiere que transfiera a su CVU.",
        "Cumplimos en informarle que no hacemos planes. Ingrese lo que pueda en su cuenta Ualá."
    ];
    $respuesta = $opciones[array_rand($opciones)];
} elseif (contiene($message, ["ya pague", "pague", "pagé", "saldad", "no debo", "no devo"])) {
    $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app de Ualá.";
} elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "desempleado", "desocupado"])) {
    $respuesta = "Entendemos que esté sin trabajo. Le pedimos que igual haga el esfuerzo de ingresar lo que pueda hoy desde Ualá.";
} elseif (contiene($message, ["uala no funciona", "no puedo ingresar", "uala no me deja", "uala no abre", "uala no carga", "problema con uala", "no anda la app", "no puedo entrar"])) {
    $respuesta = "Si tiene problemas para acceder a la app de Ualá, comuníquese con su soporte. El ingreso debe hacerse desde allí.";
} elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    $yaSaludoHoy = file_exists("visitas.csv") && strpos(file_get_contents("visitas.csv"), $telefonoConPrefijo) !== false;
    if (!$yaSaludoHoy) {
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = urgenciaAleatoria();
    }
} elseif (preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
    $dni = $coinc[0];
    $fp = fopen("deudores.csv", "r");
    $lines = [];
    $encontrado = null;
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4 && trim($line[1]) == $dni) {
            $line[2] = $telefonoConPrefijo;
            $encontrado = ["nombre" => $line[0], "deuda" => $line[3], "dni" => $line[1]];
        }
        $lines[] = $line;
    }
    fclose($fp);
    if ($encontrado) {
        $fp = fopen("deudores.csv", "w");
        foreach ($lines as $l) fputcsv($fp, $l);
        fclose($fp);
        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["asociar", $telefonoConPrefijo, $dni]);
        fclose($fp);
        $nombre = ucfirst(strtolower($encontrado["nombre"]));
        $saludo = saludoHora();
        $monto = $encontrado["deuda"];
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
} else {
    $respuesta = urgenciaAleatoria();
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
