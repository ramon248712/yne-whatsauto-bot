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
        "por favor ingresá saldo en la app de ualá hoy mismo para evitar complicaciones",
        "recordá regularizar tu situación ingresando fondos desde ualá",
        "te pedimos que transfieras hoy mismo desde tu cuenta de ualá",
        "es importante que ingreses un monto hoy mismo desde ualá",
        "cualquier ingreso desde ualá ayuda a reducir tu deuda",
        "tu situación sigue activa, te sugerimos ingresar dinero cuanto antes",
        "evitá gestiones adicionales ingresando saldo en ualá",
        "no postergues más, ingresá lo que puedas desde la app de ualá",
        "cumplí con el ingreso desde ualá para evitar más avisos",
        "regularizá tu saldo cuanto antes desde la app",
        "recordá que el ingreso se hace por la app de ualá a tu cvu",
        "te sugerimos ingresar un monto hoy mismo desde ualá",
        "el saldo sigue pendiente, resolvelo desde ualá",
        "sumá saldo hoy desde ualá y evitá consecuencias",
        "te recordamos ingresar cualquier monto hoy para reducir tu deuda",
        "una transferencia en ualá ayuda a resolver esto cuanto antes",
        "el saldo sigue sin regularizar, por favor ingresá desde ualá",
        "te recomendamos hacer un esfuerzo y cargar dinero hoy",
        "ingresá dinero hoy desde ualá para frenar nuevas gestiones",
        "tu caso sigue activo, resolvelo ingresando saldo",
        "no dejes pasar el día, ingresá fondos desde ualá",
        "cualquier monto ayuda, hacelo desde tu cuenta en ualá",
        "evitá más contactos ingresando saldo hoy mismo",
        "regularizá cuanto antes desde tu app de ualá",
        "cumplí hoy con una transferencia desde ualá",
        "abonar es simple, hacelo por ualá desde tu celular",
        "la deuda sigue vigente, ingresá un monto desde ualá",
        "es importante resolver esto hoy desde ualá",
        "por favor realizá la transferencia hoy desde tu cuenta",
        "todo se resuelve ingresando dinero hoy mismo",
        "sumá saldo y quedás al día con el estudio",
        "no ignores este mensaje, hacé la carga desde ualá",
        "tu cvu está esperando una carga desde ualá",
        "hoy es un buen día para ponerte al día desde ualá",
        "cargá saldo cuanto antes para evitar nuevos avisos",
        "es simple: ualá, tu cuenta, tu solución",
        "no hay excusas, resolvelo desde ualá hoy",
        "cumplí con lo pendiente cargando saldo",
        "no ignores esta oportunidad de resolverlo",
        "la deuda sigue vigente, podés empezar con poco",
        "te invitamos a resolverlo ingresando saldo",
        "tu saldo pendiente puede resolverse en minutos",
        "una acción hoy evita problemas mañana",
        "podés resolverlo sin contacto humano: usá ualá",
        "sabemos que podés, hacé la carga hoy",
        "cumplí con el ingreso hoy desde la app",
        "tu compromiso es importante, cargá saldo",
        "estás a un paso de resolverlo con ualá",
        "ingresá un monto hoy y bajá el saldo",
        "resolvé ya desde tu app de ualá"
    ];
    return $r[array_rand($r)];
}

// Procesamiento
$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);

if (contiene($message, ["equivocado", "numero equivocado"])) {
    $fp = fopen("modificaciones.csv", "a");
    fputcsv($fp, ["eliminar", $telefonoConPrefijo]);
    fclose($fp);
    $respuesta = "Entendido. Eliminamos tu número de nuestra base de gestión.";

} elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = "De nada, estamos para ayudarte.";

} elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
    $respuesta = "No trabajamos con cuotas, debe ingresar saldo en su app de Ualá.";

} elseif (contiene($message, ["ya pague", "pague", "saldad", "no debo", "no devo"])) {
    $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app de Ualá.";

} elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    if (!yaSaludoHoy($telefonoConPrefijo)) {
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = urgenciaAleatoria();
    }

} elseif (preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
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
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \${$encontrado["deuda"]}. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }

} else {
    // Si el mensaje es vacío o parece multimedia (sin texto útil), responder con urgencia
    if (empty($message) || strlen(trim(preg_replace('/[^a-z0-9áéíóúñ ]/i', '', $message))) < 3) {
        $respuesta = urgenciaAleatoria();
    } else {
        $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
    }
}


file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
