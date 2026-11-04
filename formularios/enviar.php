<?php
// Recibe los formularios de Contáctanos y Asociarse y los manda por correo a la asociación,
// igual que hacía Contact Form 7. No guarda los datos en ningún sitio (ni base de datos ni ficheros).
// Compatible con PHP 7.4. Los campos se definen en src/lib/formularios.ts (→ campos.json).

declare(strict_types=1);

// Destinatario y remitente: ajustar a la configuración de Contact Form 7 / Forminator
const DESTINO = 'info@agraela.org';
const REMITENTE = 'info@agraela.org';
const NOMBRE_REMITENTE = 'Web AGRAELA';
const SEGUNDOS_MINIMOS = 3;
const ENVIOS_POR_HORA = 5;
const PAGINA_ERROR = '/formulario-error/';

// Pruebas locales: AGRAELA_FORMULARIOS_PRUEBA=/ruta/salida.txt guarda el correo en ese fichero
// y escribe la redirección en vez de enviarlo
$modoPrueba = getenv('AGRAELA_FORMULARIOS_PRUEBA') ?: '';

function terminar(string $ruta): void
{
    global $modoPrueba;
    if ($modoPrueba !== '') {
        echo "REDIRECCION {$ruta}\n";
        return;
    }
    header('Location: ' . $ruta, true, 303);
    exit;
}

function cortar(string $texto, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($texto, 0, $max, 'UTF-8') : substr($texto, 0, $max);
}

function sinSaltos(string $texto): string
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', $texto) ?? '');
}

function procesar(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        return '';
    }

    $definiciones = json_decode((string) @file_get_contents(__DIR__ . '/campos.json'), true);
    $tipo = (string) ($_POST['formulario'] ?? '');
    if (!is_array($definiciones) || !isset($definiciones[$tipo])) {
        return PAGINA_ERROR;
    }
    $def = $definiciones[$tipo];

    // Antispam sin captcha: campo trampa invisible y tiempo mínimo de relleno (solo si el navegador
    // ejecuta JS; sin JS no hay marca de tiempo y no se aplica)
    $trampa = trim((string) ($_POST['web'] ?? ''));
    $inicio = (int) ($_POST['t'] ?? 0);
    if ($trampa !== '' || ($inicio > 0 && (time() - intdiv($inicio, 1000)) < SEGUNDOS_MINIMOS)) {
        return $def['ok']; // al bot se le responde como si hubiera ido bien
    }

    if (!dentroDelLimite()) {
        return PAGINA_ERROR;
    }

    $valores = [];
    foreach ($def['campos'] as $campo) {
        $valor = cortar(trim((string) ($_POST[$campo['name']] ?? '')), (int) $campo['max']);
        $tipoCampo = $campo['tipo'] ?? '';
        if ($tipoCampo !== 'texto') {
            $valor = sinSaltos($valor);
        }
        if ($campo['requerido'] && $valor === '') {
            return PAGINA_ERROR;
        }
        if ($valor !== '' && $tipoCampo === 'email' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            return PAGINA_ERROR;
        }
        if ($valor !== '' && $tipoCampo === 'fecha' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return PAGINA_ERROR;
        }
        $valores[$campo['name']] = $valor;
    }

    $asunto = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($valores) {
        return $valores[$m[1]] ?? '';
    }, $def['asunto']);
    $asunto = sinSaltos(trim((string) $asunto, " :")) ?: 'Formulario de ' . $def['pagina'];

    $lineas = [];
    foreach ($def['campos'] as $campo) {
        $valor = $valores[$campo['name']];
        if ($valor === '') {
            continue;
        }
        if ($campo['name'] === 'consentimiento') {
            $valor = 'Aceptado';
        }
        $lineas[] = $campo['label'] . ":\n" . $valor;
    }
    $cuerpo = implode("\n\n", $lineas)
        . "\n\n--\nEnviado desde el formulario de " . $def['pagina'] . ' de agraela.org el ' . date('d/m/Y H:i') . "\n";

    $cabeceras = [
        'From: ' . NOMBRE_REMITENTE . ' <' . REMITENTE . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $correo = $valores['correo'] ?? '';
    if ($correo !== '') {
        $cabeceras[] = 'Reply-To: ' . $correo;
    }

    return enviar($asunto, $cuerpo, $cabeceras) ? $def['ok'] : PAGINA_ERROR;
}

function enviar(string $asunto, string $cuerpo, array $cabeceras): bool
{
    global $modoPrueba;
    $asuntoCodificado = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    if ($modoPrueba !== '') {
        $mensaje = "Para: " . DESTINO . "\nAsunto: {$asunto}\n" . implode("\n", $cabeceras) . "\n\n{$cuerpo}";
        return file_put_contents($modoPrueba, $mensaje) !== false;
    }
    // -f fija el remitente del sobre para que cuadre con el SPF del dominio
    return mail(DESTINO, $asuntoCodificado, $cuerpo, implode("\r\n", $cabeceras), '-f' . REMITENTE);
}

// Límite de envíos por IP en la última hora. Solo guarda un hash de la IP y marcas de tiempo.
function dentroDelLimite(): bool
{
    $dir = sys_get_temp_dir() . '/agraela-formularios';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return true; // si no se puede escribir, no se bloquea a nadie
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $fichero = $dir . '/' . hash('sha256', $ip . '|agraela') ;
    $ahora = time();
    $marcas = array_filter(
        array_map('intval', file_exists($fichero) ? (array) file($fichero, FILE_IGNORE_NEW_LINES) : []),
        function ($t) use ($ahora) {
            return $t > $ahora - 3600;
        }
    );
    if (count($marcas) >= ENVIOS_POR_HORA) {
        return false;
    }
    $marcas[] = $ahora;
    @file_put_contents($fichero, implode("\n", $marcas));
    return true;
}

$destino = procesar();
if ($destino !== '') {
    terminar($destino);
}
