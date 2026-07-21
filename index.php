<?php

declare(strict_types=1);

/**
 * Ponto de entrada unico do portal -- sem framework, sem dependencia
 * (nada de Composer/vendor). Conforme a URL pedida:
 *
 *   /setup...      -> setup/index.php responde (assistente de instalacao)
 *   /api...        -> backend/api.php responde (JSON)
 *   /css|/js|/img.. -> serve o arquivo estatico direto
 *   qualquer outra  -> devolve esta pagina (SPA)
 *
 * Funciona tanto local ("php -S 0.0.0.0:8000 index.php", rodando a partir
 * da raiz do projeto) quanto em producao atras de Nginx/Apache/IIS (nesses
 * casos o servidor web normalmente ja serve css/js/img direto, sem nem
 * chamar o PHP -- ver DEPLOY.md; o bloco abaixo so e mesmo usado quando o
 * servidor delega tudo pro index.php).
 */

/**
 * Caminho-base do projeto na URL (ex.: "" se instalado na raiz do dominio,
 * ou "/gestao-dados" se instalado numa subpasta qualquer). Calculado
 * comparando a pasta deste arquivo com o DOCUMENT_ROOT do servidor web --
 * assim funciona em qualquer subpasta escolhida na instalacao, sem
 * precisar configurar nada na mao. De proposito NAO usamos SCRIPT_NAME
 * pra isso: em Nginx com "try_files ... /index.php" esse valor vem sempre
 * como "/index.php", mesmo instalado numa subpasta -- DOCUMENT_ROOT nao
 * tem esse problema em Apache, Nginx nem IIS.
 */
function caminhoBaseApp(): string
{
    // Usa SCRIPT_NAME (caminho URL do script, ex: /gestao/index.php) em vez de
    // comparar __DIR__ com DOCUMENT_ROOT. Em servidores FreeBSD e outros com
    // symlinks, PHP resolve __DIR__ para o caminho real (ex: /usr/local/www/nginx-dist/gestao)
    // mas Nginx passa DOCUMENT_ROOT sem resolver o symlink (/usr/local/www/nginx),
    // causando calculo errado do basePath e loop de redirect.
    $scriptName = rtrim(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    return ($dir === '' || $dir === '.') ? '' : $dir;
}

/**
 * Cabecalhos de seguranca enviados em TODA resposta dinamica do portal
 * (SPA, /api e arquivos estaticos servidos via PHP) -- defesa em
 * profundidade contra XSS, clickjacking e MIME-sniffing, recomendada pelo
 * OWASP e cobrada em qualquer avaliacao de seguranca de mercado.
 *
 * $cspNonce libera so os blocos de <script> inline que o projeto de fato
 * usa (o desta pagina e o do assistente de instalacao), sem precisar de
 * 'unsafe-inline' no script-src -- 'unsafe-inline' anularia boa parte da
 * protecao da CSP contra XSS, ja que deixaria QUALQUER <script> injetado
 * rodar tambem. style-src ainda usa 'unsafe-inline' porque o projeto usa
 * style="..." inline em varios lugares do HTML -- risco bem menor que
 * permitir script arbitrario.
 *
 * Duplicada em setup/index.php pelo mesmo motivo de caminhoBaseApp():
 * setup/ pode ser acessado direto pelo navegador, sem passar por este
 * arquivo, e precisa enviar os mesmos cabecalhos nesse caso tambem.
 */
if (!function_exists('enviarCabecalhosSeguranca')) {
function enviarCabecalhosSeguranca(string $cspNonce): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

    // HSTS so faz sentido (e so e seguro mandar) quando a conexao atual
    // ja e HTTPS -- mandar em HTTP simples nao tem efeito no navegador.
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-{$cspNonce}'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'");
}
}

$basePath = caminhoBaseApp();
$uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// Remove o prefixo da subpasta antes de comparar rotas -- o resto deste
// arquivo continua comparando com "/setup", "/api" etc. como se o projeto
// estivesse na raiz, mesmo quando na verdade esta em "/gestao-dados" (ou
// qualquer outra subpasta).
if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
    $uriPath = substr($uriPath, strlen($basePath));
    if ($uriPath === '') {
        $uriPath = '/';
    }
}

