<?php

declare(strict_types=1);

/**
 * Assistente de instalacao -- roda uma unica vez, igual instalador de
 * WordPress/phpMyAdmin. A pessoa que recebe o projeto escolhe o motor de
 * banco, preenche a conexao, define o titulo do projeto e cria o usuario
 * administrador. Ao final, o proprio assistente apaga esta pasta (setup/)
 * pra ninguem conseguir reinstalar o sistema so visitando /setup de novo.
 *
 * Nao usa config()/db() de backend/db.php enquanto conf/config.php nao
 * existe -- por isso "testarConexaoDireta()" monta a conexao na mao com
 * os dados que vieram do formulario.
 *
 * Ja carregamos backend/auth.php aqui no topo (so tem definicao de
 * funcao, nao executa nada sozinho) pra reusar avaliarForcaSenha() na
 * validacao da senha do administrador -- mesma regra usada depois pra
 * trocar senha ou criar usuario, sem duplicar logica em dois lugares.
 *
 * Suporte a idioma: detectado via $_GET['lang'] (valores 'pt' ou 'en').
 * Padrao e 'pt'. O idioma persiste no action do form e na URL do AJAX.
 */

require_once __DIR__ . '/../backend/auth.php';

/**
 * Mesma logica de caminhoBaseApp() em index.php (duplicada aqui, mesmo
 * padrao do verificarExtensaoPdo() abaixo: setup/ pode ser carregado via
 * require do index.php OU acessado direto pelo servidor caso o .htaccess
 * nao intercepte -- precisa funcionar nos dois casos).
 */
if (!function_exists('caminhoBaseApp')) {
function caminhoBaseApp(): string
{
    // Mesma logica do index.php: usa SCRIPT_NAME para evitar problema de symlinks
    // em servidores FreeBSD. Em setup/index.php, SCRIPT_NAME e ex: /gestao/setup/index.php,
    // entao precisa de 2x dirname() para chegar ao basePath do portal (/gestao).
    $scriptName = rtrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $dir = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');
    return ($dir === '' || $dir === '.') ? '' : $dir;
}
}

/**
 * Mesma logica de enviarCabecalhosSeguranca() em index.php (duplicada
 * aqui, mesmo padrao de caminhoBaseApp() acima -- setup/ pode ser acessado
 * direto pelo navegador, sem passar pelo index.php). Ver os comentarios
 * la pra detalhe de cada cabecalho/diretiva da CSP.
 */
if (!function_exists('enviarCabecalhosSeguranca')) {
function enviarCabecalhosSeguranca(string $cspNonce): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-{$cspNonce}' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'");
}
}

$basePath = caminhoBaseApp();
$cspNonce = base64_encode(random_bytes(16));
enviarCabecalhosSeguranca($cspNonce);

$configPath = __DIR__ . '/../conf/config.php';
$jaInstalado = is_file($configPath);

$erros = [];
$sucesso = false;
$avisoLimpeza = null;
$valores = [
    'project_title' => '',
    'table_prefix' => '',
    'db_driver' => 'mysql',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_password' => '',
    'admin_username' => '',
    'admin_nome' => '',
    'cors_allowed_origin' => detectarOrigemAtual(),
];

$portaPadrao = ['mysql' => '3306', 'pgsql' => '5432', 'sqlsrv' => '1433'];

// ---------------------------------------------------------------------------
// Idioma -- detectado via GET; persiste no action do form e no AJAX.
// ---------------------------------------------------------------------------
$lang = (($_GET['lang'] ?? '') === 'en') ? 'en' : 'pt';

