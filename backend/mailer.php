<?php

declare(strict_types=1);

/**
 * Cliente SMTP minimo, sem biblioteca externa (sem PHPMailer/Composer) --
 * fala o protocolo SMTP na mao via socket (EHLO / STARTTLS / AUTH LOGIN /
 * MAIL FROM / RCPT TO / DATA). Suporta os 3 modos de seguranca usados no
 * mercado: nenhum, TLS via STARTTLS (porta 587, comum) e SSL implicito
 * (porta 465). Mesma filosofia do bcrypt/JWT feitos a mao em auth.php --
 * zero dependencia, funciona em qualquer host com a extensao openssl do
 * PHP habilitada (padrao em qualquer instalacao).
 */

/**
 * Le a linha unica (id = 1) de configuracao de e-mail. null se nunca foi salva.
 *
 * @return array<string, mixed>|null
 */
function configEmail(): ?array
{
    $pdo = db();
    if (!tableExists($pdo, tableName('config_email'))) {
        return null;
    }
    $stmt = $pdo->query(
        'SELECT * FROM ' . quoteIdent(tableName('config_email')) . ' WHERE ' . quoteIdent('id') . ' = 1'
    );
    if ($stmt === false) {
        return null;
    }
    /** @var array<string, mixed>|false $linha */
    $linha = $stmt->fetch();
    return $linha !== false ? $linha : null;
}

/**
 * Mesma config, mas pronta pra virar resposta JSON: sem a senha cifrada.
 *
 * @return array<string, mixed>
 */
function configEmailSemSenha(): array
{
    $cfg = configEmail();
    if ($cfg === null) {
        return [
            'host' => '', 'porta' => 587, 'seguranca' => 'tls', 'usuario' => '',
            'remetente_nome' => '', 'remetente_email' => '', 'senha_configurada' => false,
            'testado_ok' => false,
        ];
    }
    $cfg['senha_configurada'] = !empty($cfg['senha_cifrada']);
    $cfg['testado_ok'] = !empty($cfg['testado_ok']);
    unset($cfg['senha_cifrada']);
    return $cfg;
}

/** O e-mail esta pronto pra valer (configurado E com teste de envio ok)? */
function emailProntoParaUso(): bool
{
    $cfg = configEmail();
    return $cfg !== null && !empty($cfg['testado_ok']);
}

/**
 * Marca a config atual como testada com sucesso -- chamada so depois de um
 * envio real (smtpEnviar) ter funcionado em /config/email/testar. Qualquer
 * alteracao na config depois disso volta o flag pra 0 (ver salvarConfigEmail).
 */
function marcarEmailTestadoOk(): void
{
    $pdo = db();
    $pdo->prepare(
        'UPDATE ' . quoteIdent(tableName('config_email')) . ' SET ' . quoteIdent('testado_ok') . ' = 1 WHERE ' . quoteIdent('id') . ' = 1'
    )->execute();
}

/** Chave de cifra derivada do secret_key do config.php (mesma chave do JWT). */
/**
 * Deriva uma subchave AES-256 especifica para cifrar a senha SMTP.
 * Usa HKDF (RFC 5869) simplificado com context "email-smtp" para que a
 * chave seja diferente da usada pelo JWT, mesmo partindo do mesmo secret_key.
 */
function emailChaveCifra(): string
{
    // Sem fallback: se secret_key estiver ausente, o bootstrap de db.php ja
    // aborta antes de chegar aqui. Nao ha razao para continuar com chave publica.
    $master = (string) config()['secret_key'];
    // HKDF-Extract: PRK = HMAC-SHA256(salt="gdt-email-v1", IKM=master)
    $prk = hash_hmac('sha256', $master, 'gdt-email-v1', true);
    // HKDF-Expand: OKM = HMAC-SHA256(PRK, info="smtp-password" || 0x01)
    return hash_hmac('sha256', 'smtp-password' . "", $prk, true);
}

/**
 * Cifra a senha SMTP com AES-256-GCM (autenticado).
 * Formato do blob: base64( nonce[12] || tag[16] || ciphertext )
 */