// Nonce novo a cada requisicao (nunca reaproveitado) -- e o que permite o
// <script> inline desta pagina rodar sem precisar de 'unsafe-inline' no
// script-src. Chamado ANTES de qualquer rota abaixo (/setup, /api,
// estaticos, SPA) pra cobrir todas as respostas dinamicas com um unico ponto.
$cspNonce = base64_encode(random_bytes(16));
enviarCabecalhosSeguranca($cspNonce);

// ---------------------------------------------------------------------------
// 0) Assistente de instalacao -- /setup sempre cai no instalador, mesmo que
//    o portal ja esteja configurado (nesse caso ele mesmo se recusa a rodar
//    de novo e orienta a remover a pasta).
// ---------------------------------------------------------------------------
if ($uriPath === '/setup' || str_starts_with($uriPath, '/setup/')) {
    if (is_file(__DIR__ . '/setup/index.php')) {
        require __DIR__ . '/setup/index.php';
        return;
    }
    // A pasta setup/ ja foi removida (limpeza automatica pos-instalacao) --
    // nao ha mais nada pra mostrar aqui, volta pro portal.
    header('Location: ' . $basePath . '/');
    exit;
}

// ---------------------------------------------------------------------------
// 1) API
// ---------------------------------------------------------------------------
if ($uriPath === '/api' || str_starts_with($uriPath, '/api/')) {
    require __DIR__ . '/backend/api.php';
    return;
}