/** @var array<string, array<string, string>> $t */
$t = [
    'pt' => [
        // pagina "ja instalado"
        'already_tab'      => 'Instalacao concluida',
        'already_h2'       => 'Este portal ja foi instalado',
        'already_p1'       => 'Encontramos um arquivo <code>conf/config.php</code> valido, ou seja, a instalacao'
                            . ' ja foi concluida anteriormente. Por seguranca, o assistente nao roda de novo'
                            . ' sobre uma instalacao existente.',
        'already_p2'       => 'Se a pasta <code>setup/</code> ainda existe no servidor, remova-a manualmente agora.'
                            . ' Se voce precisa reconfigurar o banco, edite <code>conf/config.php</code> direto.',
        'go_portal'        => 'Ir para o portal',
        // AJAX
        'conn_ok'          => 'Conexao realizada com sucesso.',
        // validacao
        'err_title'        => 'Informe o titulo do projeto.',
        'err_driver'       => 'Selecione um motor de banco de dados valido.',
        'f_host'           => 'Host',
        'f_port'           => 'Porta',
        'f_dbname'         => 'Nome do banco',
        'f_user'           => 'Usuario',
        'err_field'        => 'Informe o campo "%s".',
        'err_login'        => 'Informe o login do administrador.',
        'err_pass_match'   => 'A confirmacao de senha nao corresponde a senha informada.',
        'err_cors'         => 'Informe o dominio de producao no formato "https://seusite.com.br" (sem barra no final, sem caminho).',
        'err_conn'         => 'Nao foi possivel conectar ao banco com os dados informados: ',
        'err_config'       => 'Nao foi possivel gravar "conf/config.php". Confira se a pasta "conf/" tem permissao de escrita pelo servidor web.',
        'err_db'           => 'A conexao funcionou, mas houve um erro ao preparar o banco de dados: ',
        'err_cleanup'      => 'Nao foi possivel remover a pasta "setup/" automaticamente (detalhe: %s). Por seguranca, remova essa pasta manualmente do servidor agora que a instalacao foi concluida.',
        // sucesso
        'tab_install'      => 'Instalar portal',
        'tab_success'      => 'Instalacao concluida',
        'success_h1'       => 'Instalacao concluida',
        'success_user'     => 'O banco foi preparado e o usuario master "%s" foi criado.',
        'success_cleanup'  => 'A pasta "setup/" foi removida automaticamente por seguranca.',
        'enter_portal'     => 'Entrar no portal',
        // formulario
        'setup_h1'         => 'Vamos instalar o seu portal',
        'setup_intro'      => 'Defina o titulo do projeto, conecte o banco de dados e crie o primeiro usuario administrador. Ao final, esta pagina de instalacao e removida automaticamente.',
        's_project'        => 'Projeto',
        'lbl_title'        => 'Titulo do projeto',
        'ph_title'         => 'Ex.: Portal de Dados',
        'lbl_prefix'       => 'Prefixo das tabelas (opcional)',
        'ph_prefix'        => 'gdt',
        'hint_prefix'      => 'Identifica as tabelas deste projeto no banco (ex.: "abc" gera "abc_usuarios", "abc_acessos"...). Util se varios projetos compartilham o mesmo banco. Deixe em branco para usar o padrao "gdt".',
        'lbl_cors'         => 'Dominio de producao (seguranca da API)',
        'ph_cors'          => 'https://seusite.com.br',
        'hint_cors'        => 'Detectamos este endereco pelo link que voce esta usando agora para acessar a instalacao. Confirme se e o dominio definitivo do portal (sem barra no final) -- a API so vai aceitar chamadas vindas dele.',
        'lbl_cors_any'     => 'Permitir qualquer origem (nao recomendado -- so para testes locais)',
        's_db'             => 'Banco de dados',
        'lbl_engine'       => 'Motor',
        'opt_sqlite'       => 'SQLite (arquivo local, sem servidor)',
        'hint_sqlite'      => 'O SQLite nao precisa de servidor: o assistente cria a pasta <code>db/</code> e o arquivo <code>db/database.db</code> automaticamente. Nao e necessario informar caminho.',
        'btn_test'         => 'Testar conexao',
        's_admin'          => 'Usuario master do portal',
        'lbl_login'        => 'Login',
        'lbl_fullname'     => 'Nome completo',
        'lbl_pass'         => 'Senha',
        'hint_pass'        => 'Minimo 8 caracteres, com letra e numero.',
        'lbl_confirm'      => 'Confirmar senha',
        'btn_install'      => 'Instalar portal',
        // JS
        'js_testing'       => 'Testando...',
        'js_test_conn'     => 'Testar conexao',
        'js_test_fail'     => 'Nao foi possivel testar a conexao agora.',
        'js_installing'    => 'Instalando...',
        // alternancia de idioma
        'lang_other_code'  => 'en',
        'lang_other_label' => '&#x1F1EC;&#x1F1E7; EN',
    ],
    'en' => [
        // already installed page
        'already_tab'      => 'Installation complete',
        'already_h2'       => 'This portal is already installed',
        'already_p1'       => 'We found a valid <code>conf/config.php</code> file, meaning the installation was already completed. For security, the wizard will not run again on an existing installation.',
        'already_p2'       => 'If the <code>setup/</code> folder still exists on the server, remove it manually now. If you need to reconfigure the database, edit <code>conf/config.php</code> directly.',
        'go_portal'        => 'Go to portal',
        // AJAX
        'conn_ok'          => 'Connection successful.',
        // validation
        'err_title'        => 'Enter a project title.',
        'err_driver'       => 'Select a valid database engine.',
        'f_host'           => 'Host',
        'f_port'           => 'Port',
        'f_dbname'         => 'Database name',
        'f_user'           => 'User',
        'err_field'        => 'Enter the "%s" field.',
        'err_login'        => 'Enter the administrator login.',
        'err_pass_match'   => 'Password confirmation does not match the entered password.',
        'err_cors'         => 'Enter the production domain in the format "https://yoursite.com" (no trailing slash, no path).',
        'err_conn'         => 'Could not connect to the database with the provided details: ',
        'err_config'       => 'Could not write "conf/config.php". Check that the "conf/" folder has write permission for the web server.',
        'err_db'           => 'Connection succeeded, but an error occurred while preparing the database: ',
        'err_cleanup'      => 'Could not automatically remove the "setup/" folder (detail: %s). For security, remove this folder manually from the server now that installation is complete.',
        // success
        'tab_install'      => 'Install Portal',
        'tab_success'      => 'Installation complete',
        'success_h1'       => 'Installation complete',
        'success_user'     => 'The database is ready and the master user "%s" has been created.',
        'success_cleanup'  => 'The "setup/" folder was automatically removed for security.',
        'enter_portal'     => 'Enter portal',
        // form
        'setup_h1'         => "Let's install your portal",
        'setup_intro'      => 'Define the project title, connect the database, and create the first administrator user. At the end, this installation page is removed automatically.',
        's_project'        => 'Project',
        'lbl_title'        => 'Project title',
        'ph_title'         => 'Ex.: Data Portal',
        'lbl_prefix'       => 'Table prefix (optional)',
        'ph_prefix'        => 'gdt',
        'hint_prefix'      => 'Identifies this project\'s tables in the database (e.g., "abc" creates "abc_usuarios", "abc_acessos"...). Useful when multiple projects share the same database. Leave blank to use the default "gdt".',
        'lbl_cors'         => 'Production domain (API security)',
        'ph_cors'          => 'https://yoursite.com',
        'hint_cors'        => 'We detected this address from the link you are currently using to access the installation. Confirm it is the final portal domain (no trailing slash) -- the API will only accept calls from this origin.',
        'lbl_cors_any'     => 'Allow any origin (not recommended -- for local testing only)',
        's_db'             => 'Database',
        'lbl_engine'       => 'Engine',
        'opt_sqlite'       => 'SQLite (local file, no server)',
        'hint_sqlite'      => 'SQLite does not require a server: the wizard creates the <code>db/</code> folder and the <code>db/database.db</code> file automatically. No path needs to be entered.',
        'btn_test'         => 'Test connection',
        's_admin'          => 'Portal master user',
        'lbl_login'        => 'Login',
        'lbl_fullname'     => 'Full name',
        'lbl_pass'         => 'Password',
        'hint_pass'        => 'Minimum 8 characters, with a letter and a number.',
        'lbl_confirm'      => 'Confirm password',
        'btn_install'      => 'Install portal',
        // JS
        'js_testing'       => 'Testing...',
        'js_test_conn'     => 'Test connection',
        'js_test_fail'     => 'Could not test the connection right now.',
        'js_installing'    => 'Installing...',
        // language toggle
        'lang_other_code'  => 'pt',
        'lang_other_label' => '&#x1F1E7;&#x1F1F7; PT',
    ],
];

