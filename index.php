<?php
// Configuración general
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Datos del POST
$app = $_POST["app"] ?? "";
$sender = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));

// Validar número
if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

// Funciones generales
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
    while (($line = fgetcsv($fp)) !== false) {
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
    $mensajes = [
        "LE RECORDAMOS INGRESAR SALDO HOY MISMO EN UALA PARA EVITAR GESTIONES.",
        "LE INFORMAMOS QUE SU DEUDA SIGUE ACTIVA, REGULARICE HOY EN UALA.",
        "CUMPLA CON EL INGRESO DE SALDO EN LA APP PARA EVITAR CONSECUENCIAS.",
        "SE REQUIERE INGRESO DE SALDO URGENTE EN UALA.",
        "EVITE COMPLICACIONES: INGRESE DINERO EN UALA HOY.",
        "CARGUE SALDO EN SU CUENTA DE UALA PARA SALDAR LA DEUDA.",
        "REALICE LA TRANSFERENCIA EN SU APP DE UALA A LA BREVEDAD.",
        "RECUERDE ABONAR INGRESANDO SALDO EN UALA.",
        "EVITE PROBLEMAS FUTUROS CARGANDO FONDOS HOY MISMO.",
        "REGULARICE LA DEUDA INGRESANDO SALDO EN UALA YA.",
        "INGRESE CUALQUIER MONTO DISPONIBLE DESDE UALA HOY.",
        "LE SOLICITAMOS EL PAGO INGRESANDO SALDO EN SU APP DE UALA.",
        "CUMPLA HOY CON EL INGRESO DE FONDOS EN UALA.",
        "NO DEMORE MÁS, INGRESE SALDO EN UALA CUANTO ANTES.",
        "RESUELVA HOY CARGANDO DINERO EN UALA.",
        "SEÑOR/A: INGRESE SALDO EN UALA PARA EVITAR NUEVAS GESTIONES.",
        "EVITE INCONVENIENTES CUMPLIENDO CON EL INGRESO EN UALA.",
        "REALICE EL DEPÓSITO HOY EN UALA PARA EVITAR SEGUIMIENTOS.",
        "LA DEUDA SIGUE ACTIVA, INGRESE SALDO PARA RESOLVER.",
        "HAGA EL INGRESO EN SU APP DE UALA PARA EVITAR ACCIONES.",
        "CARGUE DINERO EN UALA PARA CERRAR EL CASO.",
        "REGULARICE HOY INGRESANDO EN UALA, EVITE ESCALAMIENTOS.",
        "NO POSTERGUE MÁS, INGRESE LO QUE PUEDA DESDE UALA.",
        "MANTÉNGASE AL DÍA CARGANDO FONDOS HOY MISMO.",
        "LE INFORMAMOS QUE AÚN NO INGRESÓ SALDO EN UALA.",
        "APORTE HOY DESDE UALA PARA EVITAR SEGUIMIENTO LEGAL.",
        "ESTAMOS ESPERANDO SU PAGO EN UALA.",
        "SE LE SOLICITA CUMPLIMIENTO HOY MISMO EN UALA.",
        "LE PEDIMOS REGULARICE SU SITUACIÓN CON UN INGRESO.",
        "RECUERDE REALIZAR LA CARGA EN UALA PARA EVITAR MÁS CONTACTOS.",
        "PARA EVITAR GESTIONES ADICIONALES, ABONE HOY EN UALA.",
        "TRANSFIERA HOY EN SU CUENTA DE UALA PARA FINALIZAR.",
        "SEGUIMOS A LA ESPERA DE SU INGRESO DESDE UALA.",
        "NO SE REGISTRA EL INGRESO, REALÍCELO HOY DESDE UALA.",
        "LE INFORMAMOS QUE CONTINÚA LA DEUDA: REGULARICE EN UALA.",
        "LE RECOMENDAMOS INGRESAR SALDO HOY DESDE SU APP.",
        "EVITE CONTACTOS FUTUROS INGRESANDO SALDO EN UALA.",
        "NO OLVIDE CUMPLIR CON LA OBLIGACIÓN HOY MISMO.",
        "SU SITUACIÓN SIGUE ACTIVA, INGRESE SALDO DESDE LA APP.",
        "NECESITAMOS VER UN MOVIMIENTO HOY DESDE UALA.",
        "REGULARICE A LA BREVEDAD INGRESANDO SALDO EN UALA.",
        "POR FAVOR, INGRESE SALDO HOY MISMO EN UALA.",
        "SEGUIMOS ESPERANDO EL INGRESO DESDE SU APP.",
        "CUMPLO EN RECORDARLE EL INGRESO PENDIENTE EN UALA.",
        "LE SOLICITAMOS LO QUE PUEDA INGRESAR HOY MISMO.",
        "REGULARICE SU SITUACIÓN DESDE LA APP DE UALA.",
        "SU ESTADO SIGUE ACTIVO: INGRESE SALDO HOY.",
        "ABONE HOY LO QUE PUEDA DESDE SU APP DE UALA.",
        "LO QUE PUEDA INGRESAR SERÁ TENIDO EN CUENTA.",
        "LE RECOMENDAMOS NO DEMORAR MÁS EL INGRESO.",
    ];
    return $mensajes[array_rand($mensajes)];
}

// --- Procesamiento del mensaje ---
$respuesta = "";
$deudor = buscarDeudor($telefonoConPrefijo);

// Excepciones
if (contiene($message, ["equivocado", "numero equivocado"])) {
    $fp = fopen("modificaciones.csv", "a");
    fputcsv($fp, ["eliminar", $telefonoConPrefijo]);
    fclose($fp);
    $respuesta = "Entendido. Eliminamos tu número de nuestra base de gestión.";
}
elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = "De nada, estamos para ayudarte.";
}
elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "refinansiar", "plan", "acuerdo"])) {
    $respuesta = "No trabajamos con cuotas, debe ingresar saldo en su app de Ualá.";
}
elseif (contiene($message, ["ya pague", "pague", "saldad", "no debo", "no devo"])) {
    $respuesta = "En las próximas horas actualizaremos nuestros registros. Guíese por el saldo en la app de Ualá.";
}
elseif ($deudor) {
    $nombre = ucfirst(strtolower($deudor["nombre"]));
    $monto = $deudor["deuda"];
    if (!yaSaludoHoy($telefonoConPrefijo)) {
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \$$monto. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = urgenciaAleatoria();
    }
}
elseif (preg_match('/\b\d{7,9}\b/', $message, $coinc)) {
    $dni = $coinc[0];
    $fp = fopen("deudores.csv", "r");
    $lineas = [];
    $encontrado = null;

    while (($line = fgetcsv($fp)) !== false) {
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
        foreach ($lineas as $l) fputcsv($fp, $l);
        fclose($fp);

        $fp = fopen("modificaciones.csv", "a");
        fputcsv($fp, ["asociar", $telefonoConPrefijo, $dni]);
        fclose($fp);

        $saludo = saludoHora();
        $nombre = ucfirst(strtolower($encontrado["nombre"]));
        $respuesta = "$saludo $nombre. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \${$encontrado["deuda"]}. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
}
else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

// Guardar historial
file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);

// Respuesta final
echo json_encode(["reply" => $respuesta]);
exit;
?>
