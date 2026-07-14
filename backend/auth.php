<?php

declare(strict_types=1);

/**
 * Hash de senha e token de sessao (JWT) -- sem nenhuma biblioteca externa.
 *
 * Senha: bcrypt nativo do PHP (password_hash/password_verify). Nunca
 * guardamos senha em texto puro, so o hash.
 *
 * Token: um JWT "na mao" -- e so base64url(header) + "." +
 * base64url(payload) + "." + base64url(HMAC-SHA256(header.payload, chave)).
 * Isso e tudo que o algoritmo HS256 faz; nao precisa de lib pra isso.
 */

function hashPassword(string $senha): string
{
    return password_hash($senha, PASSWORD_BCRYPT);
}

function verifyPassword(string $senha, string $hash): bool
{
    return password_verify($senha, $hash);
}

/**
 * Confere a forca minima da senha: 8+ caracteres, com pelo menos uma
 * letra e um numero. Devolve null se a senha estiver ok, ou a mensagem de
 * erro (em portugues, pronta pra mostrar pro usuario) caso contrario.
 * Usada na instalacao (setup/index.php) e na criacao/troca de senha de
 * usuario (backend/api.php).
 */
function avaliarForcaSenha(string $senha): ?string
{
    if (strlen($senha) < 8) {
        return 'A senha precisa ter pelo menos 8 caracteres.';
    }
    if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/[0-9]/', $senha)) {
        return 'A senha precisa ter pelo menos uma letra e um numero.';
    }
    return null;
}

function base64UrlEncode(string $dados): string
{
    return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
}

function base64UrlDecode(string $dados): string
{
    return (string) base64_decode(strtr($dados, '-_', '+/'));
}

/** @param array<string, mixed> $dados */
function criarToken(array $dados): string
{
    $cfg = config();
    $minutos = (int) ($cfg['token_expire_minutes'] ?? 480);

    $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']) ?: '');
    $payload = $dados;
    $payload['exp'] = time() + ($minutos * 60);
    // jti (JWT ID): identifica esse token especifico, sem revelar nada do
    // payload. E o que permite revogar UM token (logout, troca de senha)
    // sem precisar invalidar a chave secreta de todo mundo -- ver
    // revogarToken()/tokenRevogado() abaixo.
    $payload['jti'] = bin2hex(random_bytes(16));
    $payloadCodificado = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');

    $assinatura = base64UrlEncode(
        hash_hmac('sha256', "{$header}.{$payloadCodificado}", (string) $cfg['secret_key'], true)
    );

    return "{$header}.{$payloadCodificado}.{$assinatura}";
}

/**
 * Retorna o payload decodificado, ou null se o token for invalido/expirado/adulterado.
 *
 * @return array<string, mixed>|null
 */
function decodificarToken(string $token): ?array
{
    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        return null;
    }
    [$header, $payload, $assinatura] = $partes;

    $cfg = config();
    $esperada = base64UrlEncode(
        hash_hmac('sha256', "{$header}.{$payload}", (string) $cfg['secret_key'], true)
    );
    if (!hash_equals($esperada, $assinatura)) {
        return null; // assinatura nao bate -- token adulterado ou chave errada
    }

    $dados = json_decode(base64UrlDecode($payload), true);
    if (!is_array($dados)) {
        return null;
    }
    // Exige que exp esteja presente: token sem exp seria eterno.
    // criarToken() sempre define exp; se faltar, e token adulterado.
    if (!isset($dados['exp'])) {
        return null;
    }
    if (time() > (int) $dados['exp']) {
        return null; // expirado
    }

    return $dados;
}

// ---------------------------------------------------------------------------
// Revogacao de token -- o JWT por si so e valido ate o "exp" estourar, sem
// jeito de invalida-lo antes disso. Pra logout explicito e troca de senha
// precisarem encerrar a sessao DE IMEDIATO (e nao so quando o token vencer
// sozinho), guardamos o jti revogado na tabela "tokens_revogados" (ver
// migrate() em backend/db.php) e checamos ela em toda chamada de
// exigirLogin().
// ---------------------------------------------------------------------------

/**
 * Marca um jti como revogado ate a data em que ele venceria de qualquer
 * forma. Aproveita a chamada pra apagar revogacoes ja vencidas -- assim a
 * tabela so guarda o que ainda importa, sem crescer pra sempre.
 */