$T = $t[$lang];

// ---------------------------------------------------------------------------
// Helpers de conexao (sem depender de backend/db.php, que exige config.php)
// ---------------------------------------------------------------------------

/**
 * Caminho fixo do arquivo SQLite: "db/database.db" na raiz do projeto
 * (irmao de backend/, conf/, css/...). Cria a pasta "db/" na primeira
 * vez, se ainda nao existir -- assim a pessoa que instala nunca precisa
 * informar caminho nenhum pra usar SQLite.
 */
function caminhoSqlitePadrao(): string
{
    $pasta = __DIR__ . '/../db';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0775, true);
    }
    return $pasta . '/database.db';
}

/**
 * Mesma regra de saneamento do tablePrefix() em backend/db.php (so
 * letras minusculas/numeros/underscore, sem underscore nas pontas,
 * padrao "gdt" se ficar vazio) -- reescrita aqui porque tablePrefix()
 * chama config(), que so funciona depois que conf/config.php existir.
 */
function sanitizarPrefixoTabela(string $bruto): string
{
    $prefixo = strtolower(trim($bruto));
    $prefixo = (string) preg_replace('/[^a-z0-9_]/', '', $prefixo);
    $prefixo = trim($prefixo, '_');
    return $prefixo !== '' ? $prefixo : 'gdt';
}

/**
 * Esquema + host de quem esta acessando este formulario agora (ex.:
 * "https://portal.minhaempresa.com.br"). Usado so pra PRE-PREENCHER o
 * campo "dominio de producao" do CORS -- quem instala confirma ou corrige
 * antes de gravar, nunca e usado sem passar pela validacao do form. Sem
 * isso o padrao seria "*" (libera qualquer site), o que e perigoso numa
 * API que carrega token de login.
 */
function detectarOrigemAtual(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host) ?? '';
    return $host !== '' ? ($https ? 'https' : 'http') . "://{$host}" : '';
}

/**
 * Confere se o valor informado e um par esquema+host valido (sem caminho,
 * sem barra final). Devolve null se valido ou a mensagem de erro traduzida.
 *
 * @param array<string, string> $T
 */
function validarOrigemCors(string $origem, array $T): ?string
{
    if (!preg_match('#^https?://[a-zA-Z0-9.\-]+(:\d+)?$#', $origem)) {
        return $T['err_cors'];
    }
    return null;
}

