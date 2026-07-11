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
    $raizApp = str_replace('\\', '/', __DIR__);
    $raizDocumento = str_replace('\\', '/', rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\'));
    if ($raizDocumento === '' || !str_starts_with($raizApp, $raizDocumento)) {
        return '';
    }
    return rtrim(substr($raizApp, strlen($raizDocumento)), '/');
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
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&family=Datatype:wght@400;500;600&display=swap" rel="stylesheet">
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
        <button class="side-collapse-btn" id="sideCollapseBtn" title="Recolher menu" aria-label="Recolher menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>
      </div>
    </div>
    <nav class="side-nav" id="nav">
      <button class="nav-item active" data-view="overview" title="Dashboard" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg><span class="lbl" data-i18n>Dashboard</span></button>
      <button class="nav-item" data-view="acessos" title="Acessos" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2z"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span class="lbl" data-i18n>Acessos</span></button>
      <button class="nav-item" data-view="mudancas" title="Mudanças" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg><span class="lbl" data-i18n>Mudanças</span></button>
      <button class="nav-item" data-view="backup" title="Backup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg><span class="lbl">Backup</span></button>
      <button class="nav-item" data-view="dicionario" title="Dicionário" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg><span class="lbl" data-i18n>Dicionário</span></button>
      <button class="nav-item" data-view="integracoes" title="Integrações" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3l4 4-4 4"/><path d="M20 7H4"/><path d="M8 21l-4-4 4-4"/><path d="M4 17h16"/></svg><span class="lbl" data-i18n>Integrações</span></button>
      <button class="nav-item" data-view="relatorios" title="Relatórios" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg><span class="lbl" data-i18n>Relatórios</span></button>
      <button class="nav-item" data-view="documentacao" title="Documentação" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg><span class="lbl" data-i18n>Documentação</span></button>
      <div class="nav-label lbl" id="navAdminLabel" style="display:none" data-i18n>Administração</div>
      <button class="nav-item" data-view="cadastro" id="navCadastro" title="Cadastro" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><span class="lbl" data-i18n>Cadastro</span></button>
      <button class="nav-item" data-view="usuarios" id="navUsuarios" title="Usuários" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><span class="lbl" data-i18n>Usuários</span></button>
      <button class="nav-item" data-view="roles" id="navRoles" title="Roles" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><span class="lbl">Roles</span></button>
      <button class="nav-item" data-view="email" id="navEmail" title="E-mail" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 8.97 6.36a2 2 0 0 0 2.06 0L22 7"/></svg><span class="lbl" data-i18n>E-mail</span></button>
      <button class="nav-item" data-view="configProjeto" id="navConfigProjeto" title="Configurações" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9A1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg><span class="lbl" data-i18n>Configurações</span></button>
      <button class="nav-item" data-view="auditoria" id="navAuditoria" title="Auditoria" data-i18n-title style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 4v5c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7z"/><path d="M9 12l2 2 4-4"/></svg><span class="lbl" data-i18n>Auditoria</span></button>
      <button class="nav-item" data-view="seguranca" id="navSeguranca" title="Segurança" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><span class="lbl">Segurança</span></button>
    </nav>
    <div class="side-foot">
      <div class="user-chip" id="userChip" title="Editar meu perfil" data-i18n-title style="cursor:pointer">
        <div class="av" id="userAvatar">?</div>
        <div class="info lbl">
          <div class="nm" id="userName">—</div>
        </div>
      </div>
      <button id="changePassBtn" title="Trocar senha" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span class="lbl" data-i18n>Trocar senha</span></button>
      <button id="logoutBtn" title="Sair" data-i18n-title><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span class="lbl" data-i18n>Sair</span></button>
      <div id="dbInfo" class="db-info lbl" style="display:none" title=""></div>
    </div>
  </aside>

  <div class="main">
    <div class="mfa-banner" id="mfaBanner" hidden>
      <div class="mfa-banner-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
      <div class="mfa-banner-txt"><strong data-i18n>Proteja sua conta</strong><span data-i18n>Ative a verificação em duas etapas para aumentar a segurança do seu acesso.</span></div>
      <button class="btn btn-primary" data-act="irMfaBannerPerfil" data-i18n>Ativar agora</button>
    </div>
    <div class="topbar">
      <button class="btn btn-ghost menu-toggle" id="menuToggle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div class="tt"><h2 id="viewTitle">Dashboard</h2><div class="sub" id="viewSub">Resumo do seu ambiente de dados</div></div>
      <div class="search" id="searchBox" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg><input type="text" id="search" placeholder="Buscar..." data-i18n-ph></div>
      <button class="btn btn-ghost icon-only" id="themeToggle" title="Alternar tema claro/escuro" data-i18n-title aria-label="Alternar tema">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      </button>
      <button class="btn btn-ghost lang-toggle" id="langToggle" title="Mudar idioma / Switch language" aria-label="Mudar idioma"><span id="langToggleLabel">EN</span></button>
      <button class="btn btn-primary" id="addBtn" style="display:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg><span data-i18n>Adicionar</span></button>
    </div>
    <div class="content" id="content"></div>
  </div>
</div>

<div class="overlay" id="overlay">
  <div class="modal">
    <div class="modal-h"><h3 id="modalTitle">Novo registro</h3><button class="x" id="modalClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="modal-b" id="modalBody"></div>
    <div class="modal-f"><button class="btn btn-ghost" id="modalCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="modalSave" data-i18n>Salvar registro</button></div>
  </div>
</div>

<div class="overlay" id="pwOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-h"><h3 data-i18n>Trocar senha</h3><button class="x" id="pwClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Senha atual</label><input type="password" id="pwAtual"></div>
      <div class="fld"><label data-i18n>Nova senha</label><input type="password" id="pwNova"></div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="pwCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="pwSave" data-i18n>Salvar nova senha</button></div>
  </div>
</div>

<div class="overlay" id="mfaToggleOverlay">
  <div class="modal" style="max-width:380px">
    <div class="modal-h"><h3 data-i18n>Verificação em duas etapas</h3><button class="x" id="mfaToggleClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Senha atual</label><input type="password" id="mfaTogglePass"></div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="mfaToggleCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="mfaToggleSave" data-i18n>Confirmar</button></div>
  </div>
</div>

<div class="overlay" id="userOverlay">
  <div class="modal" style="max-width:460px">
    <div class="modal-h"><h3 data-i18n>Novo usuário</h3><button class="x" id="userClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Login</label><input type="text" id="userLogin"></div>
      <div class="fld"><label data-i18n>Nome completo</label><input type="text" id="userNome"></div>
      <div class="fld"><label data-i18n>E-mail</label><input type="email" id="userEmail"></div>
      <div class="fld">
        <label data-i18n>Senha</label>
        <input type="password" id="userSenha">
        <div style="font-size:11.5px;color:var(--muted);margin-top:4px" data-i18n>Mínimo 8 caracteres, com letra e número.</div>
      </div>
      <div class="fld"><label data-i18n>Papel</label><select id="userRoleSel"><option value="leitura" data-i18n>Leitura</option><option value="escrita" data-i18n>Escrita</option><option value="admin" data-i18n>Administrador</option></select></div>
      <div class="fld" id="userModulosWrap">
        <label data-i18n>Artefatos permitidos</label>
        <div class="chk-group" id="userModulosChk"></div>
      </div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="userCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="userSave" data-i18n>Criar usuário</button></div>
  </div>
</div>

<div class="overlay" id="rolesOverlay">
  <div class="modal" style="max-width:460px">
    <div class="modal-h"><h3 data-i18n>Editar permissões</h3><button class="x" id="rolesClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>Usuário</label><input type="text" id="rolesUserLabel" disabled></div>
      <div class="fld"><label data-i18n>Papel</label><select id="rolesRoleSel"><option value="leitura" data-i18n>Leitura</option><option value="escrita" data-i18n>Escrita</option><option value="admin" data-i18n>Administrador</option></select></div>
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
    <div class="modal-h"><h3 data-i18n>Enviar relatório por e-mail</h3><button class="x" id="emailClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="modal-b" style="grid-template-columns:1fr">
      <div class="fld"><label data-i18n>E-mail de destino</label><input type="email" id="emailPara" placeholder="nome@empresa.com"></div>
    </div>
    <div class="modal-f"><button class="btn btn-ghost" id="emailCancel" data-i18n>Cancelar</button><button class="btn btn-primary" id="emailSend" data-i18n>Enviar</button></div>
  </div>
</div>

<div class="overlay" id="bulkObsOverlay">
  <div class="modal" style="max-width:460px">
    <div class="modal-h"><h3 data-i18n>Atualizar observações em massa</h3><button class="x" id="bulkObsClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
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
    <div class="modal-h"><h3 data-i18n>Importação concluída</h3><button class="x" id="importResultClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
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
      <button class="x" id="cadastroDrawerClose"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="drawer-b" id="cadastroDrawerBody"></div>
  </div>
</div>

<div class="toast" id="toast"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span id="toastMsg"></span></div>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/api.js?v=<?= @filemtime(__DIR__ . '/js/api.js') ?: time() ?>"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/vendor/jspdf.umd.min.js"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/vendor/jspdf.plugin.autotable.min.js"></script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: time() ?>"></script>
</body>
</html>