function revogarToken(string $jti, int $expiraEm): void
{
    if ($jti === '') {
        return;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM ' . quoteIdent(tableName('tokens_revogados')) . ' WHERE ' . quoteIdent('expira_em') . ' < ?')
        ->execute([date('Y-m-d H:i:s')]);
    try {
        $pdo->prepare(
            'INSERT INTO ' . quoteIdent(tableName('tokens_revogados')) . ' (' .
            quoteIdent('jti') . ', ' . quoteIdent('expira_em') . ') VALUES (?, ?)'
        )->execute([$jti, date('Y-m-d H:i:s', $expiraEm)]);
    } catch (Throwable $e) {
        // jti ja revogado antes (ex.: duplo clique em "Sair") -- ja esta no
        // estado desejado, so ignora.
    }
}

/** True se esse jti foi revogado (logout ou troca de senha) antes do vencimento natural. */
function tokenRevogado(string $jti): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM ' . quoteIdent(tableName('tokens_revogados')) . ' WHERE ' . quoteIdent('jti') . ' = ?');
    $stmt->execute([$jti]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Le o header "Authorization" tentando todas as formas como ele pode
 * chegar no PHP. Em CGI/FastCGI normal vem em HTTP_AUTHORIZATION; no
 * Apache, quando o .htaccess faz um rewrite interno (nosso caso -- tudo
 * cai em index.php), o Apache costuma renomear pra
 * REDIRECT_HTTP_AUTHORIZATION em vez de manter o nome original. Em alguns
 * setups (Apache com mod_php, principalmente) nenhum dos dois aparece em
 * $_SERVER, so em getallheaders() -- por isso o terceiro fallback.
 */
function cabecalhoAutorizacao(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) {
                return (string) $valor;
            }
        }
    }
    return '';
}

// ---------------------------------------------------------------------------
// Protecao contra forca bruta no login -- sem nenhuma dependencia externa
// (nada de Redis/memcached): guarda a contagem direto na tabela
// "tentativas_login" (ver migrate() em backend/db.php), uma linha por par
// usuario+IP. Pensado pra ser barato: so 1 SELECT na checagem e 1
// INSERT/UPDATE/DELETE por tentativa, nada de lock pesado.
// ---------------------------------------------------------------------------

/** Tentativas erradas seguidas (mesmo usuario+IP) ate bloquear temporariamente. */
const LOGIN_MAX_TENTATIVAS = 5;

/** Por quanto tempo (minutos) o par usuario+IP fica bloqueado apos estourar o limite. */
const LOGIN_BLOQUEIO_MINUTOS = 15;

/**
 * Janela (minutos) em que tentativas erradas seguem contando juntas. Se a
 * ultima tentativa errada foi ha mais tempo que isso, a contagem reinicia
 * do zero -- assim 1 erro de digitacao isolado, anos atras, nao fica
 * "guardado" pra sempre.
 */
const LOGIN_JANELA_MINUTOS = 15;

/** IP de quem esta fazendo a requisicao (usado so pra throttle, nao e logado em lugar nenhum). */
function clienteIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Se o par usuario+IP estiver bloqueado agora, devolve quantos minutos
 * faltam pra liberar de novo. Devolve null se puder tentar (sem bloqueio
 * ativo) -- o caso normal, na grande maioria das requisicoes.
 */
function checarBloqueioLogin(string $usuario, string $ip): ?int
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT ' . quoteIdent('bloqueado_ate') . ' FROM ' . quoteIdent(tableName('tentativas_login')) .
        ' WHERE ' . quoteIdent('usuario') . ' = ? AND ' . quoteIdent('ip') . ' = ?'
    );
    $stmt->execute([$usuario, $ip]);
    /** @var array<string, mixed>|false $linha */
    $linha = $stmt->fetch();
    if (!$linha || empty($linha['bloqueado_ate'])) {
        return null;
    }

    $ts = strtotime((string) $linha['bloqueado_ate']);
    $restanteSegundos = ($ts !== false ? $ts : 0) - time();
    if ($restanteSegundos <= 0) {
        return null; // bloqueio ja venceu -- a proxima tentativa errada (se houver) reabre a contagem
    }
    return (int) ceil($restanteSegundos / 60);
}

/**
 * Registra mais uma tentativa errada pra esse usuario+IP. Ao chegar em
 * LOGIN_MAX_TENTATIVAS, preenche "bloqueado_ate" (now + LOGIN_BLOQUEIO_MINUTOS).
 */