/**
 * Confere se a extensao PDO do motor escolhido esta habilitada no PHP.
 * Mensagem tecnica mantida em ingles (nomes de extensao/pacote/comandos
 * sao universais independente do idioma escolhido).
 */
if (!function_exists('verificarExtensaoPdo')) {
function verificarExtensaoPdo(string $driver): ?string
{
    $extensoes = [
        'mysql'  => 'pdo_mysql',
        'pgsql'  => 'pdo_pgsql',
        'sqlsrv' => 'pdo_sqlsrv',
        'sqlite' => 'pdo_sqlite',
    ];
    $nomesMotor = [
        'mysql'  => 'MySQL/MariaDB',
        'pgsql'  => 'PostgreSQL',
        'sqlsrv' => 'SQL Server',
        'sqlite' => 'SQLite',
    ];
    $extensao = $extensoes[$driver] ?? null;
    if ($extensao === null) {
        return null;
    }
    if (!extension_loaded('pdo') || !extension_loaded($extensao)) {
        $pacoteApt = 'php-' . str_replace('pdo_', '', $extensao);
        return "The \"{$extensao}\" PHP extension is not enabled on this server -- without it, "
            . "PHP cannot connect to {$nomesMotor[$driver]}. Ask your infrastructure team to enable "
            . "this extension (on Debian/Ubuntu: \"sudo apt install {$pacoteApt}\" + restart PHP-FPM/Apache; "
            . "on Windows/XAMPP, uncomment \"extension={$extensao}\" in php.ini and restart the server).";
    }
    return null;
}
}

/**
 * @param  array<string, mixed>                   $d
 * @return array{string, string|null, string|null}
 */
function dsnParaTeste(array $d): array
{
    $driver = (string) ($d['db_driver'] ?? 'mysql');
    switch ($driver) {
        case 'mysql':
            $dsn = "mysql:host={$d['db_host']};port={$d['db_port']};dbname={$d['db_name']};charset=utf8mb4";
            return [$dsn, (string) ($d['db_user'] ?? 'root'), (string) ($d['db_password'] ?? '')];
        case 'pgsql':
            $dsn = "pgsql:host={$d['db_host']};port={$d['db_port']};dbname={$d['db_name']}";
            return [$dsn, (string) ($d['db_user'] ?? 'postgres'), (string) ($d['db_password'] ?? '')];
        case 'sqlsrv':
            $dsn = "sqlsrv:Server={$d['db_host']},{$d['db_port']};Database={$d['db_name']};LoginTimeout=5";
            return [$dsn, (string) ($d['db_user'] ?? 'sa'), (string) ($d['db_password'] ?? '')];
        case 'sqlite':
            return ["sqlite:" . caminhoSqlitePadrao(), null, null];
        default:
            throw new RuntimeException('Invalid database engine. Choose MySQL, PostgreSQL, SQL Server or SQLite.');
    }
}

/**
 * @param array<string, mixed>  $d
 * @param array<string, string> $T
 */
function testarConexaoDireta(array $d, array $T): void
{
    $driver = (string) ($d['db_driver'] ?? 'mysql');
    $avisoExtensao = verificarExtensaoPdo($driver);
    if ($avisoExtensao !== null) {
        throw new RuntimeException($avisoExtensao);
    }
    [$dsn, $user, $pass] = dsnParaTeste($d);

    $opcoes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if ($driver !== 'sqlsrv') {
        $opcoes[PDO::ATTR_TIMEOUT] = 5;
    }
    new PDO($dsn, $user, $pass, $opcoes);
}

/**
 * Remove o conteudo da pasta setup/ e, por fim (depois da resposta ja ter
 * sido enviada ao navegador), a propria pasta.
 */
