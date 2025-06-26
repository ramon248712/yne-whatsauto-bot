<?php
// Configuración general
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: application/json');

// Capturar datos
$app     = $_POST['app']     ?? '';
$sender  = preg_replace('/\D/', '', $_POST['sender']  ?? '');
$message = strtolower(trim($_POST['message'] ?? ''));

// Normalizar número y descartar si es muy corto
if (strlen($sender) < 10) {
    exit(json_encode(['reply' => '']));
}
$telefonoBase      = substr($sender, -10);
$telefonoConPrefijo = "+549{$telefonoBase}";

// 1) Saludo según hora
function saludoHora() {
    $h = (int)date('H');
    if ($h >= 6 && $h < 12)  return 'Buen día';
    if ($h >= 12 && $h < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

// 2) Elegir frase “hasta agotar” usando CSV
function elegirFrase($telefono, $tipo, array $frases) {
    $archivo = 'frases_usadas.csv';
    $usos = [];

    if (file_exists($archivo)) {
        foreach (file($archivo, FILE_IGNORE_NEW_LINES) as $l) {
            list($tel, $t, $lista) = array_pad(explode('|', $l), 3, '');
            $usos[$tel][$t] = $lista === '' ? [] : explode(',', $lista);
        }
    }

    $usadas     = $usos[$telefono][$tipo] ?? [];
    $todos      = range(0, count($frases) - 1);
    $disp       = array_diff($todos, $usadas);

    if (empty($disp)) { // reinicia ciclo
        $usadas = [];
        $disp   = $todos;
    }

    $idx        = array_values($disp)[array_rand($disp)];
    $usadas[]   = $idx;
    $usos[$telefono][$tipo] = $usadas;

    // grabo CSV
    $fp = fopen($archivo, 'w');
    foreach ($usos as $tel => $tipos) {
        foreach ($tipos as $t => $lst) {
            fwrite($fp, "$tel|$t|" . implode(',', $lst) . "\n");
        }
    }
    fclose($fp);

    return $frases[$idx];
}

// 3) Registro de visitas para saludo único diario
function registrarVisita($telefono) {
    file_put_contents('visitas.csv', "$telefono|" . date('Y-m-d') . "\n", FILE_APPEND);
}
function yaSaludoHoy($telefono) {
    if (!file_exists('visitas.csv')) return false;
    foreach (file('visitas.csv', FILE_IGNORE_NEW_LINES) as $l) {
        list($t, $f) = explode('|', $l);
        if ($t === $telefono && $f === date('Y-m-d')) {
            return true;
        }
    }
    return false;
}

// 4) Búsqueda de deudor en CSV
function buscarDeudor($telefono) {
    if (!file_exists('deudores.csv')) return null;
    $fp = fopen('deudores.csv', 'r');
    $telBase = substr(preg_replace('/\D/', '', $telefono), -10);

    while (($line = fgetcsv($fp, 0, ";")) !== false) {
        if (count($line) >= 4) {
            $telCsv = substr(preg_replace('/\D/', '', $line[2]), -10);
            if ($telCsv === $telBase) {
                fclose($fp);
                return [
                    'nombre'   => $line[0],
                    'dni'      => $line[1],
                    'telefono' => $line[2],
                    'deuda'    => $line[3]
                ];
            }
        }
    }
    fclose($fp);
    return null;
}

// 5) Bancos de plantillas
function respuestasIniciales() {
    return [
        "%s, ¿cómo estás? Respecto a la deuda devengada de \$%s con Ualá, estás a tiempo de abonarla desde la app. – Rodrigo",
        "Te escribo por el saldo pendiente de \$%s registrado en tu cuenta Ualá. Podés cancelarlo directamente desde la app. – Rodrigo",
        "Le informamos que mantiene un saldo impago de \$%s en Ualá. Le recomendamos regularizarlo desde la aplicación cuanto antes. – Rodrigo",
        "Se detecta una deuda activa de \$%s con Ualá. Recordá que podés saldarla cargando saldo en la app. – Rodrigo",
        "Aún figura un saldo sin cancelar de \$%s en tu cuenta. Ingresá fondos desde la app para resolverlo. – Rodrigo",
        "Continúa pendiente el pago de \$%s con Ualá. Puede regularizarlo ingresando saldo desde la app. – Rodrigo",
        "Sigue registrada una deuda de \$%s. Por favor, ingresá ese monto en tu cuenta Ualá para evitar gestiones posteriores. – Rodrigo",
        "Te recordamos que hay un importe de \$%s sin abonar. Es necesario cargar ese monto en tu app de Ualá. – Rodrigo",
        "Tu deuda de \$%s permanece activa. Le recomendamos ingresar fondos hoy mismo desde la app de Ualá. – Rodrigo",
        "Aún no hemos registrado el ingreso de \$%s. Podés solucionarlo cargando saldo en tu cuenta Ualá. – Rodrigo",
        "Le notificamos que el monto de \$%s aún no ha sido abonado. Ingréselo desde su app para evitar acciones. – Rodrigo",
        "El sistema indica un saldo pendiente de \$%s con Ualá. Podés regularizarlo desde la aplicación. – Rodrigo",
        "Sigue abierta la deuda de \$%s. Le pedimos que transfiera ese monto desde su app Ualá. – Rodrigo",
        "¿Pudiste revisar tu cuenta? Sigue figurando una deuda de \$%s. Ingrese el saldo en la app para resolver. – Rodrigo",
        "Le recordamos que su deuda de \$%s continúa impaga. Regularícela ingresando el monto desde la app de Ualá. – Rodrigo",
        "Aún vemos un saldo devengado de \$%s. Transfiera hoy mismo desde su app de Ualá. – Rodrigo",
        "Recordá que con solo ingresar \$%s desde la app podés resolver la deuda pendiente. – Rodrigo",
        "Para evitar gestiones adicionales, es necesario que ingreses \$%s en tu app de Ualá. – Rodrigo",
        "Tenés un saldo vencido de \$%s. Te pedimos que lo abones desde la app de Ualá cuanto antes. – Rodrigo",
        "Le notificamos que su cuenta registra una deuda de \$%s. Puede cancelarla desde su aplicación Ualá. – Rodrigo"
    ];
}

function urgenciaAleatoria($telefono) {
    $f = include 'frases_urgencia.php'; 
    return elegirFrase($telefono, 'urgencia', $f);
}

function respuestaGracias($telefono) {
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
    return elegirFrase($telefono, 'gracias', $r);
}

function respuestaNoCuotas($telefono) {
    $r = [
        "No trabajamos con planes, pero puede ingresar lo que pueda hoy desde Ualá",
        "No manejamos acuerdos ni cuotas. El ingreso debe hacerse en la app",
        "No ofrecemos cuotas. Le sugerimos hacer el esfuerzo hoy mismo desde Ualá",
        "Para resolverlo, debe ingresar saldo desde su app. Incluso un monto parcial ayuda",
        "Gracias por consultar. No hacemos acuerdos de pago, el ingreso es directo desde la app de Ualá"
    ];
    return elegirFrase($telefono, 'cuotas', $r);
}

function respuestaSinTrabajo($telefono) {
    $r = [
        "Entendemos que esté sin trabajo. Le pedimos que igual haga el esfuerzo de ingresar lo que pueda hoy desde Ualá",
        "Sabemos que la situación puede ser difícil, pero necesitamos que ingrese un monto hoy desde la app de Ualá",
        "Aunque esté sin trabajo, le pedimos que realice una carga mínima en su cuenta Ualá para evitar gestiones"
    ];
    return elegirFrase($telefono, 'sintrabajo', $r);
}

function respuestaProblemaApp($telefono) {
    $r = [
        "Si tiene problemas para acceder a la app de Ualá, comuníquese con soporte de Ualá",
        "Para problemas con la app, le recomendamos contactar al soporte de Ualá directamente",
        "Le sugerimos reiniciar la app o comunicarse con soporte de Ualá si persiste el problema"
    ];
    return elegirFrase($telefono, 'app', $r);
}

// 6) Lógica principal
$deudor = buscarDeudor($telefonoConPrefijo);

if (preg_match('/\b\d{7,9}\b/', $message, $m)) {
    // Manejo de envío de DNI para asociación
    // … tu código de asociación aquí …
    $respuesta = "Hola. Verificamos tu DNI y actualizamos tu número.";
}
elseif ($deudor) {
    // primer saludo diario con nombre+monto
    if (!yaSaludoHoy($telefonoConPrefijo)) {
        registrarVisita($telefonoConPrefijo);
        $nombre = ucfirst(strtolower($deudor['nombre']));
        $monto  = $deudor['deuda'];
        // formatear plantillas iniciales
        $tpls = respuestasIniciales();
        $fmt  = array_map(fn($t) => sprintf($t, $nombre, $monto), $tpls);
        $textoBase = elegirFrase($telefonoConPrefijo, 'inicial', $fmt);
        $respuesta = saludoHora() . ' ' . $textoBase;
    }
    // demás mensajes del día → urgencia pura
    else {
        $respuesta = urgenciaAleatoria($telefonoConPrefijo);
    }
}
elseif (strpos($message, 'gracia') !== false) {
    $respuesta = respuestaGracias($telefonoConPrefijo);
}
elseif (strpos($message, 'cuota') !== false || strpos($message, 'plan') !== false) {
    $respuesta = respuestaNoCuotas($telefonoConPrefijo);
}
elseif (strpos($message, 'sin trabajo') !== false || strpos($message, 'desemple') !== false) {
    $respuesta = respuestaSinTrabajo($telefonoConPrefijo);
}
elseif (strpos($message, 'no anda la app') !== false 
     || strpos($message, 'no puedo') !== false) {
    $respuesta = respuestaProblemaApp($telefonoConPrefijo);
}
else {
    $respuesta = "Hola. ¿Podrías indicarnos tu DNI para identificarte?";
}

// Guardar historial y responder
file_put_contents(
    "historial.txt",
    date("Y-m-d H:i") . " | $sender => $message\n",
    FILE_APPEND
);
echo json_encode(['reply' => $respuesta]);
exit;
?>
