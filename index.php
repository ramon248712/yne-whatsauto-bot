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

function yaSaludoHoy($telefono) {
    if (!file_exists("visitas.csv")) return false;
    foreach (file("visitas.csv") as $linea) {
        [$tel, $fecha] = str_getcsv($linea);
        if ($tel === $telefono && $fecha === date("Y-m-d")) return true;
    }
    return false;
}

function buscarDeudor($tel) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    $telFinal = substr(preg_replace('/\D/', '', $tel), -10);
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4) {
            $telCSV = substr(preg_replace('/\D/', '', $line[2]), -10);
            if ($telCSV === $telFinal) {
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
        "LE RECORDAMOS QUE DEBE INGRESAR SALDO EN SU CUENTA DE UALA HOY MISMO",
        "INGRESE FONDOS EN LA APP DE UALA PARA EVITAR NUEVAS GESTIONES",
        "CUMPLIMOS EN INFORMARLE QUE DEBE REGULARIZAR SU DEUDA DESDE UALA",
        "PARA EVITAR COMPLICACIONES TRANSFIERA A SU CVU EN UALA",
        "LA SITUACIÓN SIGUE ACTIVA INGRESE SALDO EN SU CUENTA HOY",
        "INGRESE CUALQUIER MONTO DISPONIBLE DESDE LA APP DE UALA",
        "REGULARICE HOY MISMO MEDIANTE CARGA EN SU CUENTA UALA",
        "SE SOLICITA PAGO INMEDIATO A TRAVÉS DE LA APP DE UALA",
        "REALICE UNA TRANSFERENCIA DESDE SU APP PARA EVITAR GESTIONES",
        "LA DEUDA SIGUE PENDIENTE INGRESE SALDO DESDE UALA",
        "POR FAVOR CANCELE O INGRESE A CUENTA EN UALA",
        "LE INFORMAMOS QUE LA GESTIÓN SIGUE ACTIVA SIN PAGO REGISTRADO",
        "HAGA EL ESFUERZO HOY Y TRANSFIERA DESDE UALA",
        "SU SALDO SIGUE PENDIENTE INGRESE A UALA Y TRANSFIERA",
        "EVITE COMPLICACIONES ADICIONALES CARGANDO DINERO EN UALA",
        "ES NECESARIO QUE REGULARICE INGRESANDO SALDO HOY",
        "LA DEUDA SIGUE ABIERTA INGRESE CUALQUIER MONTO EN UALA",
        "TRANSFERENCIA URGENTE REQUERIDA DESDE SU APP DE UALA",
        "RECUERDE INGRESAR DINERO EN UALA PARA RESOLVER SU SITUACIÓN",
        "REALICE UN PAGO A CUENTA PARA EVITAR NUEVAS GESTIONES"
    ];
    return $urgencias[array_rand($urgencias)];
}

$deudor = buscarDeudor($telefonoConPrefijo);
$respuesta = "";

if (contiene($message, ["equivocado", "número equivocado", "numero equivocado"])) {
    $fp = fopen("modificaciones.csv", "a");
    fputcsv($fp, ["eliminar", $telefonoConPrefijo]);
    fclose($fp);
    echo json_encode(["reply" => "Entendido. Eliminamos tu número de nuestra base de gestión."]);
    exit;
}
elseif ($deudor) {
    if (!yaSaludoHoy($telefonoConPrefijo)) {
        $saludo = saludoHora();
        $respuesta = "$saludo {$deudor['nombre']}. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \${$deudor['deuda']}. Ingrese saldo desde su app de Ualá para resolverlo.";
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
        $saludo = saludoHora();
        $respuesta = "$saludo {$encontrado['nombre']}. Soy Rodrigo, abogado del Estudio Cuervo Abogados. Le informamos que mantiene un saldo pendiente de \${$encontrado['deuda']}. Ingrese saldo desde su app de Ualá para resolverlo.";
        registrarVisita($telefonoConPrefijo);
    } else {
        $respuesta = "Hola. No encontramos deuda con ese DNI. ¿Podrías verificar si está bien escrito?";
    }
} else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