/** @param array<string, string> $T */
function apagarPastaSetup(string $pasta, array $T): ?string
{
    try {
        $itens = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($itens as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        register_shutdown_function(static function () use ($pasta): void {
            @rmdir($pasta);
        });
        return null;
    } catch (Throwable $e) {
        return sprintf($T['err_cleanup'], $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Ja instalado -- o assistente se recusa a rodar de novo.
// ---------------------------------------------------------------------------
if ($jaInstalado) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $htmlLang = $lang === 'en' ? 'en' : 'pt-BR';
    ?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($T['already_tab'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/css/style.css">
</head>
<body data-theme="light">
<div class="login-screen">
  <div class="login-card" style="max-width:460px;text-align:center">
    <div style="text-align:right;margin-bottom:12px">
      <a href="?lang=<?= $T['lang_other_code'] ?>" style="font-size:12px;color:var(--muted);text-decoration:none;border:1px solid var(--border);border-radius:6px;padding:3px 9px"><?= $T['lang_other_label'] ?></a>
    </div>
    <div class="mark" style="margin-bottom:18px">
      <svg viewBox="0 0 24 24" width="46" height="46" fill="none" stroke="#A9802E" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.7 2.7L16 9.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
    <h2 style="margin:0 0 10px;font-size:18px"><?= htmlspecialchars($T['already_h2'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p style="color:var(--muted);font-size:13.5px;line-height:1.6;margin:0 0 18px"><?= $T['already_p1'] ?></p>
    <p style="color:var(--muted);font-size:13.5px;line-height:1.6;margin:0 0 20px"><?= $T['already_p2'] ?></p>
    <a class="btn btn-primary" style="width:100%;justify-content:center" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars($T['go_portal'], ENT_QUOTES, 'UTF-8') ?></a>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Endpoint AJAX -- "Testar conexao" antes de instalar de fato.
// ---------------------------------------------------------------------------
if (($_GET['acao'] ?? '') === 'testar' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $corpo = json_decode((string) file_get_contents('php://input'), true);
    $corpo = is_array($corpo) ? $corpo : [];
    try {
        testarConexaoDireta($corpo, $T);
        echo json_encode(['ok' => true, 'mensagem' => $T['conn_ok']]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Envio do formulario -- valida, testa a conexao, grava config.php, cria
// as tabelas, cria o admin e limpa a pasta setup/.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($valores as $campo => $padrao) {
        $valores[$campo] = trim((string) ($_POST[$campo] ?? $padrao));
    }
    $adminSenha = (string) ($_POST['admin_password'] ?? '');
    $adminSenhaConfirma = (string) ($_POST['admin_password_confirm'] ?? '');
    $driver = $valores['db_driver'];
    $corsPermitirQualquer = !empty($_POST['cors_permitir_qualquer']);

    if ($valores['project_title'] === '') {
        $erros[] = $T['err_title'];
    }
    if (!in_array($driver, ['mysql', 'pgsql', 'sqlsrv', 'sqlite'], true)) {
        $erros[] = $T['err_driver'];
    }
    if ($driver !== 'sqlite') {
        $obrigatorios = [
            'db_host' => $T['f_host'],
            'db_port' => $T['f_port'],
            'db_name' => $T['f_dbname'],
            'db_user' => $T['f_user'],
        ];
        foreach ($obrigatorios as $campo => $rotulo) {
            if ($valores[$campo] === '') {
                $erros[] = sprintf($T['err_field'], $rotulo);
            }
        }
    }
    if ($valores['admin_username'] === '') {
        $erros[] = $T['err_login'];
    }
    $erroSenhaAdmin = avaliarForcaSenha($adminSenha);
    if ($erroSenhaAdmin !== null) {
        $erros[] = $erroSenhaAdmin;
    }
    if ($adminSenha !== $adminSenhaConfirma) {
        $erros[] = $T['err_pass_match'];
    }

    if ($corsPermitirQualquer) {
        $valores['cors_allowed_origin'] = '*';
    } else {
        $valores['cors_allowed_origin'] = rtrim($valores['cors_allowed_origin'], '/');
        $erroCors = validarOrigemCors($valores['cors_allowed_origin'], $T);
        if ($erroCors !== null) {
            $erros[] = $erroCors;
        }
    }

    if (!$erros) {
        try {
            testarConexaoDireta($valores, $T);
        } catch (Throwable $e) {
            $erros[] = $T['err_conn'] . $e->getMessage();
        }
    }

    if (!$erros) {
        $configArray = [
            'project_title'       => $valores['project_title'],
            'table_prefix'        => sanitizarPrefixoTabela($valores['table_prefix']),
            'db_driver'           => $driver,
            'db_host'             => $valores['db_host'],
            'db_port'             => $valores['db_port'],
            'db_name'             => $valores['db_name'],
            'db_user'             => $valores['db_user'],
            'db_password'         => $valores['db_password'],
            'db_sqlite_path'      => $driver === 'sqlite' ? caminhoSqlitePadrao() : '',
            'secret_key'          => bin2hex(random_bytes(32)),
            'token_expire_minutes' => 480,
            'cors_allowed_origin' => $valores['cors_allowed_origin'],
            'app_debug'           => false,
        ];

        $conteudoConfig = "<?php\n\n/**\n * Gerado automaticamente pelo assistente de instalacao em "
            . date('Y-m-d H:i:s') . ".\n * Este arquivo NAO entra no controle de versao -- veja .gitignore.\n */\n\nreturn "
            . var_export($configArray, true) . ";\n";

        $gravou = @file_put_contents($configPath, $conteudoConfig);

        if ($gravou === false) {
            $erros[] = $T['err_config'];
        } else {
            try {
                require_once __DIR__ . '/../backend/db.php';
                require_once __DIR__ . '/../backend/auth.php';

                migrate();

                $pdo = db();
                $tabelaUsuarios = quoteIdent(tableName('usuarios'));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabelaUsuarios} WHERE " . quoteIdent('username') . ' = ?');
                $stmt->execute([$valores['admin_username']]);
                $agora = date('Y-m-d H:i:s');
                $nomeAdmin = $valores['admin_nome'] !== '' ? $valores['admin_nome'] : $valores['admin_username'];

                if ((int) $stmt->fetchColumn() === 0) {
                    $colunas = implode(', ', array_map('quoteIdent', [
                        'username', 'nome_completo', 'role', 'password_hash', 'criado_em', 'atualizado_em',
                    ]));
                    $sql = "INSERT INTO {$tabelaUsuarios} ({$colunas}) VALUES (?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([
                        $valores['admin_username'],
                        $nomeAdmin,
                        'master',
                        hashPassword($adminSenha),
                        $agora,
                        $agora,
                    ]);
                } else {
                    $sql = "UPDATE {$tabelaUsuarios} SET " . quoteIdent('nome_completo') . ' = ?, ' .
                        quoteIdent('role') . ' = ?, ' . quoteIdent('password_hash') . ' = ?, ' .
                        quoteIdent('atualizado_em') . ' = ? WHERE ' . quoteIdent('username') . ' = ?';
                    $pdo->prepare($sql)->execute([
                        $nomeAdmin,
                        'master',
                        hashPassword($adminSenha),
                        $agora,
                        $valores['admin_username'],
                    ]);
                }

                $sucesso = true;
                $avisoLimpeza = apagarPastaSetup(__DIR__, $T);
            } catch (Throwable $e) {
                @unlink($configPath);
                $erros[] = $T['err_db'] . $e->getMessage();
            }
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
$tituloAba = $sucesso ? $T['tab_success'] : $T['tab_install'];
$htmlLang   = $lang === 'en' ? 'en' : 'pt-BR';
$langAction = '?lang=' . $lang;
?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($tituloAba, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&family=Datatype:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/css/style.css">
<style>
  body{margin:0}
  .setup-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--login-grad);padding:28px 16px}
  .setup-card{background:var(--surface);border-radius:16px;width:100%;max-width:560px;padding:34px 36px;box-shadow:0 30px 80px rgba(0,0,0,.25)}
  .setup-head{display:flex;flex-direction:column;align-items:center;margin-bottom:22px;text-align:center}
  .setup-head h1{font-size:18px;margin:14px 0 4px}
  .setup-head p{font-size:13px;color:var(--muted);margin:0;max-width:420px;line-height:1.5}
  .setup-erros{background:var(--red-soft);color:var(--red);border-radius:var(--r-sm);padding:12px 14px;font-size:12.5px;margin-bottom:18px;line-height:1.6}
  .setup-erros ul{margin:0;padding-left:18px}
  .setup-section{margin-bottom:18px}
  .setup-section h2{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);margin:0 0 12px;font-weight:600}
  .setup-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px}
  .setup-grid .span2{grid-column:1 / -1}
  .conn-status{font-size:12px;margin-top:8px;display:none;padding:8px 10px;border-radius:var(--r-sm)}
  .conn-status.ok{display:block;background:var(--green-soft);color:var(--green)}
  .conn-status.fail{display:block;background:var(--red-soft);color:var(--red)}
  .setup-actions{display:flex;gap:10px;margin-top:22px}
  .setup-actions .btn{flex:1;justify-content:center}
  .sucesso-icone{width:52px;height:52px;color:var(--green);margin:0 auto 14px}
  .lang-toggle{text-align:right;margin-bottom:14px}
  .lang-toggle a{font-size:12px;color:var(--muted);text-decoration:none;border:1px solid var(--border);border-radius:6px;padding:3px 9px}
  .lang-toggle a:hover{color:var(--text);border-color:var(--accent)}
</style>
</head>
<body data-theme="light">
<div class="setup-wrap">
  <div class="setup-card">
    <div class="lang-toggle">
      <a href="<?= $langAction === '?lang=pt' ? '?lang=en' : '?lang=pt' ?>"><?= $T['lang_other_label'] ?></a>
    </div>

    <?php if ($sucesso): ?>
      <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
        try { localStorage.removeItem('gov_token'); } catch (e) {}
      </script>
      <div style="text-align:center">
        <svg class="sucesso-icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.7 2.7L16 9.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <h1 style="font-size:19px;margin:0 0 8px"><?= htmlspecialchars($T['success_h1'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p style="color:var(--muted);font-size:13.5px;line-height:1.6;margin:0 0 18px">
          <?= htmlspecialchars(sprintf($T['success_user'], $valores['admin_username']), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php if ($avisoLimpeza !== null): ?>
          <div class="setup-erros" style="text-align:left;margin-bottom:18px"><?= htmlspecialchars($avisoLimpeza, ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
          <p style="color:var(--muted);font-size:12.5px;margin:0 0 18px"><?= htmlspecialchars($T['success_cleanup'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <a class="btn btn-primary" style="width:100%" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars($T['enter_portal'], ENT_QUOTES, 'UTF-8') ?></a>
      </div>

    <?php else: ?>
      <div class="setup-head">
        <svg viewBox="0 0 32 32" width="44" height="44" fill="none" stroke="var(--accent)" stroke-width="1.6">
          <path d="M16 3 L27 7.5 V16C27 23.5 22 28 16 29.5C10 28 5 23.5 5 16V7.5Z" stroke-linejoin="round" stroke-linecap="round"/>
          <ellipse cx="16" cy="13" rx="6" ry="2.4"/>
          <path d="M10 13v6c0 1.3 2.7 2.4 6 2.4s6-1.1 6-2.4v-6" stroke-linecap="round"/>
        </svg>
        <h1><?= htmlspecialchars($T['setup_h1'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($T['setup_intro'], ENT_QUOTES, 'UTF-8') ?></p>
      </div>

      <?php if ($erros): ?>
        <div class="setup-erros">
          <ul><?php foreach ($erros as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="POST" action="<?= htmlspecialchars($langAction, ENT_QUOTES, 'UTF-8') ?>" id="setupForm" autocomplete="off">
        <div class="setup-section">
          <h2><?= htmlspecialchars($T['s_project'], ENT_QUOTES, 'UTF-8') ?></h2>
          <div class="setup-grid">
            <div class="fld span2">
              <label><?= htmlspecialchars($T['lbl_title'], ENT_QUOTES, 'UTF-8') ?></label>
              <input type="text" name="project_title" placeholder="<?= htmlspecialchars($T['ph_title'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($valores['project_title'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="fld span2">
              <label><?= htmlspecialchars($T['lbl_prefix'], ENT_QUOTES, 'UTF-8') ?></label>
              <input type="text" name="table_prefix" placeholder="<?= htmlspecialchars($T['ph_prefix'], ENT_QUOTES, 'UTF-8') ?>" maxlength="20" value="<?= htmlspecialchars($valores['table_prefix'], ENT_QUOTES, 'UTF-8') ?>">
              <div style="font-size:12px;color:var(--muted);margin-top:5px;line-height:1.5"><?= $T['hint_prefix'] ?></div>
            </div>
            <div class="fld span2">
              <label><?= htmlspecialchars($T['lbl_cors'], ENT_QUOTES, 'UTF-8') ?></label>
              <input type="text" name="cors_allowed_origin" id="corsOrigin" placeholder="<?= htmlspecialchars($T['ph_cors'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($valores['cors_allowed_origin'], ENT_QUOTES, 'UTF-8') ?>">
              <div style="font-size:12px;color:var(--muted);margin-top:5px;line-height:1.5">
                <?= $T['hint_cors'] ?>
                <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-weight:normal;cursor:pointer">
                  <input type="checkbox" name="cors_permitir_qualquer" value="1" id="corsQualquer"> <?= htmlspecialchars($T['lbl_cors_any'], ENT_QUOTES, 'UTF-8') ?>
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="setup-section">
          <h2><?= htmlspecialchars($T['s_db'], ENT_QUOTES, 'UTF-8') ?></h2>
          <div class="setup-grid">
            <div class="fld span2">
              <label><?= htmlspecialchars($T['lbl_engine'], ENT_QUOTES, 'UTF-8') ?></label>
              <select name="db_driver" id="dbDriver">
                <option value="mysql" <?= $valores['db_driver'] === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                <option value="pgsql" <?= $valores['db_driver'] === 'pgsql' ? 'selected' : '' ?>>PostgreSQL</option>
                <option value="sqlsrv" <?= $valores['db_driver'] === 'sqlsrv' ? 'selected' : '' ?>>SQL Server</option>
                <option value="sqlite" <?= $valores['db_driver'] === 'sqlite' ? 'selected' : '' ?>><?= htmlspecialchars($T['opt_sqlite'], ENT_QUOTES, 'UTF-8') ?></option>
              </select>
            </div>

            <div id="camposServidor" style="display:contents">
              <div class="fld"><label><?= htmlspecialchars($T['f_host'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" name="db_host" id="dbHost" value="<?= htmlspecialchars($valores['db_host'], ENT_QUOTES, 'UTF-8') ?>"></div>
              <div class="fld"><label><?= htmlspecialchars($T['f_port'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" name="db_port" id="dbPort" value="<?= htmlspecialchars($valores['db_port'], ENT_QUOTES, 'UTF-8') ?>"></div>
              <div class="fld span2"><label><?= htmlspecialchars($T['f_dbname'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" name="db_name" id="dbName" value="<?= htmlspecialchars($valores['db_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
              <div class="fld"><label><?= htmlspecialchars($T['f_user'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" name="db_user" id="dbUser" value="<?= htmlspecialchars($valores['db_user'], ENT_QUOTES, 'UTF-8') ?>"></div>
              <div class="fld"><label><?= htmlspecialchars($T['lbl_pass'], ENT_QUOTES, 'UTF-8') ?></label><input type="password" name="db_password" id="dbPassword" value="<?= htmlspecialchars($valores['db_password'], ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>

            <div class="fld span2" id="campoSqlite" style="display:none">
              <div style="font-size:12.5px;color:var(--muted);line-height:1.6;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 12px"><?= $T['hint_sqlite'] ?></div>
            </div>
          </div>
          <button type="button" class="btn btn-ghost" id="btnTestar" style="margin-top:12px;width:100%;justify-content:center"><?= htmlspecialchars($T['btn_test'], ENT_QUOTES, 'UTF-8') ?></button>
          <div class="conn-status" id="connStatus"></div>
        </div>

        <div class="setup-section">
          <h2><?= htmlspecialchars($T['s_admin'], ENT_QUOTES, 'UTF-8') ?></h2>
          <div class="setup-grid">
            <div class="fld"><label><?= htmlspecialchars($T['lbl_login'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" name="admin_username" value="<?= htmlspecialchars($valores['admin_username'], ENT_QUOTES, 'UTF-8') ?>" required></div>
            <div class="fld"><label><?= htmlspecialchars($T['lbl_fullname'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" name="admin_nome" value="<?= htmlspecialchars($valores['admin_nome'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="fld">
              <label><?= htmlspecialchars($T['lbl_pass'], ENT_QUOTES, 'UTF-8') ?></label>
              <input type="password" name="admin_password" required minlength="8">
              <div style="font-size:11.5px;color:var(--muted);margin-top:5px"><?= htmlspecialchars($T['hint_pass'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="fld"><label><?= htmlspecialchars($T['lbl_confirm'], ENT_QUOTES, 'UTF-8') ?></label><input type="password" name="admin_password_confirm" required minlength="8"></div>
          </div>
        </div>

        <div class="setup-actions">
          <button type="submit" class="btn btn-primary" id="btnInstalar"><?= htmlspecialchars($T['btn_install'], ENT_QUOTES, 'UTF-8') ?></button>
        </div>
      </form>
    <?php endif; ?>

  </div>
</div>

<script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
(function () {
  var T = <?= json_encode([
    'testing'    => $T['js_testing'],
    'testConn'   => $T['btn_test'],
    'testFail'   => $T['js_test_fail'],
    'installing' => $T['js_installing'],
  ], JSON_UNESCAPED_UNICODE) ?>;
  var lang = <?= json_encode($lang) ?>;

  var sel = document.getElementById('dbDriver');
  var camposServidor = document.getElementById('camposServidor');
  var campoSqlite = document.getElementById('campoSqlite');
  var portaPadrao = <?= json_encode($portaPadrao, JSON_UNESCAPED_UNICODE) ?>;
  var dbPort = document.getElementById('dbPort');

  function aplicarMotor() {
    if (!sel) return;
    var ehSqlite = sel.value === 'sqlite';
    camposServidor.style.display = ehSqlite ? 'none' : 'contents';
    campoSqlite.style.display = ehSqlite ? 'block' : 'none';
    if (!ehSqlite && portaPadrao[sel.value] && dbPort) {
      dbPort.value = portaPadrao[sel.value];
    }
  }
  if (sel) {
    sel.addEventListener('change', aplicarMotor);
    aplicarMotor();
  }

  var btnTestar = document.getElementById('btnTestar');
  var connStatus = document.getElementById('connStatus');
  if (btnTestar) {
    btnTestar.addEventListener('click', function () {
      var dados = {
        db_driver:   sel.value,
        db_host:     document.getElementById('dbHost').value,
        db_port:     document.getElementById('dbPort').value,
        db_name:     document.getElementById('dbName').value,
        db_user:     document.getElementById('dbUser').value,
        db_password: document.getElementById('dbPassword').value
      };
      btnTestar.disabled = true;
      btnTestar.textContent = T.testing;
      connStatus.className = 'conn-status';
      connStatus.style.display = 'none';

      fetch('?lang=' + lang + '&acao=testar', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          connStatus.textContent = j.mensagem;
          connStatus.className = 'conn-status ' + (j.ok ? 'ok' : 'fail');
          connStatus.style.display = 'block';
        })
        .catch(function () {
          connStatus.textContent = T.testFail;
          connStatus.className = 'conn-status fail';
          connStatus.style.display = 'block';
        })
        .finally(function () {
          btnTestar.disabled = false;
          btnTestar.textContent = T.testConn;
        });
    });
  }

  var corsOrigin = document.getElementById('corsOrigin');
  var corsQualquer = document.getElementById('corsQualquer');
  function aplicarCors() {
    if (!corsOrigin || !corsQualquer) return;
    corsOrigin.disabled = corsQualquer.checked;
    corsOrigin.required = !corsQualquer.checked;
  }
  if (corsQualquer) {
    corsQualquer.addEventListener('change', aplicarCors);
    aplicarCors();
  }

  var form = document.getElementById('setupForm');
  if (form) {
    form.addEventListener('submit', function () {
      var btn = document.getElementById('btnInstalar');
      btn.disabled = true;
      btn.textContent = T.installing;
    });
  }
})();
</script>
</body>
</html>
