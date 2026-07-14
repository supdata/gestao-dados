<?php

declare(strict_types=1);

/**
 * Configuracao, conexao com o banco e criacao das tabelas -- sem
 * framework, sem ORM, sem dependencia do Composer. Funciona com MySQL,
 * PostgreSQL, SQL Server ou SQLite; troque "db_driver" em conf/config.php
 * pra mudar de motor.
 */

/** @return array<string, mixed> */
function config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $arquivo = __DIR__ . '/../conf/config.php';
    if (!is_file($arquivo)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'detail' => 'Configuracao ausente: copie conf/config.example.php para conf/config.php e ajuste os valores.',
        ]);
        exit;
    }

    $cfg = require $arquivo;
    return $cfg;
}

function dbDriver(): string
{
    return strtolower((string) (config()['db_driver'] ?? 'mysql'));
}

/**
 * Nome do projeto: prioriza o que o administrador configurou em
 * Administracao > Configuracoes do projeto (tabela config_projeto, gravada
 * em tempo de execucao); cai pro project_title de conf/config.php (definido
 * so na instalacao) se nunca foi customizado.
 */
function projectTitle(): string
{
    $custom = trim((string) (configProjeto()['nome_projeto'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    $titulo = trim((string) (config()['project_title'] ?? ''));
    return $titulo !== '' ? $titulo : 'Portal de Dados';
}

/**
 * Linha unica (id = 1) de nome/logo customizados do projeto. null se nunca
 * foi salva, a tabela ainda nao existe, ou o banco esta inacessivel --
 * projectTitle()/projectLogoDataUri() precisam de um fallback seguro mesmo
 * com o banco fora do ar (a pagina de login, por exemplo, nao pode quebrar
 * por isso).
 */
/**
 * @return array<string, mixed>|null
 */
function configProjeto(): ?array
{
    try {
        $pdo = db();
        if (!tableExists($pdo, tableName('config_projeto'))) {
            return null;
        }
        $stmt = $pdo->query(
            'SELECT * FROM ' . quoteIdent(tableName('config_projeto')) . ' WHERE ' . quoteIdent('id') . ' = 1'
        );
        if ($stmt === false) {
            return null;
        }
        /** @var array<string, mixed>|false $linha */
        $linha = $stmt->fetch();
        return $linha !== false ? $linha : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Logo customizado (data URI "data:image/...;base64,...."), ou null se o admin nunca enviou um (usa-se o SVG padrao nesse caso). */
function projectLogoDataUri(): ?string
{
    $logo = trim((string) (configProjeto()['logo_data'] ?? ''));
    return $logo !== '' ? $logo : null;
}

/**
 * Grava (insere ou atualiza) nome/logo do projeto. "nome_projeto" vazio
 * volta a usar o project_title do config.php. "logo_data" vazio (ou
 * ausente) mantem a logo que ja estava salva; "remover_logo" = true
 * descarta a logo customizada e volta pro icone padrao do portal.
 */
/**
 * @param array<string, mixed> $dados
 * @return array<string, mixed>
 */
function salvarConfigProjeto(array $dados): array
{
    $pdo = db();
    $existente = configProjeto();

    $nomeProjeto = trim((string) ($dados['nome_projeto'] ?? ($existente['nome_projeto'] ?? '')));

    if (!empty($dados['remover_logo'])) {
        $logoData = null;
    } else {
        $logoNova = trim((string) ($dados['logo_data'] ?? ''));
        $logoData = $logoNova !== '' ? $logoNova : ($existente['logo_data'] ?? null);
    }

    $timeoutInatividade = array_key_exists('timeout_inatividade_min', $dados)
        ? (int) $dados['timeout_inatividade_min']
        : (int) ($existente['timeout_inatividade_min'] ?? 30);

    $valores = [$nomeProjeto, $logoData, $timeoutInatividade, date('Y-m-d H:i:s')];

    if ($existente) {
        $sql = 'UPDATE ' . quoteIdent(tableName('config_projeto')) . ' SET ' .
            quoteIdent('nome_projeto') . ' = ?, ' . quoteIdent('logo_data') . ' = ?, ' .
            quoteIdent('timeout_inatividade_min') . ' = ?, ' .
            quoteIdent('atualizado_em') . ' = ? WHERE ' . quoteIdent('id') . ' = 1';
        $pdo->prepare($sql)->execute($valores);
    } else {
        $sql = 'INSERT INTO ' . quoteIdent(tableName('config_projeto')) . ' (' . quoteIdent('id') . ', ' .
            quoteIdent('nome_projeto') . ', ' . quoteIdent('logo_data') . ', ' .
            quoteIdent('timeout_inatividade_min') . ', ' . quoteIdent('atualizado_em') .
            ') VALUES (1, ?, ?, ?, ?)';
        inserirComIdExplicito($pdo, tableName('config_projeto'), $sql, $valores);
    }

    return ['nome_projeto' => $nomeProjeto, 'tem_logo' => $logoData !== null, 'timeout_inatividade_min' => $timeoutInatividade];
}

/**
 * Prefixo escolhido na instalacao pra identificar as tabelas deste
 * projeto dentro do banco (util quando varios projetos compartilham o
 * mesmo banco/servidor, ex.: hospedagem compartilhada). O valor ja vem
 * saneado de setup/index.php; aqui so aplicamos um fallback defensivo
 * "gdt" caso o config.php tenha sido editado a mao com um valor vazio ou
 * com caractere invalido.
 */
function tablePrefix(): string
{
    $prefixo = strtolower(trim((string) (config()['table_prefix'] ?? '')));
    $prefixo = (string) preg_replace('/[^a-z0-9_]/', '', $prefixo);
    $prefixo = trim($prefixo, '_');
    return $prefixo !== '' ? $prefixo : 'gdt';
}

/** Nome real da tabela no banco: "{prefixo}_{nome logico}" (ex.: gdt_usuarios). */
function tableName(string $logico): string
{
    return tablePrefix() . '_' . $logico;
}

function dbEngineName(): string
{
    $nomes = [
        'mysql' => 'MySQL',
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'SQL Server',
        'sqlite' => 'SQLite',
    ];
    return $nomes[dbDriver()] ?? dbDriver();
}

/**
 * Coloca aspas/colchetes no identificador (tabela ou coluna) seguindo a
 * convencao de cada motor. Evita tropecar em nome de coluna que por acaso
 * seja palavra reservada em algum dos quatro motores (ex.: "rollback" e
 * palavra reservada em T-SQL).
 */
function quoteIdent(string $nome): string
{
    switch (dbDriver()) {
        case 'mysql':
            return '`' . str_replace('`', '``', $nome) . '`';
        case 'sqlsrv':
            return '[' . str_replace(']', ']]', $nome) . ']';
        default: // pgsql, sqlite
            return '"' . str_replace('"', '""', $nome) . '"';
    }
}

/**
 * Executa um INSERT que informa explicitamente o valor da coluna "id"
 * (usado pelas tabelas de linha unica config_projeto/config_email, que
 * sempre gravam id = 1). MySQL, Postgres e SQLite aceitam valor explicito
 * em coluna auto-incremento sem reclamar -- so o SQL Server bloqueia isso
 * por padrao ("Cannot insert explicit value for identity column ... when
 * IDENTITY_INSERT is set to OFF"), entao so nesse motor precisamos ligar o
 * IDENTITY_INSERT pra essa tabela antes do INSERT e desligar depois.
 */
/** @param list<mixed> $valores */
function inserirComIdExplicito(PDO $pdo, string $tabela, string $sql, array $valores): void
{
    if (dbDriver() !== 'sqlsrv') {
        $pdo->prepare($sql)->execute($valores);
        return;
    }
    $tabelaQuoted = quoteIdent($tabela);
    $pdo->exec('SET IDENTITY_INSERT ' . $tabelaQuoted . ' ON');
    try {
        $pdo->prepare($sql)->execute($valores);
    } finally {
        $pdo->exec('SET IDENTITY_INSERT ' . $tabelaQuoted . ' OFF');
    }
}

/**
 * Confere se a extensao PDO do motor escolhido esta habilitada no PHP
 * deste servidor ANTES de tentar conectar -- sem essa checagem o erro
 * que sobra e algo tecnico tipo "could not find driver", que nao diz o
 * que fazer. Esta e a causa mais comum de "nenhum banco conecta".
 */
if (!function_exists('verificarExtensaoPdo')) {
function verificarExtensaoPdo(string $driver): ?string
{
    $extensoes = [
        'mysql' => 'pdo_mysql',
        'pgsql' => 'pdo_pgsql',
        'sqlsrv' => 'pdo_sqlsrv',
        'sqlite' => 'pdo_sqlite',
    ];
    $extensao = $extensoes[$driver] ?? null;
    if ($extensao === null) {
        return null;
    }
    if (!extension_loaded('pdo') || !extension_loaded($extensao)) {
        $pacoteApt = 'php-' . str_replace('pdo_', '', $extensao);
        return "A extensao \"{$extensao}\" do PHP nao esta habilitada neste servidor (peca pro time "
            . "de infraestrutura habilitar -- em Debian/Ubuntu: \"sudo apt install {$pacoteApt}\" + "
            . 'reiniciar o PHP-FPM/Apache).';
    }
    return null;
}
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = config();
    $driver = dbDriver();
    $user = null;
    $pass = null;

    $avisoExtensao = verificarExtensaoPdo($driver);
    if ($avisoExtensao !== null) {
        throw new RuntimeException($avisoExtensao);
    }

    switch ($driver) {
        case 'mysql':
            $dsn = "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4";
            $user = $cfg['db_user'] ?? 'root';
            $pass = $cfg['db_password'] ?? '';
            break;

        case 'pgsql':
            $dsn = "pgsql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']}";
            $user = $cfg['db_user'] ?? 'postgres';
            $pass = $cfg['db_password'] ?? '';
            break;

        case 'sqlsrv':
            $dsn = "sqlsrv:Server={$cfg['db_host']},{$cfg['db_port']};Database={$cfg['db_name']}";
            $user = $cfg['db_user'] ?? 'sa';
            $pass = $cfg['db_password'] ?? '';
            break;

        case 'sqlite':
            $caminho = $cfg['db_sqlite_path'] ?? (__DIR__ . '/../db/database.db');
            $pastaSqlite = dirname($caminho);
            if (!is_dir($pastaSqlite)) {
                @mkdir($pastaSqlite, 0775, true);
            }
            $dsn = "sqlite:{$caminho}";
            break;

        default:
            throw new RuntimeException(
                "db_driver desconhecido: \"{$driver}\". Use mysql, pgsql, sqlsrv ou sqlite em conf/config.php."
            );
    }

    try {
        $pdo = new PDO($dsn, $user, $pass);
    } catch (PDOException $e) {
        throw new RuntimeException(
            "Nao foi possivel conectar ao banco ({$driver}). Confira host, porta, usuario e senha em " .
            "conf/config.php. Detalhe original: " . $e->getMessage()
        );
    }

    // Atributos setados UM A UM depois de conectar, em vez de juntos no
    // array do construtor: alguns drivers (notavelmente pdo_sqlsrv, em
    // certas combinacoes de versao do PHP/driver) rejeitam atributos
    // passados dessa forma com o erro "SQLSTATE[IMSSP]: An unsupported
    // attribute was designated on the PDO object" -- mesmo sendo
    // atributos oficialmente suportados. Setando depois, individualmente,
    // evitamos esse bug conhecido; se algum mesmo assim falhar, ignoramos
    // em vez de derrubar a conexao inteira.
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        // sem isso o PDO usa ERRMODE_SILENT por padrao; raro de falhar.
    }
    try {
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // sem isso o PDO usa FETCH_BOTH por padrao; raro de falhar.
    }

    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    return $pdo;
}

/**
 * Confere se a tabela ja existe NO BANCO/ESQUEMA ATUAL -- nao no servidor
 * inteiro. Em MySQL/PostgreSQL/SQL Server, "information_schema.tables" e
 * uma visao que cobre todos os bancos visiveis pelo usuario conectado; sem
 * filtrar pelo banco/esquema atual, uma tabela com o MESMO NOME em outro
 * banco do mesmo servidor (comum em hospedagem compartilhada com varios
 * projetos) faz esta funcao mentir dizendo "ja existe", e o migrate() pula
 * a criacao da tabela certa. Foi exatamente esse bug que causou o erro
 * "Table 'meubanco.usuarios' doesn't exist" depois de uma instalacao que
 * pareceu ter dado certo.
 */
function tableExists(PDO $pdo, string $tabela): bool
{
    $driver = dbDriver();

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$tabela]);
        return (bool) $stmt->fetchColumn();
    }

    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables ' .
            'WHERE table_catalog = current_database() AND table_schema = current_schema() AND table_name = ?'
        );
    } elseif ($driver === 'sqlsrv') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables ' .
            'WHERE table_catalog = DB_NAME() AND table_schema = SCHEMA_NAME() AND table_name = ?'
        );
    } else { // mysql
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
    }

    $stmt->execute([$tabela]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/**
 * Confere se a COLUNA ja existe numa tabela (mesma logica de escopo por
 * banco/esquema atual do tableExists(), pra evitar o mesmo tipo de falso
 * positivo). Usado pra evoluir tabelas que ja existem de uma instalacao
 * anterior sem apagar nada (ex.: adicionar "modulos_permitidos" em quem
 * instalou antes desse campo existir).
 */
function columnExists(PDO $pdo, string $tabela, string $coluna): bool
{
    $driver = dbDriver();

    if ($driver === 'sqlite') {
        $stmt = $pdo->query('PRAGMA table_info(' . quoteIdent($tabela) . ')');
        if ($stmt === false) {
            return false;
        }
        /** @var list<array<string, mixed>> $colunas */
        $colunas = $stmt->fetchAll();
        foreach ($colunas as $col) {
            if (strcasecmp((string) $col['name'], $coluna) === 0) {
                return true;
            }
        }
        return false;
    }

    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns ' .
            'WHERE table_catalog = current_database() AND table_schema = current_schema() AND table_name = ? AND column_name = ?'
        );
    } elseif ($driver === 'sqlsrv') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns ' .
            'WHERE table_catalog = DB_NAME() AND table_schema = SCHEMA_NAME() AND table_name = ? AND column_name = ?'
        );
    } else { // mysql
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
    }

    $stmt->execute([$tabela, $coluna]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/** Adiciona a coluna se ela ainda nao existir. Nunca apaga/altera coluna existente. */
function ensureColumn(PDO $pdo, string $tabela, string $coluna, string $tipoSql): void
{
    if (columnExists($pdo, $tabela, $coluna)) {
        return;
    }
    // T-SQL (SQL Server) nao aceita a palavra "COLUMN" nessa clausula --
    // o correto la e so "ALTER TABLE tabela ADD coluna tipo". Usar "ADD
    // COLUMN" nesse motor da exatamente "Incorrect syntax near the
    // keyword 'COLUMN'". Os outros tres motores aceitam "ADD COLUMN".
    $clausula = dbDriver() === 'sqlsrv' ? 'ADD' : 'ADD COLUMN';
    $pdo->exec('ALTER TABLE ' . quoteIdent($tabela) . ' ' . $clausula . ' ' . quoteIdent($coluna) . ' ' . $tipoSql);
}

/** Cria as tabelas que ainda nao existem. Nunca apaga dados existentes. */
function migrate(): void
{
    $pdo = db();
    $driver = dbDriver();

    $pk = [
        'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'pgsql' => 'SERIAL PRIMARY KEY',
        'sqlsrv' => 'INT IDENTITY(1,1) PRIMARY KEY',
        'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    ][$driver];

    // SQL Server nao tem TEXT/CLOB "de boa" -- usa NVARCHAR(MAX). Os outros usam TEXT.
    $txt = [
        'mysql' => 'TEXT',
        'pgsql' => 'TEXT',
        'sqlsrv' => 'NVARCHAR(MAX)',
        'sqlite' => 'TEXT',
    ][$driver];

    // criado_em/atualizado_em: quem preenche o valor e a aplicacao (em PHP,
    // com date('Y-m-d H:i:s')), nao um DEFAULT do banco -- assim nao
    // dependemos de CURRENT_TIMESTAMP vs GETDATE() vs etc.
    $carimbo = [
        'mysql' => 'DATETIME',
        'pgsql' => 'TIMESTAMP',
        'sqlsrv' => 'DATETIME2',
        'sqlite' => 'DATETIME',
    ][$driver];

    $tabelas = [
        'usuarios' => [
            'id' => $pk,
            'username' => 'VARCHAR(50) NOT NULL UNIQUE',
            'nome_completo' => 'VARCHAR(120)',
            'password_hash' => 'VARCHAR(255) NOT NULL',
            'role' => "VARCHAR(20) NOT NULL DEFAULT 'leitura'",
            'criado_em' => $carimbo,
            'atualizado_em' => $carimbo,
        ],
        'acessos' => [
            'id' => $pk,
            'data' => 'DATE',
            'usuario' => 'VARCHAR(150)',
            'tipo' => 'VARCHAR(50)',
            'objeto' => 'VARCHAR(200)',
            'nivel' => 'VARCHAR(100)',
            'justificativa' => $txt,
            'solicitante' => 'VARCHAR(150)',
            'aprovador' => 'VARCHAR(150)',
            'revisao' => 'DATE',
            'status' => "VARCHAR(30) DEFAULT 'Ativo'",
            'obs' => $txt,
            'criado_em' => $carimbo,
            'atualizado_em' => $carimbo,
        ],
        'mudancas_objetos' => [
            'id'         => $pk,
            'mudanca_id' => 'INT NOT NULL',
            'nome'       => 'VARCHAR(200) NOT NULL',
            'tipo'       => 'VARCHAR(50)',
            'criado_em'  => $carimbo,
        ],
        'mudancas' => [
            'id' => $pk,
            'codigo' => 'VARCHAR(30)',
            'data' => 'DATE',
            'ambiente' => 'VARCHAR(30)',
            'tipo' => 'VARCHAR(50)',
            'descricao' => $txt,
            'script' => 'VARCHAR(200)',
            'rollback' => $txt,
            'solicitante' => 'VARCHAR(150)',
            'aprovador' => 'VARCHAR(150)',
            'status' => 'VARCHAR(30)',
            'resultado' => $txt,
            'criado_em' => $carimbo,
            'atualizado_em' => $carimbo,
        ],
        'backup_politicas' => [
            'id' => $pk,
            'banco' => 'VARCHAR(150)',
            'criticidade' => 'VARCHAR(30)',
            'tipo' => 'VARCHAR(50)',
            'frequencia' => 'VARCHAR(100)',
            'horario' => 'VARCHAR(50)',
            'retencao' => 'VARCHAR(100)',
            'local' => 'VARCHAR(250)',
            'rpo' => 'VARCHAR(50)',
            'rto' => 'VARCHAR(50)',
            'responsavel' => 'VARCHAR(150)',
            'criado_em' => $carimbo,
            'atualizado_em' => $carimbo,
        ],
        'restore_testes' => [
            'id' => $pk,
            'data' => 'DATE',
            'banco' => 'VARCHAR(150)',
            'backup' => 'VARCHAR(150)',
            'tempo' => 'VARCHAR(50)',
            'resultado' => 'VARCHAR(30)',
            'por' => 'VARCHAR(150)',
            'obs' => $txt,
            'criado_em' => $carimbo,
            'atualizado_em' => $carimbo,
        ],
        'dicionario_dados' => [
            'id' => $pk,
            'banco' => 'VARCHAR(150)',
            'schema_nome' => 'VARCHAR(150)',
            'tabela' => 'VARCHAR(150)',
            'coluna' => 'VARCHAR(150)',
            'tipo_dado' => 'VARCHAR(100)',
            'permite_nulo' => 'VARCHAR(10)',
            'descricao' => $txt,
            'classificacao' => 'VARCHAR(50)',
            'origem' => 'VARCHAR(150)',
            'obs' => $txt,
            'criado_em' => $carimbo,
            'atualizado_em' => $carimbo,
        ],
        'integracoes' => [
            'id' => $pk,
            'nome' => 'VARCHAR(200)',
            'origem' => 'VARCHAR(150)',
            'ip_origem' => 'VARCHAR(45)',
            'destino' => 'VARCHAR(150)',
            'ip_destino' => 'VARCHAR(45)',
            'tipo' => 'VARCHAR(60)',
            'direcao' => 'VARCHAR(30)',
            'mecanismo' => 'VARCHAR(150)',
            'frequencia' => 'VARCHAR(30)',
            'dados_trafegados' => $txt,
            'classificacao' => 'VARCHAR(50)',
            'criticidade' => 'VARCHAR(30)',
            'resp_tecnico' => 'VARCHAR(150)',
            'resp_negocio' => 'VARCHAR(150)',
            'ambiente' => 'VARCHAR(30)',
            'status' => 'VARCHAR(30)',
            'ultima_revisao' => 'DATE',
            'obs' => $txt,
            'criado_em' => $carimbo,
            'atualizado_em' => $carimbo,
        ],
        // Cadastro: listas de opcoes editaveis pelo administrador (Ambiente,
        // Tipo e Status de Mudancas; Criticidade, Tipo de backup e Resultado
        // de Restore). Uma tabela generica com discriminador "categoria" em
        // vez de uma tabela por lista -- mais simples de evoluir se surgir
        // uma setima/oitava lista no futuro.
        'config_tipos' => [
            'id' => $pk,
            'categoria' => 'VARCHAR(40) NOT NULL',
            'nome' => 'VARCHAR(120) NOT NULL',
            'criado_em' => $carimbo,
        ],
        // Configuracao do servidor de e-mail (SMTP) usada pra enviar
        // relatorios por e-mail. Linha unica (id = 1) -- nao tem tela de
        // "varios servidores", e um portal so manda por um SMTP so. A senha
        // fica cifrada (ver backend/mailer.php) e nunca volta em respostas
        // GET.
        'config_email' => [
            'id' => $pk,
            'host' => 'VARCHAR(150)',
            'porta' => 'INT',
            'seguranca' => "VARCHAR(10) DEFAULT 'tls'",
            'usuario' => 'VARCHAR(150)',
            'senha_cifrada' => $txt,
            'remetente_nome' => 'VARCHAR(120)',
            'remetente_email' => 'VARCHAR(150)',
            'testado_ok' => 'INT NOT NULL DEFAULT 0',
            'atualizado_em' => $carimbo,
        ],
        // Nome e logo customizados do projeto (Administracao > Configuracoes
        // do projeto). Linha unica (id = 1), mesmo padrao do config_email.
        // A logo fica como data URI (base64) direto na coluna -- sem upload
        // de arquivo pra disco, pra nao precisar de parsing multipart na API
        // (que hoje so aceita JSON) nem de permissao de escrita extra no
        // servidor.
        'config_projeto' => [
            'id' => $pk,
            'nome_projeto' => 'VARCHAR(160)',
            'logo_data' => $txt,
            'atualizado_em' => $carimbo,
        ],
        // Controle de tentativas de login (protecao contra forca bruta).
        // Uma linha por par usuario+IP; "tentativas" zera no login certo
        // (ver limparTentativasLogin() em backend/auth.php) e
        // "bloqueado_ate" so e preenchido quando estoura o limite
        // (LOGIN_MAX_TENTATIVAS em backend/auth.php).
        'tentativas_login' => [
            'id' => $pk,
            'usuario' => 'VARCHAR(150) NOT NULL',
            'ip' => 'VARCHAR(45) NOT NULL',
            'tentativas' => 'INT NOT NULL DEFAULT 0',
            'primeira_tentativa' => $carimbo,
            'ultima_tentativa' => $carimbo,
            'bloqueado_ate' => $carimbo,
        ],
        // Tokens (JWT) revogados antes do vencimento natural -- logout
        // explicito ou troca de senha (ver revogarToken() em
        // backend/auth.php). So guarda o jti + a validade original; a
        // propria revogarToken() apaga as linhas ja vencidas a cada
        // chamada, entao a tabela nao cresce pra sempre.
        'tokens_revogados' => [
            'jti' => 'VARCHAR(64) NOT NULL PRIMARY KEY',
            'expira_em' => $carimbo,
        ],
        // Codigos de verificacao do MFA por e-mail (ver backend/auth.php,
        // funcoes mfaGerarCodigo()/mfaConferirCodigo()). Uma linha por
        // tentativa de login pendente -- "token" identifica essa tentativa
        // pro front-end (devolvido em /auth/login), "codigo_hash" guarda o
        // codigo de 6 digitos so como HMAC (nunca em texto puro, mesma
        // filosofia da senha/SMTP). "tentativas" e o mesmo padrao
        // anti-forca-bruta de tentativas_login, agora por codigo errado.
        'mfa_codigos' => [
            'id' => $pk,
            'usuario' => 'VARCHAR(150) NOT NULL',
            'token' => 'VARCHAR(64) NOT NULL',
            'codigo_hash' => 'VARCHAR(64) NOT NULL',
            'tentativas' => 'INT NOT NULL DEFAULT 0',
            'expira_em' => $carimbo,
            'criado_em' => $carimbo,
        ],
        // Trilha de auditoria: registra quem fez o que, quando e a partir
        // de qual IP em cada modulo do portal (CRUD + eventos de
        // autenticacao). dados_antes/dados_depois guardam um JSON com o
        // estado do registro antes/depois da operacao (null quando nao se
        // aplica, ex.: login). Ver registrarAuditoria() em backend/crud.php.
        'auditoria_log' => [
            'id' => $pk,
            'tabela' => 'VARCHAR(60) NOT NULL',
            'registro_id' => 'INT',
            'acao' => 'VARCHAR(40) NOT NULL',
            'usuario' => 'VARCHAR(150)',
            'dados_antes' => $txt,
            'dados_depois' => $txt,
            'ip' => 'VARCHAR(45)',
            'criado_em' => $carimbo,
        ],
    ];

    $tabelasCriadasAgora = [];
    foreach ($tabelas as $logico => $colunas) {
        $nomeReal = tableName($logico);
        if (tableExists($pdo, $nomeReal)) {
            continue;
        }
        $defs = [];
        foreach ($colunas as $coluna => $tipo) {
            $defs[] = quoteIdent($coluna) . ' ' . $tipo;
        }
        $sql = 'CREATE TABLE ' . quoteIdent($nomeReal) . ' (' . implode(', ', $defs) . ')';
        $pdo->exec($sql);
        $tabelasCriadasAgora[] = $logico;
    }

    // Evolucao aditiva: quem instalou antes do recurso de Roles/E-mail
    // existir ganha as colunas novas sem perder usuarios/dados ja
    // cadastrados.
    ensureColumn($pdo, tableName('usuarios'), 'modulos_permitidos', $txt);
    ensureColumn($pdo, tableName('usuarios'), 'email', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('usuarios'), 'mfa_ativo', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, tableName('usuarios'), 'cor_tema', "VARCHAR(20) NOT NULL DEFAULT 'padrao'");
    ensureColumn($pdo, tableName('usuarios'), 'estilo_side', "VARCHAR(20) NOT NULL DEFAULT 'claro'");
    ensureColumn($pdo, tableName('usuarios'), 'ativo', 'INT NOT NULL DEFAULT 1');
    ensureColumn($pdo, tableName('config_email'), 'testado_ok', 'INT NOT NULL DEFAULT 0');
    ensureColumn($pdo, tableName('config_projeto'), 'timeout_inatividade_min', 'INT NOT NULL DEFAULT 30');
    ensureColumn($pdo, tableName('acessos'), 'servidor', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('dicionario_dados'), 'servidor', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('integracoes'), 'ip_origem', 'VARCHAR(45)');
    ensureColumn($pdo, tableName('integracoes'), 'ip_destino', 'VARCHAR(45)');
    // Campo "Adicionado por": registra o username de quem criou cada registro.
    ensureColumn($pdo, tableName('acessos'),         'criado_por', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('mudancas'),         'criado_por', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('backup_politicas'), 'criado_por', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('restore_testes'),   'criado_por', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('dicionario_dados'), 'criado_por', 'VARCHAR(150)');
    ensureColumn($pdo, tableName('integracoes'),      'criado_por', 'VARCHAR(150)');

    // Acessos > Nivel de acesso passou de lista fixa (antigo OPT.nivel no
    // front) pra categoria do Cadastro -- diferente das categorias abaixo,
    // essa chegou DEPOIS que config_tipos ja existia em instalacoes em
    // produção, entao nao pode depender de "tabela criada agora": semeia
    // sozinha, e so na primeira vez (categoria ainda sem nenhuma linha).
    seedCategoriaSeVazia($pdo, 'acesso_nivel', [
        'db_datareader', 'db_datawriter', 'db_owner', 'db_ddladmin', 'db_securityadmin', 'Customizado', 'sysadmin',
    ]);

    // Mesma logica acima, agora para as listas do modulo Integracoes (Tipo,
    // Ambiente, Criticidade e Status) -- chegaram junto com a tabela
    // "integracoes", mas usam seedCategoriaSeVazia() (e nao
    // seedConfigTipos()) pra nao depender de config_tipos ter sido criada
    // na mesma execucao.
    seedCategoriaSeVazia($pdo, 'integracao_tipo', [
        'Linked Server', 'Job SQL Agent / ETL', 'Pacote SSIS', 'Replicação', 'API / Web Service',
        'Integração por arquivo', 'Database Mail / SMTP', 'Conexão de aplicação', 'Power BI / SSRS',
        'CDC / Change Tracking', 'Service Broker', 'Outro',
    ]);
    seedCategoriaSeVazia($pdo, 'integracao_ambiente', [
        'Produção', 'Homologação', 'Desenvolvimento', 'Múltiplos',
    ]);
    seedCategoriaSeVazia($pdo, 'integracao_criticidade', [
        'Crítica', 'Alta', 'Média', 'Baixa',
    ]);
    seedCategoriaSeVazia($pdo, 'integracao_status', [
        'Ativa', 'Inativa', 'Em implantação', 'A desativar', 'Desconhecida',
    ]);

    // Semeia a tabela de Cadastro com os valores que ja existiam fixos no
    // front (OPT.ambiente/tipoChg/statusChg/criticidade/tipoBkp/resultado),
    // mas SO na primeira vez que a tabela e criada -- assim quem ja tinha o
    // sistema instalado nao perde valores adicionados manualmente, e quem
    // esta instalando agora ja comeca com as mesmas opcoes de sempre.
    if (in_array('config_tipos', $tabelasCriadasAgora, true)) {
        seedConfigTipos($pdo);
    }
}

/** Valores padrao das listas geridas pelo menu Cadastro (ver migrate()). */
function seedConfigTipos(PDO $pdo): void
{
    $agora = date('Y-m-d H:i:s');
    $padrao = [
        'mudanca_ambiente' => ['Produção', 'Homologação', 'Desenvolvimento'],
        'mudanca_tipo' => ['DDL / estrutura', 'Configuração', 'Patch / versão', 'Índice', 'Segurança', 'Outro'],
        'mudanca_status' => ['Planejada', 'Aprovada', 'Executada', 'Revertida', 'Cancelada'],
        'backup_criticidade' => ['Alta', 'Média', 'Baixa'],
        'backup_tipo' => ['Full', 'Full + Diff', 'Full + Log', 'Full + Diff + Log'],
        'backup_resultado' => ['OK', 'Falha', 'OK com ressalvas'],
    ];

    $sql = 'INSERT INTO ' . quoteIdent(tableName('config_tipos')) . ' (' .
        implode(', ', array_map('quoteIdent', ['categoria', 'nome', 'criado_em'])) .
        ') VALUES (?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    foreach ($padrao as $categoria => $nomes) {
        foreach ($nomes as $nome) {
            $stmt->execute([$categoria, $nome, $agora]);
        }
    }
}

/**
 * Semeia uma categoria do Cadastro com valores padrao, mas SO se ela ainda
 * nao tiver nenhuma linha -- usado para categorias que chegam DEPOIS que a
 * tabela config_tipos ja existe em instalacoes em produção (ver migrate()).
 * Diferente de seedConfigTipos(), que so roda na criacao da tabela.
 */
/** @param list<string> $nomes */
function seedCategoriaSeVazia(PDO $pdo, string $categoria, array $nomes): void
{
    $sqlConta = 'SELECT COUNT(*) FROM ' . quoteIdent(tableName('config_tipos')) .
        ' WHERE ' . quoteIdent('categoria') . ' = ?';
    $stmtConta = $pdo->prepare($sqlConta);
    $stmtConta->execute([$categoria]);
    if ((int) $stmtConta->fetchColumn() > 0) {
        return;
    }

    $agora = date('Y-m-d H:i:s');
    $sql = 'INSERT INTO ' . quoteIdent(tableName('config_tipos')) . ' (' .
        implode(', ', array_map('quoteIdent', ['categoria', 'nome', 'criado_em'])) .
        ') VALUES (?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    foreach ($nomes as $nome) {
        $stmt->execute([$categoria, $nome, $agora]);
    }
}
