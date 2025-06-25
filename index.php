<?php
ini_set('display_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Entrada de datos
$app = $_POST["app"] ?? "";
$sender = $_POST["sender"] ?? "";
$message = strtolower(trim($_POST["message"] ?? ""));
$sender = preg_replace('/\D/', '', $sender);
$telefonoBase = substr($sender, -10);
$telefonoConPrefijo = "+549" . $telefonoBase;

if (strlen($telefonoBase) != 10) exit(json_encode(["reply" => ""]));

// Saludo por hora
function saludoHora() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12) return "BUEN DIA";
    if ($h >= 12 && $h < 19) return "BUENAS TARDES";
    return "BUENAS NOCHES";
}

// Cargar visitas previas
$visitas = [];
if (file_exists("visitas.csv")) {
    foreach (file("visitas.csv") as $linea) {
        [$tel, $fecha] = str_getcsv($linea);
        $visitas[$tel] = $fecha;
    }
}
function registrarVisita($telefono) {
    global $visitas;
    $visitas[$telefono] = date("Y-m-d");
    $fp = fopen("visitas.csv", "w");
    foreach ($visitas as $t => $f) fputcsv($fp, [$t, $f]);
    fclose($fp);
}

// Buscar deudor por teléfono
function buscarDeudor($tel) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    while (($line = fgetcsv($fp)) !== false) {
        if (count($line) >= 4 && substr(preg_replace('/\D/', '', $line[2]), -10) === substr($tel, -10)) {
            fclose($fp);
            return ["nombre" => $line[0], "dni" => $line[1], "telefono" => $line[2], "deuda" => $line[3]];
        }
    }
    fclose($fp);
    return null;
}

function contiene($msg, $palabras) {
    foreach ($palabras as $p) {
        if (strpos($msg, $p) !== false) return true;
    }
    return false;
}

// Variantes de urgencia
function respuestaUrgente() {
    $r = [
        "LE RECORDAMOS QUE DEBE INGRESAR SALDO EN UALA HOY MISMO PARA EVITAR CONSECUENCIAS",
        "CUMPLIMOS EN INFORMARLE QUE SU SITUACION SIGUE ACTIVA INGRESE SALDO DESDE SU APP",
        "EVITE COMPLICACIONES TRANSFIERA DESDE UALA A SU CVU HOY MISMO",
        "SE SOLICITA EL INGRESO DE SALDO HOY EN UALA PARA EVITAR NUEVAS GESTIONES"
    ];
    return $r[array_rand($r)];
}

$deudor = buscarDeudor($telefonoConPrefijo);
$hoy = date("Y-m-d");
$respuesta = "";

// Respuestas especiales
if (contiene($message, ["gracias", "gracia"])) {
    $respuesta = "DE NADA ESTAMOS PARA AYUDARTE";
}
elseif (contiene($message, ["cuota", "cuotas", "refinanciar", "plan", "acuerdo"])) {
    $respuesta = "NO HACEMOS CUOTAS NI PLANES EL SALDO DEBE INGRESARSE DESDE LA APP DE UALA";
}
elseif (contiene($message, ["ya pague", "pague", "saldad", "no debo", "no devo"])) {
    $respuesta = "EN LAS PROXIMAS HORAS ACTUALIZAREMOS LOS REGISTROS GUIESE POR LO QUE VEA EN LA APP DE UALA";
}
elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "desempleado", "desocupado"])) {
    $respuesta = "ENTENDEMOS SU SITUACION LE PEDIMOS QUE HAGA UN ESFUERZO E INGRESE LO QUE PUEDA HOY DESDE UALA";
}
elseif (contiene($message, ["uala no", "no anda", "no puedo entrar", "uala no funciona", "uala no me deja"])) {
    $respuesta = "SI NO PUEDE INGRESAR A LA APP DE UALA CONTACTE A SU SOPORTE TECNICO";
}
// Si ya está en la base
elseif ($deudor) {
    $nombre = strtoupper($deudor["nombre"]);
    $monto = $deudor["deuda"];
    $yaSaludoHoy = isset($visitas[$telefonoConPrefijo]) && $visitas[$telefonoConPrefijo] === $hoy;

    // Primer mensaje del día
    if (!$yaSaludoHoy) {
        $saludo = saludoHora();
        $respuesta = "$saludo $nombre. SOY RODRIGO ABOGADO DEL ESTUDIO CUERVO ABOGADOS. LE INFORMAMOS QUE MANTIENE UN SALDO PENDIENTE DE \$$monto. POR FAVOR REGULARICE INGRESANDO SALDO EN LA APP DE UALA";
        registrarVisita($telefonoConPrefijo);
    }
    // Si ya saludó hoy
    elseif (contiene($message, ["quiero"])) {
        $respuesta = "¿CON CUÁNTO PODRÍAS COMPROMETERTE HOY PARA COMENZAR A SALDAR LA DEUDA?";
    }
    // Si informa un monto a cuenta
    elseif (preg_match('/(puedo|pagar|ingresar|tengo|transferir|colaborar|poner).*?(\\d{3,7})/', $message, $match)) {
        $montoPago = (int)$match[2];
        if ($montoPago >= 500 && $montoPago <= 1000000) {
            $respuesta = "GRACIAS. PODEMOS REGISTRAR $montoPago PESOS COMO UN PAGO A CUENTA HOY. ¿CUANDO PODRIAS COMPLETAR EL SALDO?";
            $fp = fopen("pagos.csv", "a");
            fputcsv($fp, [$telefonoConPrefijo, date("Y-m-d"), $montoPago]);
            fclose($fp);
        }
    } else {
        $respuesta = respuestaUrgente();
    }
}
// Si escribe solo un DNI
elseif (preg_match('/^\\d{7,9}$/', $message, $coincide)) {
    $dni = $coincide[0];
    if (file_exists("deudores.csv")) {
        $fp = fopen("deudores.csv", "r");
        $lines = [];
        $encontrado = null;
        while (($line = fgetcsv($fp)) !== false) {
            if (count($line) >= 4 && trim($line[1]) == $dni) {
                $line[2] = $telefonoConPrefijo;
                $encontrado = ["nombre" => $line[0], "deuda" => $line[3]];
            }
            $lines[] = $line;
        }
        fclose($fp);
        if ($encontrado) {
            $nombre = strtoupper($encontrado["nombre"]);
            $saludo = saludoHora();
            $monto = $encontrado["deuda"];
            $respuesta = "$saludo $nombre. SOY RODRIGO ABOGADO DEL ESTUDIO CUERVO ABOGADOS. LE INFORMAMOS QUE MANTIENE UN SALDO PENDIENTE DE \$$monto. POR FAVOR REGULARICE INGRESANDO SALDO EN LA APP DE UALA";
            registrarVisita($telefonoConPrefijo);
        } else {
            $respuesta = "HOLA. NO ENCONTRAMOS DEUDA CON ESE DNI. ¿PODRIAS VERIFICAR SI ESTA BIEN?";
        }
    }
}
// Si no está identificado
else {
    $respuesta = "HOLA. PARA AYUDARTE NECESITAMOS QUE NOS INDIQUES TU NUMERO DE DNI";
}

// Registrar historial
file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
