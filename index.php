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

function elegirFrase($telefono, $clave, $frases) {
    $archivo = "frases_usadas.csv";
    $usos = [];

    if (file_exists($archivo)) {
        foreach (file($archivo, FILE_IGNORE_NEW_LINES) as $linea) {
            list($tel, $tipo, $lista) = array_pad(explode("|", $linea), 3, '');
            $usos[$tel][$tipo] = $lista === '' ? [] : explode(",", $lista);
        }
    }

    $usadas = $usos[$telefono][$clave] ?? [];
    $todos = range(0, count($frases) - 1);
    $disponibles = array_diff($todos, $usadas);
    if (empty($disponibles)) {
        $usadas = [];
        $disponibles = $todos;
    }

    $indice = array_values($disponibles)[array_rand($disponibles)];
    $usadas[] = $indice;
    $usos[$telefono][$clave] = $usadas;

    $fp = fopen($archivo, "w");
    foreach ($usos as $tel => $tipos) {
        foreach ($tipos as $tipo => $lista) {
            fwrite($fp, "$tel|$tipo|" . implode(",", $lista) . "\n");
        }
    }
    fclose($fp);

    return $frases[$indice];
}

function urgenciaAleatoria($telefono) {
    $frases = include "frases_urgencia.php";
    return elegirFrase($telefono, "urgencia", $frases);
}

function respuestaGracias($telefono) {
    $r = ["De nada, estamos para ayudarte", "Un placer ayudarte", "Con gusto", "Siempre a disposición", "Gracias a vos por comunicarte", "Estamos para ayudarte", "Un gusto poder colaborar", "Cualquier cosa, escribinos", "Lo que necesites, consultanos"];
    return elegirFrase($telefono, "gracias", $r);
}

function respuestaNoCuotas($telefono) {
    $r = ["No trabajamos con planes, pero puede ingresar lo que pueda hoy desde Ualá", "No manejamos acuerdos ni cuotas. El ingreso debe hacerse en la app", "No ofrecemos cuotas. Le sugerimos hacer el esfuerzo hoy mismo desde Ualá", "Para resolverlo, debe ingresar saldo desde su app. Incluso un monto parcial ayuda", "Gracias por consultar. No hacemos acuerdos de pago, el ingreso es directo desde la app de Ualá"];
    return elegirFrase($telefono, "cuotas", $r);
}

function respuestaSinTrabajo($telefono) {
    $r = ["Entendemos que esté sin trabajo. Le pedimos que igual haga el esfuerzo de ingresar lo que pueda hoy desde Ualá", "Sabemos que la situación puede ser difícil, pero necesitamos que ingrese un monto hoy desde la app de Ualá", "Aunque esté sin trabajo, le pedimos que realice una carga mínima en su cuenta Ualá para evitar gestiones"];
    return elegirFrase($telefono, "sintrabajo", $r);
}

function respuestaProblemaApp($telefono) {
    $r = ["Si tiene problemas para acceder a la app de Ualá, comuníquese con soporte de Ualá", "Para problemas con la app, le recomendamos contactar al soporte de Ualá directamente", "Le sugerimos reiniciar la app o comunicarse con soporte de Ualá si persiste el problema"];
    return elegirFrase($telefono, "app", $r);
}

function respuestaInicialPersonalizada($telefono, $nombre, $monto) {
    $frases = [
        "$nombre, ¿cómo estás? Respecto a la deuda devengada de \$$monto con Ualá, estás a tiempo de abonarla desde la app. – Rodrigo",
        "Te escribo por el saldo pendiente de \$$monto registrado en tu cuenta Ualá. Podés cancelarlo directamente desde la app. – Rodrigo",
        "Le informamos que mantiene un saldo impago de \$$monto en Ualá. Le recomendamos regularizarlo desde la aplicación cuanto antes. – Rodrigo",
        "Se detecta una deuda activa de \$$monto con Ualá. Recordá que podés saldarla cargando saldo en la app. – Rodrigo",
        "Aún figura un saldo sin cancelar de \$$monto en tu cuenta. Ingresá fondos desde la app para resolverlo. – Rodrigo",
        "Continúa pendiente el pago de \$$monto con Ualá. Puede regularizarlo ingresando saldo desde la app. – Rodrigo",
        "Sigue registrada una deuda de \$$monto. Por favor, ingresá ese monto en tu cuenta Ualá para evitar gestiones posteriores. – Rodrigo",
        "Te recordamos que hay un importe de \$$monto sin abonar. Es necesario cargar ese monto en tu app de Ualá. – Rodrigo",
        "Tu deuda de \$$monto permanece activa. Le recomendamos ingresar fondos hoy mismo desde la app de Ualá. – Rodrigo",
        "Aún no hemos registrado el ingreso de \$$monto. Podés solucionarlo cargando saldo en tu cuenta Ualá. – Rodrigo"
    ];
    $saludo = saludoHora();
    return "$saludo " . elegirFrase($telefono, "inicial", $frases);
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

    } elseif (contiene($message, ["sin trabajo", "no tengo trabajo", "sin empleo", "chagas", "desempleado", "desocupado"])) {
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
