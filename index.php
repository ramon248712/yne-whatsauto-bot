<?php
// Configuración general
ini_set('display_errors', 0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Capturar datos
$app     = $_POST["app"]     ?? "";
$sender  = preg_replace('/\D/', '', $_POST["sender"] ?? "");
$message = strtolower(trim($_POST["message"] ?? ""));

// Normalizar número
if (strlen($sender) < 10) exit(json_encode(["reply" => ""]));
$telefonoConPrefijo = "+549" . substr($sender, -10);

// ——— Funciones auxiliares ———

function saludoHora() {
    $h = (int)date("H");
    if ($h >= 6 && $h < 12)  return "Buen día";
    if ($h >= 12 && $h < 19) return "Buenas tardes";
    return "Buenas noches";
}

function contiene($msg, $palabras) {
    foreach ($palabras as $p) {
        if (strpos($msg, $p) !== false) return true;
    }
    return false;
}

// Track “hasta agotar” en frases_usadas.csv
function elegirFrase($telefono, $tipo, array $frases) {
    $file = "frases_usadas.csv";
    $usos = [];

    if (file_exists($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $l) {
            list($tel, $t, $lst) = array_pad(explode("|", $l), 3, "");
            $usos[$tel][$t] = $lst === "" ? [] : explode(",", $lst);
        }
    }

    $usadas = $usos[$telefono][$tipo] ?? [];
    $todos  = range(0, count($frases) - 1);
    $disp   = array_diff($todos, $usadas);
    if (empty($disp)) {
        $usadas = [];
        $disp   = $todos;
    }

    $idx = array_values($disp)[array_rand($disp)];
    $usadas[] = $idx;
    $usos[$telefono][$tipo] = $usadas;

    // Grabar CSV
    $fp = fopen($file, "w");
    foreach ($usos as $tel => $tipos) {
        foreach ($tipos as $t => $lst) {
            fwrite($fp, "$tel|$t|" . implode(",", $lst) . "\n");
        }
    }
    fclose($fp);

    return $frases[$idx];
}

// Registro de saludo único por día
function registrarVisita($tel) {
    file_put_contents("visitas.csv", "$tel|" . date("Y-m-d") . "\n", FILE_APPEND);
}
function yaSaludoHoy($tel) {
    if (!file_exists("visitas.csv")) return false;
    foreach (file("visitas.csv", FILE_IGNORE_NEW_LINES) as $l) {
        list($t, $f) = explode("|", $l);
        if ($t === $tel && $f === date("Y-m-d")) return true;
    }
    return false;
}

// Búsqueda de deudor en deudores.csv (formato: nombre;dni;telefono;deuda)
function buscarDeudor($tel) {
    if (!file_exists("deudores.csv")) return null;
    $fp = fopen("deudores.csv", "r");
    $base = substr(preg_replace('/\D/', '', $tel), -10);
    while (($row = fgetcsv($fp, 0, ";")) !== false) {
        if (count($row) >= 4) {
            $c = substr(preg_replace('/\D/', '', $row[2]), -10);
            if ($c === $base) {
                fclose($fp);
                return [
                    "nombre" => $row[0],
                    "dni"    => $row[1],
                    "telefono" => $row[2],
                    "deuda"  => $row[3]
                ];
            }
        }
    }
    fclose($fp);
    return null;
}

// ——— Bancos de frases ———

function respuestasIniciales() {
    return [
        "%s, ¿cómo estás? Respecto a la deuda devengada de \$%s con Ualá, estás a tiempo de abonarla desde la app. – Rodrigo",
        "Te escribo por el saldo pendiente de \$%s registrado en tu cuenta Ualá. Podés cancelarlo directamente desde la app. – Rodrigo",
        "Le informamos que mantiene un saldo impago de \$%s en Ualá. Recomendamos regularizarlo cuanto antes. – Rodrigo",
        "Se detecta una deuda activa de \$%s con Ualá. Recordá que podés saldarla cargando saldo en la app. – Rodrigo",
        "Aún figura un saldo sin cancelar de \$%s en tu cuenta. Ingresá fondos desde la app para resolverlo. – Rodrigo",
        "Continúa pendiente el pago de \$%s con Ualá. Puede regularizarlo ingresando saldo desde la app. – Rodrigo",
        "Sigue registrada una deuda de \$%s. Ingresá ese monto en tu cuenta Ualá para evitar gestiones posteriores. – Rodrigo",
        "Te recordamos que hay un importe de \$%s sin abonar. Cargá ese monto en tu app de Ualá. – Rodrigo",
        "Tu deuda de \$%s permanece activa. Ingresá fondos hoy mismo desde la app de Ualá. – Rodrigo",
        "Aún no hemos registrado el ingreso de \$%s. Solucionalo cargando saldo en tu cuenta Ualá. – Rodrigo",
        "Le notificamos que \$%s aún no ha sido abonado. Ingréselo desde su app para evitar acciones. – Rodrigo",
        "El sistema indica un saldo pendiente de \$%s con Ualá. Regularizalo desde la aplicación. – Rodrigo",
        "Sigue abierta la deuda de \$%s. Transfirí ese monto desde tu app Ualá. – Rodrigo",
        "¿Pudiste revisar tu cuenta? Sigue figurando \$%s. Ingresá el saldo en la app para resolver. – Rodrigo",
        "Tu deuda de \$%s continúa impaga. Regularizala ingresando el monto desde la app de Ualá. – Rodrigo",
        "Aún vemos un saldo devengado de \$%s. Transfiera hoy mismo desde su app de Ualá. – Rodrigo",
        "Con solo ingresar \$%s desde la app podés resolver la deuda pendiente. – Rodrigo",
        "Para evitar gestiones adicionales, ingresá \$%s en tu app de Ualá. – Rodrigo",
        "Tenés un saldo vencido de \$%s. Abonalo desde la app de Ualá cuanto antes. – Rodrigo",
        "Tu cuenta registra una deuda de \$%s. Cancelala desde tu aplicación Ualá. – Rodrigo"
    ];
}

function urgenciaAleatoria($tel) {
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
    return elegirFrase($tel, "urgencia", $r);
}

function respuestaGracias($tel) {
    $r = [
        "De nada, estamos para ayudarte",
        "Un placer ayudarte",
        "Con gusto",
        "Siempre a disposición",
        "Gracias a vos por comunicarte",
        "Estamos para ayudarte",
        "Un gusto poder colaborar",
        "Cualquier cosa, escribinos",
        "Lo que necesites, consultanos"
    ];
    return elegirFrase($tel, "gracias", $r);
}

function respuestaNoCuotas($tel) {
    $r = [
        "Entendemos que esté complicado. No trabajamos con planes, pero podés ingresar lo que puedas hoy desde Ualá",
        "No manejamos acuerdos ni cuotas. El ingreso debe hacerse en la app",
        "No ofrecemos cuotas. Hacé el esfuerzo hoy mismo desde Ualá",
        "Para resolverlo, ingresá saldo desde la app. Incluso un monto parcial ayuda",
        "Gracias por consultar. No hacemos acuerdos de pago, el ingreso es directo desde la app"
    ];
    return elegirFrase($tel, "cuotas", $r);
}

function respuestaSinTrabajo($tel) {
    $r = [
        "Entendemos que estés sin trabajo. Te pedimos que ingreses lo que puedas hoy desde la app",
        "Sabemos que la situación es difícil, pero necesitamos un monto hoy desde Ualá",
        "Aunque estés sin trabajo, pedimos una carga mínima para evitar gestiones"
    ];
    return elegirFrase($tel, "sintrabajo", $r);
}

function respuestaProblemaApp($tel) {
    $r = [
        "Si tenés problemas para acceder a la app de Ualá, comunicate con soporte de Ualá",
        "Para problemas con la app, contactá al soporte directamente",
        "Reiniciá la app o comunicate con soporte si persiste el problema"
    ];
    return elegirFrase($tel, "app", $r);
}

// ——— Procesamiento ———
$respuesta = "";

if (contiene($message, ["equivocado", "no soy", "falleció", "fallecido", "murió", "número equivocado"])) {
    file_put_contents("modificaciones.csv", "eliminar|$telefonoConPrefijo\n", FILE_APPEND);
    $respuesta = "Ok, disculpe";
}
elseif (preg_match('/\b\d{7,9}\b/', $message, $m)) {
    // Asociación DNI–teléfono
    $respuesta = "Hola";
}
elseif (contiene($message, ["gracia", "gracias", "graciah"])) {
    $respuesta = respuestaGracias($telefonoConPrefijo);
}
elseif (contiene($message, ["cuota", "cuotas", "plan", "refinanc"])) {
    $respuesta = respuestaNoCuotas($telefonoConPrefijo);
}
elseif (contiene($message, ["sin trabajo", "desemple", "desocup"])) {
    $respuesta = respuestaSinTrabajo($telefonoConPrefijo);
}
elseif (contiene($message, ["no anda la app", "no puedo entrar", "uala no funciona", "no puedo ingresar", "uala no me deja", "uala no abre", "uala no carga"])) {
    $respuesta = respuestaProblemaApp($telefonoConPrefijo);
}
else {
    $deudor = buscarDeudor($telefonoConPrefijo);
    if ($deudor) {
        if (!yaSaludoHoy($telefonoConPrefijo)) {
            registrarVisita($telefonoConPrefijo);
            $nom  = ucfirst(strtolower($deudor["nombre"]));
            $mon  = $deudor["deuda"];
            $tpls = respuestasIniciales();
            $fmt  = array_map(fn($t) => sprintf($t, $nom, $mon), $tpls);
            $base = elegirFrase($telefonoConPrefijo, "inicial", $fmt);
            $respuesta = saludoHora() . " " . $base;
        } else {
            $respuesta = urgenciaAleatoria($telefonoConPrefijo);
        }
    } else {
        $respuesta = "Hola";
    }
}

file_put_contents("historial.txt", date("Y-m-d H:i") . " | $sender => $message\n", FILE_APPEND);
echo json_encode(["reply" => $respuesta]);
exit;
?>