// ---------------------------------------------------------------------------
// 2) Arquivos estaticos (css/js/img) -- precisa vir ANTES do redirecionamento
//    de "nao instalado" abaixo, porque a propria pagina do /setup carrega
//    css/style.css mesmo sem o portal estar configurado ainda.
// ---------------------------------------------------------------------------
$primeiroSegmento = explode('/', trim($uriPath, '/'))[0];
if (in_array($primeiroSegmento, ['css', 'js', 'img'], true)) {
    $raiz = realpath(__DIR__);
    $caminhoReal = realpath(__DIR__ . $uriPath);

    // Confere que o arquivo resolvido continua DENTRO da pasta do projeto
    // (protege contra "../../etc/passwd" e afins).
    if ($caminhoReal !== false && $raiz !== false && str_starts_with($caminhoReal, $raiz) && is_file($caminhoReal)) {
        $mimePorExtensao = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
        ];
        $extensao = strtolower((string) pathinfo($caminhoReal, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimePorExtensao[$extensao] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=3600');
        readfile($caminhoReal);
        return;
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Arquivo nao encontrado.';
    return;
}

// ---------------------------------------------------------------------------
// 2.1) Ainda nao instalado -- manda direto pro assistente em vez de mostrar
//      um portal sem banco configurado.
// ---------------------------------------------------------------------------
if (!is_file(__DIR__ . '/conf/config.php')) {
    header('Location: ' . $basePath . '/setup/');
    exit;
}

// ---------------------------------------------------------------------------
// 3) SPA -- a mesma pagina pra qualquer outra rota (o JS decide o que mostrar)
// ---------------------------------------------------------------------------
require __DIR__ . '/backend/db.php';
$tituloProjeto = projectTitle();
$tituloAtributo = htmlspecialchars($tituloProjeto, ENT_QUOTES, 'UTF-8');
$logoCustomizado = projectLogoDataUri();
if ($logoCustomizado !== null) {
    // Logo enviada pelo administrador em Administracao > Configuracoes do
    // projeto -- ja vem como data URI (base64), sem necessidade de salvar
    // arquivo no servidor. Ver projectLogoDataUri() em backend/db.php.
    $logoSvg = '<img class="logo logo-custom" src="' . htmlspecialchars($logoCustomizado, ENT_QUOTES, 'UTF-8') . '" alt="' . $tituloAtributo . '">';
} else {
    // Logo padrão: cilindro de banco de dados + engrenagem com gráfico de barras.
    // Design original -- sem reuso de arte de terceiros.
    $logoSvg = '<svg class="logo" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . $tituloAtributo . '">'
        . '<rect x="13" y="22" width="114" height="130" fill="#2C2C2C"/>'
        . '<ellipse cx="70" cy="152" rx="57" ry="17" fill="#2C2C2C"/>'
        . '<ellipse cx="70" cy="22" rx="57" ry="17" fill="#2C2C2C"/>'
        . '<line x1="13" y1="65.3" x2="127" y2="65.3" stroke="#FFFFFF" stroke-width="2.5"/>'
        . '<line x1="13" y1="108.7" x2="127" y2="108.7" stroke="#FFFFFF" stroke-width="2.5"/>'
        . '<circle cx="30" cy="43.7" r="6.5" fill="#FFFFFF"/>'
        . '<circle cx="30" cy="87.0" r="6.5" fill="#FFFFFF"/>'
        . '<circle cx="30" cy="130.3" r="6.5" fill="#FFFFFF"/>'
        . '<path d="M 179.28,145.68 L 193.58,146.08 L 193.58,157.92 L 179.28,158.32 L 175.76,166.82 L 185.59,177.22 L 177.22,185.59 L 166.82,175.76 L 158.32,179.28 L 157.92,193.58 L 146.08,193.58 L 145.68,179.28 L 137.18,175.76 L 126.78,185.59 L 118.41,177.22 L 128.24,166.82 L 124.72,158.32 L 110.42,157.92 L 110.42,146.08 L 124.72,145.68 L 128.24,137.18 L 118.41,126.78 L 126.78,118.41 L 137.18,128.24 L 145.68,124.72 L 146.08,110.42 L 157.92,110.42 L 158.32,124.72 L 166.82,128.24 L 177.22,118.41 L 185.59,126.78 L 175.76,137.18 Z" fill="#2C2C2C" stroke="#FFFFFF" stroke-width="5" stroke-linejoin="round" style="paint-order:stroke fill"/>'
        . '<circle cx="152" cy="152" r="21" fill="#FFFFFF"/>'
        . '<rect x="138.0" y="153" width="7" height="10" rx="1" fill="#2C2C2C"/>'
        . '<rect x="148.5" y="149" width="7" height="14" rx="1" fill="#2C2C2C"/>'
        . '<rect x="159.0" y="143" width="7" height="20" rx="1" fill="#2C2C2C"/>'
        . '<rect x="137.0" y="163" width="30.0" height="2" fill="#2C2C2C"/>'
        . '</svg>';
}
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $tituloAtributo ?></title>
<script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
  // Caminho-base do projeto (ver caminhoBaseApp() em index.php) -- js/api.js
  // usa isso pra montar a URL da API mesmo instalado numa subpasta.
  window.APP_BASE = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;
  // Nome do projeto (ver projectTitle() em backend/db.php) -- usado no
  // cabeçalho dos PDFs exportados em js/app.js (exportPdf).
  window.NOME_PROJETO = <?= json_encode($tituloProjeto, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  // Logo do projeto: data-URI se customizada, ou caminho do SVG padrão.
  // Usado em exportPdf (js/app.js) pra desenhar a logo no cabeçalho do PDF.
  window.LOGO_PROJETO = <?= json_encode($logoCustomizado ?? ($basePath . '/img/logo.svg'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  // Aplica o tema salvo ANTES de pintar a página, pra não dar "flash" de tema errado.
  (function(){
    var t = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', t);
    var c = localStorage.getItem('colorTheme') || 'padrao';
    document.documentElement.setAttribute('data-color', c);
    var s = localStorage.getItem('sideStyle') || 'claro';
    document.documentElement.setAttribute('data-side', s);
  })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?: time() ?>">
</head>
<body>

<!-- ============ TELA DE LOGIN ============ -->
<div class="login-screen" id="loginScreen">
  <div class="login-card">
    <div class="mark">
      <?= $logoSvg ?>
      <span class="login-project-name"><?= $tituloAtributo ?></span>
    </div>
    <div class="login-err" id="loginErr"></div>
    <form id="loginForm">
      <div class="fld">
        <label data-i18n>Usuário</label>
        <input type="text" id="loginUser" autocomplete="username" required>
      </div>
      <div class="fld">
        <label data-i18n>Senha</label>
        <input type="password" id="loginPass" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary" id="loginBtn" data-i18n>Entrar</button>
    </form>
    <form id="mfaForm" style="display:none">
      <div class="login-mfa-hint" data-i18n>Enviamos um código de verificação para o seu e-mail. Ele expira em 10 minutos.</div>
      <div class="fld">
        <label data-i18n>Código de verificação</label>
        <input type="text" id="mfaCodigo" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
      </div>
      <button type="submit" class="btn btn-primary" id="mfaBtn" data-i18n>Verificar</button>
      <button type="button" class="btn btn-ghost" id="mfaReenviarBtn" data-i18n>Reenviar código</button>
    </form>
    <div class="login-hint" data-i18n>Esqueceu sua senha? Fale com o administrador deste portal.</div>
  </div>
</div>

<!-- ============ APP ============ -->
<div class="app" id="appShell" style="display:none">
  <aside class="side" id="side">
    <div class="brand">
      <div class="brand-row">
        <div class="mark"><?= $logoSvg ?><span class="brand-title"><?= $tituloAtributo ?></span></div>
        <button class="side-collapse-btn" id="sideCollapseBtn" title="Recolher menu" aria-label="Recolher menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6l6 6" /></svg></button>
      </div>
    </div>
    <nav class="side-nav" id="nav">
      <button class="nav-item active" data-view="overview" title="Dashboard" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1" /> <path d="M5 16h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1" /> <path d="M15 12h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1" /> <path d="M15 4h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1" /></svg><span class="lbl" data-i18n>Dashboard</span></button>
      <button class="nav-item" data-view="acessos" title="Acessos" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg><span class="lbl" data-i18n>Acessos</span></button>
      <button class="nav-item" data-view="mudancas" title="Mudanças" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /> <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg><span class="lbl" data-i18n>Mudanças</span></button>
      <button class="nav-item" data-view="backup" title="Backup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" /> <path d="M4 6v6a8 3 0 0 0 16 0v-6" /> <path d="M4 12v6a8 3 0 0 0 16 0v-6" /></svg><span class="lbl">Backup</span></button>
      <button class="nav-item" data-view="jobs" title="Jobs"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 9l3 3l-3 3" /> <path d="M13 15l3 0" /> <path d="M3 6a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2l0 -12" /></svg><span class="lbl">Jobs</span></button>
      <button class="nav-item" data-view="dicionario" title="Dicionário" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /> <path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0" /> <path d="M3 6l0 13" /> <path d="M12 6l0 13" /> <path d="M21 6l0 13" /></svg><span class="lbl" data-i18n>Dicionário</span></button>
      <button class="nav-item" data-view="integracoes" title="Integrações" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10h14l-4 -4" /> <path d="M17 14h-14l4 4" /></svg><span class="lbl" data-i18n>Integrações</span></button>
      <button class="nav-item" data-view="relatorios" title="Relatórios" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4" /> <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /> <path d="M9 9l1 0" /> <path d="M9 13l6 0" /> <path d="M9 17l6 0" /></svg><span class="lbl" data-i18n>Relatórios</span></button>
      <button class="nav-item" data-view="documentacao" title="Documentação" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4h11a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-11a1 1 0 0 1 -1 -1v-14a1 1 0 0 1 1 -1m3 0v18" /> <path d="M13 8l2 0" /> <path d="M13 12l2 0" /></svg><span class="lbl" data-i18n>Documentação</span></button>
      <div class="nav-label lbl" id="navAdminLabel" style="display:none" data-i18n>Administração</div>
      <button class="nav-item" data-view="cadastro" id="navCadastro" title="Cadastro" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3l8 -8" /> <path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" /></svg><span class="lbl" data-i18n>Cadastro</span></button>
      <button class="nav-item" data-view="usuarios" id="navUsuarios" title="Usuários" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /> <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /> <path d="M16 3.13a4 4 0 0 1 0 7.75" /> <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" /></svg><span class="lbl" data-i18n>Usuários</span></button>
      <button class="nav-item" data-view="roles" id="navRoles" title="Roles" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg><span class="lbl">Roles</span></button>
      <button class="nav-item" data-view="email" id="navEmail" title="E-mail" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10" /> <path d="M3 7l9 6l9 -6" /></svg><span class="lbl" data-i18n>E-mail</span></button>
      <button class="nav-item" data-view="configProjeto" id="navConfigProjeto" title="Configurações" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065" /> <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /></svg><span class="lbl" data-i18n>Configurações</span></button>
      <button class="nav-item" data-view="auditoria" id="navAuditoria" title="Auditoria" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06" /> <path d="M15 19l2 2l4 -4" /></svg><span class="lbl" data-i18n>Auditoria</span></button>
      <button class="nav-item" data-view="seguranca" id="navSeguranca" title="Segurança" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /> <path d="M11 11a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /> <path d="M12 12l0 2.5" /></svg><span class="lbl">Segurança</span></button>
    </nav>
    <div class="side-foot">
      <div class="user-panel">
      <div class="user-chip" id="userChip" title="Editar meu perfil" data-i18n-title style="cursor:pointer">
        <div class="av js-user-av" id="userAvatar">?</div>
        <div class="info lbl">
          <div class="nm js-user-nm" id="userName">—</div>
          <div class="rl js-user-mail"></div>
        </div>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="uc-chev lbl" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6l6 -6" /></svg>
      </div>
      <button id="changePassBtn" class="up-act" title="Trocar senha" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6" /> <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /> <path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg><span class="lbl" data-i18n>Trocar senha</span></button>
      <button id="logoutBtn" class="up-act" title="Sair" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /> <path d="M9 12h12l-3 -3" /> <path d="M18 15l3 -3" /></svg><span class="lbl" data-i18n>Sair</span></button>
      </div>
      <div id="dbInfo" class="db-info lbl" style="display:none" title=""></div>
    </div>
  </aside>

  <div class="main">
    <div class="mfa-banner" id="mfaBanner" hidden>
      <div class="mfa-banner-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg></div>
      <div class="mfa-banner-txt"><strong data-i18n>Proteja sua conta</strong><span data-i18n>Ative a verificação em duas etapas para aumentar a segurança do seu acesso.</span></div>
      <button class="btn btn-primary" data-act="irMfaBannerPerfil" data-i18n>Ativar agora</button>
    </div>
    <div class="topbar">
      <button class="btn btn-ghost menu-toggle" id="menuToggle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6l16 0" /> <path d="M4 12l16 0" /> <path d="M4 18l16 0" /></svg></button>
      <div class="view-ico" id="viewIcon" aria-hidden="true"></div>
      <div class="tt"><h2 id="viewTitle">Dashboard</h2><div class="sub" id="viewSub">Resumo do seu ambiente de dados</div></div>
      <div class="search" id="searchBox" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /> <path d="M21 21l-6 -6" /></svg><input type="text" id="search" placeholder="Buscar..." data-i18n-ph></div>
      <button class="btn btn-ghost icon-only" id="themeToggle" title="Alternar tema claro/escuro" data-i18n-title aria-label="Alternar tema">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 12a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /> <path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7" /></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454l0 .008" /></svg>
      </button>
      <button class="btn btn-ghost lang-toggle" id="langToggle" title="Mudar idioma / Switch language" aria-label="Mudar idioma"><span id="langToggleLabel">EN</span></button>
      <div class="topbar-user" id="userChipTop" title="Editar meu perfil" data-i18n-title>
        <div class="av js-user-av">?</div>
        <div class="tu-info"><div class="tu-nm js-user-nm">—</div><div class="tu-ml js-user-mail"></div></div>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="tu-chev" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6l6 -6" /></svg>
      </div>
    </div>
    <div class="content-head"><button class="btn btn-primary" id="addBtn" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5l0 14" /> <path d="M5 12l14 0" /></svg><span data-i18n>Adicionar</span></button></div>
    <div class="content" id="content"></div>
  </div>
</div>

<div class="overlay" id="overlay">
  <div class="modal">
    <div class="modal-h"><h3 id="modalTitle">Novo registro</h3><button class="x" id="modalClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" id="modalBody"></div>
    <div class="modal-f"><button class="btn btn-ghost" id="modalCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="modalSave" data-i18n>Salvar registro</button></div>
  </div>
</div>

<div class="overlay" id="pwOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-h"><h3 data-i18n>Trocar senha</h3><button class="x" id="pwClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Senha atual</label><input type="password" id="pwAtual"></div>
      <div class="fld"><label data-i18n>Nova senha</label><input type="password" id="pwNova"></div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="pwCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="pwSave" data-i18n>Salvar nova senha</button></div>
  </div>
</div>

<div class="overlay" id="mfaToggleOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-h"><h3 data-i18n>Verificação em duas etapas</h3><button class="x" id="mfaToggleClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Senha atual</label><input type="password" id="mfaTogglePass"></div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="mfaToggleCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="mfaToggleSave" data-i18n>Confirmar</button></div>
  </div>
</div>

<div class="overlay" id="userOverlay">
  <div class="modal" style="max-width:460px">
    <div class="modal-h"><h3 id="userModalTitle" data-i18n>Novo usuário</h3><button class="x" id="userClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Login</label><input type="text" id="userLogin"></div>
      <div class="fld"><label data-i18n>Nome completo</label><input type="text" id="userNome"></div>
      <div class="fld"><label data-i18n>E-mail</label><input type="email" id="userEmail"></div>
      <div class="fld">
        <label data-i18n>Senha</label>
        <input type="password" id="userSenha" placeholder="">
        <div id="userSenhaHint" style="font-size:11.5px;color:var(--muted);margin-top:4px" data-i18n>Mínimo 8 caracteres, com letra e número.</div>
      </div>
      <div class="fld" id="userAtivoWrap" style="display:none">
        <label data-i18n>Status da conta</label>
        <select id="userAtivo"><option value="1" data-i18n>Ativo</option><option value="0" data-i18n>Desativado</option></select>
      </div>
      <div id="userRoleWrap">
        <div class="fld"><label data-i18n>Papel</label><select id="userRoleSel"><option value="leitura" data-i18n>Leitura</option><option value="escrita" data-i18n>Escrita</option><option value="admin" data-i18n>Administrador</option><option value="master" data-i18n>Master</option></select></div>
        <div class="fld" id="userModulosWrap">
          <label data-i18n>Artefatos permitidos</label>
          <div class="chk-group" id="userModulosChk"></div>
        </div>
      </div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="userCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="userSave" data-i18n>Criar usuário</button></div>
  </div>
</div>

<!-- Overlay: troca obrigatória de senha (primeiro login / senha redefinida pelo admin) -->
<div class="overlay" id="mustChangeOverlay" style="z-index:1100">
  <div class="modal" style="max-width:400px">
    <div class="modal-h"><h3 data-i18n>Defina sua senha</h3></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <p style="margin:0 0 8px;color:var(--muted);font-size:13.5px" data-i18n>Por segurança, você precisa criar uma nova senha antes de continuar.</p>
      <div class="fld"><label data-i18n>Nova senha</label><input type="password" id="mustChangeSenha"></div>
      <div class="fld"><label data-i18n>Confirmar senha</label><input type="password" id="mustChangeSenhaConf"></div>
      <div id="mustChangeErr" class="login-err"></div>
    </div>
    <div class="modal-f"><button class="btn btn-primary" id="mustChangeSave" data-i18n>Salvar senha</button></div>
  </div>
</div>

<div class="overlay" id="rolesOverlay">
  <div class="modal" style="max-width:460px">
    <div class="modal-h"><h3 data-i18n>Editar permissões</h3><button class="x" id="rolesClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Usuário</label><input type="text" id="rolesUserLabel" disabled></div>
      <div class="fld"><label data-i18n>Papel</label><select id="rolesRoleSel"><option value="leitura" data-i18n>Leitura</option><option value="escrita" data-i18n>Escrita</option><option value="admin" data-i18n>Administrador</option><option value="master" data-i18n>Master</option></select></div>
      <div class="fld" id="rolesModulosWrap">
        <label data-i18n>Artefatos permitidos</label>
        <div class="chk-group" id="rolesModulosChk"></div>
      </div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="rolesCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="rolesSave" data-i18n>Salvar permissões</button></div>
  </div>
</div>

<div class="overlay" id="emailOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-h"><h3 data-i18n>Enviar relatório por e-mail</h3><button class="x" id="emailClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>E-mail de destino</label><input type="email" id="emailPara" placeholder="nome@empresa.com"></div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="emailCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="emailSend" data-i18n>Enviar</button></div>
  </div>
</div>

<div class="overlay" id="bulkObsOverlay">
  <div class="modal" style="max-width:460px">
    <div class="modal-h"><h3 data-i18n>Atualizar observações em massa</h3><button class="x" id="bulkObsClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <p id="bulkObsInfo" style="margin:0;font-size:13px;color:var(--muted)"></p>
      <div class="fld"><label data-i18n>Observações</label><textarea id="bulkObsTexto" rows="5"></textarea></div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="bulkObsCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="bulkObsSave" data-i18n>Aplicar a todos</button></div>
  </div>
</div>

<div class="overlay" id="importOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-h"><h3 data-i18n>Importando registros</h3></div>
    <div class="modal-b" style="grid-template-columns:1fr;gap:12px">
      <div class="import-progress-track"><div class="import-progress-fill" id="importProgressFill" style="width:0%"></div></div>
      <p id="importProgressTxt" style="margin:0;font-size:13px;color:var(--muted)">0</p>
    </div>
  </div>
</div>

<div class="overlay" id="excluirOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-h"><h3 data-i18n>Excluindo registros</h3></div>
    <div class="modal-b" style="grid-template-columns:1fr;gap:12px">
      <div class="import-progress-track"><div class="import-progress-fill" id="excluirProgressFill" style="width:0%"></div></div>
      <p id="excluirProgressTxt" style="margin:0;font-size:13px;color:var(--muted)">0</p>
    </div>
  </div>
</div>

<div class="overlay" id="importResultOverlay">
  <div class="modal" style="max-width:440px">
    <div class="modal-h"><h3 data-i18n>Importação concluída</h3><button class="x" id="importResultClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr;gap:14px">
      <div class="import-result-grid">
        <div class="import-result-card ok">
          <span class="import-result-num" id="importResultOkNum">0</span>
          <span class="import-result-lbl" data-i18n>Importados</span>
        </div>
        <div class="import-result-card err">
          <span class="import-result-num" id="importResultErrNum">0</span>
          <span class="import-result-lbl" data-i18n>Não importados</span>
        </div>
      </div>
      <div class="import-result-errs" id="importResultErrs" hidden></div>
    </div>
    <div class="modal-f"><button class="btn btn-primary" id="importResultOkBtn" data-i18n>Fechar</button></div>
  </div>
</div>


<div class="drawer-overlay" id="cadastroDrawerOverlay">
  <div class="drawer">
    <div class="drawer-h">
      <div class="cadastro-ico" id="cadastroDrawerIco"></div>
      <h3 id="cadastroDrawerTitle">—</h3>
      <button class="x" id="cadastroDrawerClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /> <path d="M6 6l12 12" /></svg></button>
    </div>
    <div class="drawer-b" id="cadastroDrawerBody"></div>
  </div>
</div>

<div class="toast" id="toast"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10" /></svg><span id="toastMsg"></span></div>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/api.js?v=<?= @filemtime(__DIR__ . '/js/api.js') ?: time() ?>"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/vendor/jspdf.umd.min.js"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/vendor/jspdf.plugin.autotable.min.js"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body>
</html>