function emailCifrarSenha(string $senha): string
{
    $nonce = random_bytes(12);
    $tag   = '';
    $cifrado = openssl_encrypt($senha, 'aes-256-gcm', emailChaveCifra(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cifrado === false) {
        throw new RuntimeException('Falha ao cifrar senha SMTP.');
    }
    return base64_encode($nonce . $tag . $cifrado);
}

/**
 * Decifra blob gerado por emailCifrarSenha().
 * Suporte retroativo: blobs antigos (CBC, 28+ bytes sem tag GCM) continuam
 * funcionando via deteccao de tamanho -- remova apos todos serem re-salvos.
 */
function emailDecifrarSenha(string $cifradoB64): string
{
    $dados = base64_decode($cifradoB64, true);
    if ($dados === false || strlen($dados) < 28) {
        return '';
    }
    // Blobs GCM: nonce(12) + tag(16) + ciphertext(>=1) = minimo 29 bytes.
    // Blobs CBC legados: iv(16) + ciphertext(>=16) = minimo 32 bytes, mas
    // distinguimos pelo tamanho >= 28 para cobrir ambos -- o GCM falha rapi-
    // damente com tag invalida; o CBC retorna string ou false.
    if (strlen($dados) >= 29) {
        // Tenta GCM primeiro
        $nonce   = substr($dados, 0, 12);
        $tag     = substr($dados, 12, 16);
        $cifrado = substr($dados, 28);
        $resultado = openssl_decrypt($cifrado, 'aes-256-gcm', emailChaveCifra(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($resultado !== false) {
            return $resultado;
        }
    }
    // Retrocompatibilidade: tenta CBC com chave antiga
    $iv      = substr($dados, 0, 16);
    $cifrado = substr($dados, 16);
    $resultado = openssl_decrypt($cifrado, 'aes-256-cbc', emailChaveCifra(), OPENSSL_RAW_DATA, $iv);
    return $resultado === false ? '' : $resultado;
}

/**
 * Grava (insere ou atualiza) a linha unica de configuracao. Se "senha" vier
 * vazia no payload, mantem a senha cifrada que ja estava salva -- assim o
 * admin nao precisa redigitar a senha so pra trocar o host, por exemplo.
 */
/**
 * @param array<string, mixed> $dados
 * @return array<string, mixed>
 */
function salvarConfigEmail(array $dados): array
{
    $pdo = db();
    $existente = configEmail();

    $senhaNova = (string) ($dados['senha'] ?? '');
    $senhaCifrada = $senhaNova !== '' ? emailCifrarSenha($senhaNova) : ($existente['senha_cifrada'] ?? null);

    $seguranca = (string) ($dados['seguranca'] ?? 'tls');
    if (!in_array($seguranca, ['none', 'tls', 'ssl'], true)) {
        $seguranca = 'tls';
    }

    $valores = [
        trim((string) ($dados['host'] ?? '')),
        (int) ($dados['porta'] ?? 587),
        $seguranca,
        trim((string) ($dados['usuario'] ?? '')),
        $senhaCifrada,
        trim((string) ($dados['remetente_nome'] ?? '')),
        trim((string) ($dados['remetente_email'] ?? '')),
        0,
        date('Y-m-d H:i:s'),
    ];

    if ($existente) {
        // testado_ok sempre volta pra 0 numa alteracao -- qualquer mudanca no
        // host/usuario/senha/etc invalida o teste de envio anterior, pra nao
        // deixar o MFA liberado com base num teste que nao reflete mais a
        // config atual (ver checagem em PUT /auth/mfa no api.php).
        $sql = 'UPDATE ' . quoteIdent(tableName('config_email')) . ' SET ' .
            quoteIdent('host') . ' = ?, ' . quoteIdent('porta') . ' = ?, ' . quoteIdent('seguranca') . ' = ?, ' .
            quoteIdent('usuario') . ' = ?, ' . quoteIdent('senha_cifrada') . ' = ?, ' .
            quoteIdent('remetente_nome') . ' = ?, ' . quoteIdent('remetente_email') . ' = ?, ' .
            quoteIdent('testado_ok') . ' = ?, ' .
            quoteIdent('atualizado_em') . ' = ? WHERE ' . quoteIdent('id') . ' = 1';
        $pdo->prepare($sql)->execute($valores);
    } else {
        $colunas = ['host', 'porta', 'seguranca', 'usuario', 'senha_cifrada', 'remetente_nome', 'remetente_email', 'testado_ok', 'atualizado_em'];
        $sql = 'INSERT INTO ' . quoteIdent(tableName('config_email')) . ' (' . quoteIdent('id') . ', ' .
            implode(', ', array_map('quoteIdent', $colunas)) . ') VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        inserirComIdExplicito($pdo, tableName('config_email'), $sql, $valores);
    }

    return configEmailSemSenha();
}

/**
 * Envia um e-mail via SMTP puro. Lanca RuntimeException com mensagem
 * amigavel (em portugues) em qualquer etapa que falhar -- o endpoint que
 * chamar isso so precisa devolver $e->getMessage() pro usuario.
 *
 * @param array<string, mixed> $cfg Linha de config_email (com senha_cifrada).
 * @param array<string, mixed>|null $anexo ['nome' => ..., 'tipo' => ..., 'conteudo' => ...] opcional.
 */
function smtpEnviar(array $cfg, string $para, string $assunto, string $corpoTexto, ?array $anexo = null): void
{
    $host = (string) ($cfg['host'] ?? '');
    $porta = (int) ($cfg['porta'] ?? 587);
    $seguranca = (string) ($cfg['seguranca'] ?? 'tls');
    $usuario = trim((string) ($cfg['usuario'] ?? ''));
    $senha = !empty($cfg['senha_cifrada']) ? emailDecifrarSenha((string) $cfg['senha_cifrada']) : '';
    $remetenteEmail = trim((string) ($cfg['remetente_email'] ?? '')) ?: $usuario;
    // Remove quebras de linha para evitar header injection no campo From:.
    $remetenteNome = preg_replace('/[\r\n]/', '', trim((string) ($cfg['remetente_nome'] ?? '')));

    if ($host === '' || $remetenteEmail === '') {
        throw new RuntimeException(
            'Configure o servidor de e-mail em Administracao > E-mail antes de enviar (host e e-mail do remetente sao obrigatorios).'
        );
    }
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('E-mail de destino invalido.');
    }

    $timeout = 12;
    $prefixoTransporte = $seguranca === 'ssl' ? 'ssl://' : 'tcp://';
    // Contexto SSL explicito: sem isso o PHP nao manda o SNI corretamente
    // (varios provedores de SMTP -- SendGrid, Office365, Gmail via relay --
    // hospedam mais de um certificado por IP e recusam a conexao sem SNI) e
    // nao confere o certificado do servidor contra o hostname configurado.
    // O mesmo contexto vale tanto pra conexao SSL direta (porta 465) quanto
    // pro upgrade via STARTTLS mais abaixo, que reaproveita este socket.
    $contextoSsl = stream_context_create([
        'ssl' => [
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ],
    ]);
    $socket = @stream_socket_client(
        "{$prefixoTransporte}{$host}:{$porta}",
        $codigoErro,
        $mensagemErro,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $contextoSsl
    );
    if (!$socket) {
        throw new RuntimeException("Nao foi possivel conectar a {$host}:{$porta} ({$mensagemErro}).");
    }
    stream_set_timeout($socket, $timeout);

    $ler = static function () use ($socket): string {
        $resposta = '';
        while (!feof($socket)) {
            $linha = fgets($socket, 515);
            if ($linha === false) {
                break;
            }
            $resposta .= $linha;
            // Resposta multi-linha do SMTP: a ultima linha tem "codigo ESPACO texto";
            // as intermediarias tem "codigo HIFEN texto". Para de ler na ultima.
            if (strlen($linha) < 4 || $linha[3] !== '-') {
                break;
            }
        }
        return $resposta;
    };
    $escrever = static function (string $comando) use ($socket): void {
        fwrite($socket, $comando . "\r\n");
    };
    $checar = static function (string $resposta, array $codigosEsperados, string $etapa) use ($socket): void {
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, $codigosEsperados, true)) {
            fclose($socket);
            throw new RuntimeException("O servidor de e-mail recusou em \"{$etapa}\": " . trim($resposta ?: '(sem resposta)'));
        }
    };

    $checar($ler(), [220], 'conexao inicial');

    $nomeLocal = gethostname() ?: 'localhost';
    $escrever("EHLO {$nomeLocal}");
    $checar($ler(), [250], 'EHLO');

    if ($seguranca === 'tls') {
        $escrever('STARTTLS');
        $checar($ler(), [220], 'STARTTLS');
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            throw new RuntimeException('Falha ao iniciar a criptografia TLS com o servidor de e-mail.');
        }
        $escrever("EHLO {$nomeLocal}");
        $checar($ler(), [250], 'EHLO (apos STARTTLS)');
    }

    if ($usuario !== '') {
        $escrever('AUTH LOGIN');
        $checar($ler(), [334], 'AUTH LOGIN');
        $escrever(base64_encode($usuario));
        $checar($ler(), [334], 'usuario (autenticacao)');
        $escrever(base64_encode($senha));
        $checar($ler(), [235], 'senha (autenticacao) -- confira usuario/senha do SMTP');
    }

    $escrever("MAIL FROM:<{$remetenteEmail}>");
    $checar($ler(), [250], 'MAIL FROM');

    $escrever("RCPT TO:<{$para}>");
    $checar($ler(), [250, 251], 'RCPT TO');

    $escrever('DATA');
    $checar($ler(), [354], 'DATA');

    $limparPontoInicial = static function (string $texto): string {
        // Regra do protocolo SMTP: uma linha que comeca so com "." marcaria
        // o fim do corpo -- escapa duplicando o ponto se acontecer no texto.
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = (string) preg_replace('/^\./m', '..', $texto);
        // O protocolo exige CRLF em toda linha do corpo (RFC 5321). Sem essa
        // conversao de volta, o corpo ia com LF puro e alguns servidores
        // (principalmente os mais estritos) rejeitavam ou corrompiam a
        // mensagem -- era a causa do teste de e-mail falhar.
        return str_replace("\n", "\r\n", $texto);
    };

    $dataAtual = date('r');
    $deCabecalho = $remetenteNome !== '' ? "{$remetenteNome} <{$remetenteEmail}>" : $remetenteEmail;
    $assuntoCodificado = mb_encode_mimeheader($assunto, 'UTF-8', 'B', "\r\n");

    $cabecalhos = "From: {$deCabecalho}\r\n" .
        "To: <{$para}>\r\n" .
        "Subject: {$assuntoCodificado}\r\n" .
        "Date: {$dataAtual}\r\n" .
        "MIME-Version: 1.0\r\n";

    if ($anexo !== null) {
        $fronteira = 'gdt_' . bin2hex(random_bytes(12));
        $cabecalhos .= "Content-Type: multipart/mixed; boundary=\"{$fronteira}\"\r\n\r\n";
        $corpo = "--{$fronteira}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" .
            $limparPontoInicial($corpoTexto) . "\r\n\r\n";
        $corpo .= "--{$fronteira}\r\nContent-Type: {$anexo['tipo']}; name=\"{$anexo['nome']}\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n" .
            "Content-Disposition: attachment; filename=\"{$anexo['nome']}\"\r\n\r\n";
        $corpo .= chunk_split(base64_encode((string) $anexo['conteudo']));
        $corpo .= "--{$fronteira}--\r\n";
    } else {
        $cabecalhos .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $corpo = $limparPontoInicial($corpoTexto) . "\r\n";
    }

    $escrever($cabecalhos . $corpo . '.');
    $checar($ler(), [250], 'envio (DATA)');

    $escrever('QUIT');
    @$ler();
    fclose($socket);
}
