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