function registrarTentativaFalhaLogin(string $usuario, string $ip): void
{
    $pdo = db();
    $agora = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'SELECT * FROM ' . quoteIdent(tableName('tentativas_login')) .
        ' WHERE ' . quoteIdent('usuario') . ' = ? AND ' . quoteIdent('ip') . ' = ?'
    );
    $stmt->execute([$usuario, $ip]);
    /** @var array<string, mixed>|false $linha */
    $linha = $stmt->fetch();

    // Janela de tempo expirada desde a ultima tentativa errada -- trata
    // como se fosse a primeira tentativa de novo (UPDATE em vez de somar).
    if ($linha && strtotime((string) $linha['ultima_tentativa']) < (time() - LOGIN_JANELA_MINUTOS * 60)) {
        $linha['tentativas'] = 0;
    }

    if (!$linha) {
        $pdo->prepare(
            'INSERT INTO ' . quoteIdent(tableName('tentativas_login')) . ' (' .
            quoteIdent('usuario') . ', ' . quoteIdent('ip') . ', ' . quoteIdent('tentativas') . ', ' .
            quoteIdent('primeira_tentativa') . ', ' . quoteIdent('ultima_tentativa') . ', ' . quoteIdent('bloqueado_ate') .
            ') VALUES (?, ?, 1, ?, ?, NULL)'
        )->execute([$usuario, $ip, $agora, $agora]);
        return;
    }

    $tentativas = (int) $linha['tentativas'] + 1;
    $bloqueadoAte = $tentativas >= LOGIN_MAX_TENTATIVAS
        ? date('Y-m-d H:i:s', time() + LOGIN_BLOQUEIO_MINUTOS * 60)
        : null;

    $pdo->prepare(
        'UPDATE ' . quoteIdent(tableName('tentativas_login')) . ' SET ' .
        quoteIdent('tentativas') . ' = ?, ' . quoteIdent('ultima_tentativa') . ' = ?, ' . quoteIdent('bloqueado_ate') . ' = ? ' .
        'WHERE ' . quoteIdent('id') . ' = ?'
    )->execute([$tentativas, $agora, $bloqueadoAte, $linha['id']]);
}

/** Login deu certo -- zera o contador desse usuario+IP. */
function limparTentativasLogin(string $usuario, string $ip): void
{
    $pdo = db();
    $pdo->prepare(
        'DELETE FROM ' . quoteIdent(tableName('tentativas_login')) .
        ' WHERE ' . quoteIdent('usuario') . ' = ? AND ' . quoteIdent('ip') . ' = ?'
    )->execute([$usuario, $ip]);
}

/**
 * Exige login (token no header "Authorization: Bearer ..."). Devolve o
 * usuario logado (com password_hash, quem chama decide se tira) ou ja
 * encerra a requisicao com 401 (responderErro, definido em backend/api.php).
 *
 * @return array<string, mixed>
 */
function exigirLogin(): array
{
    $header = cabecalhoAutorizacao();
    if ($header === '' || !str_starts_with($header, 'Bearer ')) {
        responderErro(401, 'Nao autenticado. Faca login.');
    }

    $payload = decodificarToken(substr($header, 7));
    if ($payload === null || !isset($payload['sub']) || $payload['sub'] === '') {
        responderErro(401, 'Sessao invalida ou expirada. Faca login novamente.');
    }

    if (!empty($payload['jti']) && tokenRevogado((string) $payload['jti'])) {
        responderErro(401, 'Sessao encerrada. Faca login novamente.');
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('username') . ' = ?');
    $stmt->execute([$payload['sub']]);
    /** @var array<string, mixed>|false $user */
    $user = $stmt->fetch();
    if ($user === false) {
        responderErro(401, 'Usuario nao encontrado.');
    }

    // Conta desativada: rejeita mesmo com token JWT ainda valido (expira em
    // ate 8h apos emissao). Sem essa checagem, desativar o usuario nao teria
    // efeito imediato -- o token continuaria funcionando ate expirar.
    if ((int) ($user['ativo'] ?? 1) === 0) {
        responderErro(401, 'Conta desativada.');
    }

    return $user;
}

/**
 * Exige que o usuario logado seja admin (ou master, que tem todo poder do
 * admin e mais a trilha de auditoria -- ver exigirMaster() abaixo).
 */
/** @param array<string, mixed> $user */
function exigirAdmin(array $user): void
{
    $role = (string) ($user['role'] ?? '');
    if ($role !== 'admin' && $role !== 'master') {
        responderErro(403, 'Apenas administradores do portal podem fazer isso.');
    }
}

/**
 * Exige que o usuario logado seja MASTER (papel acima de admin). Usado so
 * na trilha de auditoria -- quem e "apenas" administrador nao entra aqui,
 * mesmo tendo acesso a todo o resto do portal.
 */
/** @param array<string, mixed> $user */
function exigirMaster(array $user): void
{
    if (($user['role'] ?? '') !== 'master') {
        responderErro(403, 'Apenas o usuario master pode acessar a trilha de auditoria.');
    }
}

// ---------------------------------------------------------------------------
// MFA por e-mail (segundo fator de login) -- guarda o codigo de 6 digitos na
// tabela "mfa_codigos" (ver migrate() em backend/db.php) so como HMAC-SHA256
// (mesma chave do JWT), nunca em texto puro -- mesma filosofia do
// hashPassword() acima. "token" e um identificador de uso unico, devolvido
// ao front-end em /auth/login pra ele provar, no passo seguinte, que e a
// mesma tentativa de login (sem precisar repetir usuario/senha).
// ---------------------------------------------------------------------------

/** Quanto tempo (minutos) o codigo de 6 digitos vale apos ser enviado. */
const MFA_CODIGO_TTL_MINUTOS = 10;

/** Tentativas erradas de codigo (mesmo token) ate invalidar a tentativa de login. */
const MFA_MAX_TENTATIVAS = 5;

function mfaHashCodigo(string $codigo): string
{
    $cfg = config();
    return hash_hmac('sha256', $codigo, (string) $cfg['secret_key']);
}

/**
 * Gera um codigo de 6 digitos pro usuario, gravando-o (e descartando
 * qualquer codigo pendente anterior do mesmo usuario -- so um por vez).
 * Devolve [token, codigo]: o token vai pro cliente; o codigo so e usado por
 * quem chamou pra mandar o e-mail, nunca e gravado em texto puro.
 */
/** @return array<string> */
function mfaGerarCodigo(string $usuario): array
{
    $pdo = db();
    $token = bin2hex(random_bytes(32));
    $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $agora = date('Y-m-d H:i:s');
    $expiraEm = date('Y-m-d H:i:s', time() + MFA_CODIGO_TTL_MINUTOS * 60);

    $pdo->prepare('DELETE FROM ' . quoteIdent(tableName('mfa_codigos')) . ' WHERE ' . quoteIdent('usuario') . ' = ?')
        ->execute([$usuario]);

    $pdo->prepare(
        'INSERT INTO ' . quoteIdent(tableName('mfa_codigos')) . ' (' .
        quoteIdent('usuario') . ', ' . quoteIdent('token') . ', ' . quoteIdent('codigo_hash') . ', ' .
        quoteIdent('tentativas') . ', ' . quoteIdent('expira_em') . ', ' . quoteIdent('criado_em') .
        ') VALUES (?, ?, ?, 0, ?, ?)'
    )->execute([$usuario, $token, mfaHashCodigo($codigo), $expiraEm, $agora]);

    return [$token, $codigo];
}

/** Usuario (username) dono desse token pendente, ou null se o token nao existe/ja foi usado. */
function mfaUsuarioPorToken(string $token): ?string
{
    if ($token === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT ' . quoteIdent('usuario') . ' FROM ' . quoteIdent(tableName('mfa_codigos')) . ' WHERE ' . quoteIdent('token') . ' = ?');
    $stmt->execute([$token]);
    $valor = $stmt->fetchColumn();
    return $valor !== false ? (string) $valor : null;
}

function mfaApagarPorToken(string $token): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM ' . quoteIdent(tableName('mfa_codigos')) . ' WHERE ' . quoteIdent('token') . ' = ?')
        ->execute([$token]);
}

/**
 * Confere o codigo digitado pro token dado. Devolve null se o codigo bateu
 * (quem chamou ainda precisa apagar a linha via mfaApagarPorToken -- codigo
 * de uso unico); devolve uma mensagem de erro (pt-br, pronta pra mostrar pro
 * usuario) caso contrario. Conta tentativas erradas e encerra a tentativa de
 * login ao estourar MFA_MAX_TENTATIVAS, pra nao virar um oraculo de forca
 * bruta no codigo (mesmo espirito de checarBloqueioLogin() acima).
 */
function mfaConferirCodigo(string $token, string $codigo): ?string
{
    if ($token === '' || $codigo === '') {
        return 'Informe o codigo recebido por e-mail.';
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('mfa_codigos')) . ' WHERE ' . quoteIdent('token') . ' = ?');
    $stmt->execute([$token]);
    /** @var array<string, mixed>|false $linha */
    $linha = $stmt->fetch();
    if (!$linha) {
        return 'Codigo invalido ou ja utilizado. Faca login novamente.';
    }
    if (strtotime((string) $linha['expira_em']) < time()) {
        mfaApagarPorToken($token);
        return 'Codigo expirado. Faca login novamente.';
    }
    if ((int) $linha['tentativas'] >= MFA_MAX_TENTATIVAS) {
        mfaApagarPorToken($token);
        return 'Numero maximo de tentativas excedido. Faca login novamente.';
    }
    if (!hash_equals((string) $linha['codigo_hash'], mfaHashCodigo($codigo))) {
        $pdo->prepare(
            'UPDATE ' . quoteIdent(tableName('mfa_codigos')) . ' SET ' . quoteIdent('tentativas') . ' = ' .
            quoteIdent('tentativas') . ' + 1 WHERE ' . quoteIdent('token') . ' = ?'
        )->execute([$token]);
        return 'Codigo incorreto.';
    }
    return null;
}
