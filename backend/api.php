<?php

declare(strict_types=1);

/**
 * Roteador da API -- substitui o Slim por roteamento manual (sem
 * dependencia). E chamado pelo index.php da raiz sempre que a URL comeca
 * com "/api". Mesmo contrato de sempre: mesmo formato de JSON, mesmo erro
 * {"detail": "..."}, mesmo esquema de login.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/crud.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/auditoria.php';

// ---------------------------------------------------------------------------
// Helpers de requisicao/resposta
// ---------------------------------------------------------------------------

/**
 * Le o corpo da requisicao -- JSON ou formulario, igual o frontend manda.
 *
 * @return array<string, mixed>
 */
function corpoRequisicao(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $dados = json_decode((string) file_get_contents('php://input'), true);
        return is_array($dados) ? $dados : [];
    }
    // application/x-www-form-urlencoded -- o PHP ja preenche $_POST sozinho
    return $_POST;
}

/**
 * Le $_GET['chave'] sempre como string, ignorando se vier como array
 * (protecao contra ?chave[]=valor -- PHP aceita isso e (string) de array
 * retorna a string literal "Array", o que causa comportamento inesperado).
 */
function strGet(string $chave, string $padrao = ''): string
{
    $v = $_GET[$chave] ?? $padrao;
    return is_scalar($v) ? (string) $v : $padrao;
}

/** Le $_GET['chave'] como int, com o mesmo guard contra array. */
function intGet(string $chave, int $padrao = 0): int
{
    $v = $_GET[$chave] ?? $padrao;
    return is_scalar($v) ? (int) $v : $padrao;
}

function responderJson(mixed $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return never */
function responderErro(int $status, string $detail): never
{
    responderJson(['detail' => $detail], $status);
}

/**
 * Pra respostas 204 -- sem corpo nenhum (nem "null").
 *
 * @return never
 */
function responderVazio(int $status): never
{
    http_response_code($status);
    exit;
}

/**
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
/**
 * Valida uma imagem recebida como data URI. Devolve a mensagem de erro, ou
 * null se estiver tudo certo. Mesma politica ja usada pela logo do projeto:
 * so bitmap (SVG e recusado por ser vetor de execucao/XSS), e o binario
 * precisa ser mesmo uma imagem -- nao basta o cabecalho dizer que e.
 */
function erroImagemDataUri(string $dado, int $limiteBytes): ?string
{
    $tiposPermitidos = ['data:image/png;', 'data:image/jpeg;', 'data:image/webp;', 'data:image/gif;'];
    $tipoOk = false;
    foreach ($tiposPermitidos as $prefixo) {
        if (str_starts_with($dado, $prefixo)) {
            $tipoOk = true;
            break;
        }
    }
    if (!$tipoOk) {
        return 'A imagem precisa ser PNG, JPEG, WEBP ou GIF (SVG nao e aceito por seguranca).';
    }
    if (strlen($dado) > $limiteBytes) {
        return 'Imagem muito grande.';
    }
    $virgula = strpos($dado, ',');
    $binario = $virgula === false ? false : base64_decode(substr($dado, $virgula + 1), true);
    if ($binario === false || @getimagesizefromstring($binario) === false) {
        return 'O arquivo enviado nao e uma imagem valida.';
    }
    return null;
}

/** True se o usuario tem foto cadastrada (consulta barata, sem trazer o blob). */
function usuarioTemFoto(int $usuarioId): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM ' . quoteIdent(tableName('usuarios_foto')) . ' WHERE ' . quoteIdent('usuario_id') . ' = ?');
    $stmt->execute([$usuarioId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function semSenha(array $user): array
{
    unset($user['password_hash']);
    return $user;
}

// ---------------------------------------------------------------------------
// Dashboard (numeros agregados pra tela de Visao geral)
// ---------------------------------------------------------------------------

/**
 * @return list<array<string, mixed>>
 */
function todosRegistros(string $tabela): array
{
    $pdo = db();
    $nome = quoteIdent(tableName($tabela));
    $stmt = $pdo->query("SELECT * FROM {$nome}");
    if ($stmt === false) {
        return [];
    }
    /** @var list<array<string, mixed>> $rows */
    $rows = $stmt->fetchAll();
    return $rows;
}

/**
 * @param list<array<string, mixed>> $linhas
 * @return array<string, mixed>
 */
function contagemPorCampo(array $linhas, string $campo): array
{
    $out = [];
    foreach ($linhas as $linha) {
        $valor = $linha[$campo] ?? null;
        if ($valor === null || $valor === '') {
            continue;
        }
        $out[$valor] = ($out[$valor] ?? 0) + 1;
    }
    return $out;
}

/** (para - de) em dias, podendo ser negativo. */
function diferencaDias(DateTime $de, DateTime $para): int
{
    $intervalo = $de->diff($para);
    $dias = (int) $intervalo->days;
    return $intervalo->invert ? -$dias : $dias;
}

/**
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function dashboardStats(array $user): array
{
    $hoje = new DateTime('today');
    $visiveis = modulosVisiveis($user);

    $acessos = in_array('acessos', $visiveis, true) ? todosRegistros('acessos') : [];
    $mudancas = in_array('mudancas', $visiveis, true) ? todosRegistros('mudancas') : [];
    $restores = in_array('restore', $visiveis, true) ? todosRegistros('restore_testes') : [];
    $dicionario = in_array('dicionario', $visiveis, true) ? todosRegistros('dicionario_dados') : [];
    $backups = in_array('backup', $visiveis, true) ? todosRegistros('backup_politicas') : [];
    $integracoes = in_array('integracoes', $visiveis, true) ? todosRegistros('integracoes') : [];
    $jobs = in_array('jobs', $visiveis, true) ? todosRegistros('jobs') : [];

    $ativos = 0;
    $aRevisar = 0;
    foreach ($acessos as $a) {
        if (($a['status'] ?? null) !== 'Ativo') {
            continue;
        }
        $ativos++;
        if (!empty($a['revisao']) && diferencaDias($hoje, new DateTime($a['revisao'])) <= 30) {
            $aRevisar++;
        }
    }

    $mu30 = 0;
    $muPend = 0;
    foreach ($mudancas as $m) {
        if (!empty($m['data'])) {
            $diff = diferencaDias(new DateTime($m['data']), $hoje);
            if ($diff >= 0 && $diff <= 30) {
                $mu30++;
            }
        }
        if (in_array($m['status'] ?? null, ['Planejada', 'Aprovada'], true)) {
            $muPend++;
        }
    }

    $datasRestore = [];
    foreach ($restores as $r) {
        if (!empty($r['data'])) {
            $datasRestore[] = $r['data'];
        }
    }
    $ultimo = count($datasRestore) > 0 ? max($datasRestore) : null;
    $diasDesdeRestore = $ultimo !== null ? diferencaDias(new DateTime($ultimo), $hoje) : null;

    $bancosPol = [];
    foreach ($backups as $b) {
        $nome = trim((string) ($b['banco'] ?? ''));
        if ($nome !== '') {
            $bancosPol[$nome] = true;
        }
    }
    $bancosPol = array_keys($bancosPol);
    sort($bancosPol);

    $bancosTest = [];
    foreach ($restores as $r) {
        $nome = trim((string) ($r['banco'] ?? ''));
        if ($nome !== '') {
            $bancosTest[$nome] = true;
        }
    }
    $semTeste = array_values(array_filter($bancosPol, fn ($b) => !isset($bancosTest[$b])));

    $classifSensiveis = ['Confidencial', 'Confidencial (PII)', 'Restrita'];
    $sensiveis = 0;
    foreach ($dicionario as $d) {
        if (in_array($d['classificacao'] ?? '', $classifSensiveis, true)) {
            $sensiveis++;
        }
    }

    // Integracoes: mesmos 4 sinais de risco do painel original (criticas,
    // dado pessoal/sensivel, sem responsavel tecnico, revisao vencida ou
    // ausente -- "vencida" = mais de 1 ano desde a ultima_revisao).
    $intCriticas = 0;
    $intSensiveis = 0;
    $intSemResp = 0;
    $intRevVencida = 0;
    foreach ($integracoes as $i) {
        if (($i['criticidade'] ?? null) === 'Crítica') {
            $intCriticas++;
        }
        if (($i['classificacao'] ?? null) === 'Pessoal / Sensível (LGPD)') {
            $intSensiveis++;
        }
        if (trim((string) ($i['resp_tecnico'] ?? '')) === '') {
            $intSemResp++;
        }
        $rev = $i['ultima_revisao'] ?? null;
        if (empty($rev) || diferencaDias(new DateTime($rev), $hoje) > 365) {
            $intRevVencida++;
        }
    }

    // Jobs: total, ativos e desativados (status "Desabilitado"). "Falhando"
    // nao entra aqui porque nao ha deteccao automatica de falha -- e um status
    // preenchido manualmente.
    $jobsAtivos = 0;
    $jobsDesativados = 0;
    foreach ($jobs as $j) {
        if (($j['status'] ?? null) === 'Ativo') {
            $jobsAtivos++;
        }
        if (($j['status'] ?? null) === 'Desabilitado') {
            $jobsDesativados++;
        }
    }

    return [
        'acessos_ativos' => $ativos,
        'acessos_total' => count($acessos),
        'acessos_a_revisar' => $aRevisar,
        'mudancas_30_dias' => $mu30,
        'mudancas_pendentes' => $muPend,
        'ultimo_restore' => $ultimo,
        'dias_desde_ultimo_restore' => $diasDesdeRestore,
        'bancos_sem_teste' => $semTeste,
        'bancos_com_politica' => count($bancosPol),
        'dados_sensiveis' => $sensiveis,
        'totais' => [
            'acessos' => count($acessos),
            'mudancas' => count($mudancas),
            'backup' => count($backups),
            'restore' => count($restores),
            'dicionario' => count($dicionario),
            'integracoes' => count($integracoes),
            'jobs' => count($jobs),
        ],
        'por_status_acessos' => contagemPorCampo($acessos, 'status'),
        'por_status_mudancas' => contagemPorCampo($mudancas, 'status'),
        'por_criticidade_backup' => contagemPorCampo($backups, 'criticidade'),
        'integracoes_total' => count($integracoes),
        'integracoes_criticas' => $intCriticas,
        'integracoes_sensiveis' => $intSensiveis,
        'integracoes_sem_responsavel' => $intSemResp,
        'integracoes_revisao_vencida' => $intRevVencida,
        'jobs_total' => count($jobs),
        'jobs_ativos' => $jobsAtivos,
        'jobs_desativados' => $jobsDesativados,
    ];
}

/**
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function dashboardExport(array $user): array
{
    $visiveis = modulosVisiveis($user);
    return [
        'gerado_em' => (new DateTime('today'))->format('Y-m-d'),
        'motor_banco' => dbEngineName(),
        'acessos' => in_array('acessos', $visiveis, true) ? todosRegistros('acessos') : [],
        'mudancas' => in_array('mudancas', $visiveis, true) ? todosRegistros('mudancas') : [],
        'backup' => in_array('backup', $visiveis, true) ? todosRegistros('backup_politicas') : [],
        'restore' => in_array('restore', $visiveis, true) ? todosRegistros('restore_testes') : [],
        'dicionario' => in_array('dicionario', $visiveis, true) ? todosRegistros('dicionario_dados') : [],
        'integracoes' => in_array('integracoes', $visiveis, true) ? todosRegistros('integracoes') : [],
        'jobs' => in_array('jobs', $visiveis, true) ? todosRegistros('jobs') : [],
    ];
}

/**
 * Dicionario de dados nao pode ter duas linhas para o mesmo
 * Banco + Schema + Tabela + Coluna (caso contrario o dicionario fica
 * ambiguo: duas "definicoes" pra a mesma coluna real). Comparacao
 * sem diferenciar maiusculas/minusculas (LOWER) e tratando nulo como
 * string vazia (COALESCE), pra "" e null contarem como a mesma coisa.
 * Usada tanto no POST (criar) quanto no PUT (editar, excluindo o proprio
 * id) -- e e a mesma checagem que protege a importacao via CSV, que
 * tambem cria registros por este mesmo endpoint POST /dicionario.
 */
/**
 * @param array<string, mixed> $body
 * @param array<string, mixed>|null $atual
 */
function dicionarioVerificarDuplicado(string $tabela, array $body, ?array $atual = null, ?int $idExcluir = null): void
{
    $campos = ['banco', 'schema_nome', 'tabela', 'coluna'];
    $valores = [];
    foreach ($campos as $campo) {
        $valor = array_key_exists($campo, $body) ? $body[$campo] : ($atual[$campo] ?? null);
        $valores[] = trim((string) ($valor ?? ''));
    }

    $pdo = db();
    $condicoes = [];
    foreach ($campos as $campo) {
        $condicoes[] = "COALESCE(LOWER(" . quoteIdent($campo) . "), '') = LOWER(?)";
    }
    $sql = 'SELECT id FROM ' . quoteIdent($tabela) . ' WHERE ' . implode(' AND ', $condicoes);
    if ($idExcluir !== null) {
        $sql .= ' AND ' . quoteIdent('id') . ' <> ?';
        $valores[] = $idExcluir;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($valores);
    if ($stmt->fetch()) {
        responderErro(400, 'Ja existe um registro no dicionario com esse Banco + Schema + Tabela + Coluna.');
    }
}

// ---------------------------------------------------------------------------
// Modulos de dados -- cada um so informa tabela/colunas/busca/ordem; o CRUD
// generico (backend/crud.php) faz o resto.
// ---------------------------------------------------------------------------

$MODULOS = [
    'acessos' => [
        'tabela' => tableName('acessos'),
        'colunas' => ['data', 'usuario', 'tipo', 'servidor', 'objeto', 'nivel', 'justificativa', 'solicitante', 'aprovador', 'revisao', 'status', 'obs', 'criado_por'],
        'busca' => ['usuario', 'servidor', 'objeto', 'nivel', 'justificativa', 'solicitante', 'aprovador', 'status', 'criado_por'],
        'ordem' => 'data',
    ],
    'mudancas' => [
        'tabela' => tableName('mudancas'),
        'colunas' => ['codigo', 'data', 'ambiente', 'tipo', 'descricao', 'script', 'rollback', 'solicitante', 'aprovador', 'status', 'resultado', 'criado_por'],
        'busca' => ['codigo', 'descricao', 'solicitante', 'aprovador', 'status', 'ambiente', 'criado_por'],
        'ordem' => 'data',
    ],
    'backup' => [
        'tabela' => tableName('backup_politicas'),
        'colunas' => ['banco', 'criticidade', 'tipo', 'frequencia', 'horario', 'retencao', 'local', 'rpo', 'rto', 'responsavel', 'criado_por'],
        'busca' => ['banco', 'tipo', 'local', 'responsavel', 'criado_por'],
        'ordem' => 'id',
    ],
    'restore' => [
        'tabela' => tableName('restore_testes'),
        'colunas' => ['data', 'banco', 'backup', 'tempo', 'resultado', 'por', 'obs', 'criado_por'],
        'busca' => ['banco', 'backup', 'por', 'resultado', 'criado_por'],
        'ordem' => 'data',
    ],
    'dicionario' => [
        'tabela' => tableName('dicionario_dados'),
        'colunas' => ['servidor', 'banco', 'schema_nome', 'tabela', 'coluna', 'tipo_dado', 'permite_nulo', 'descricao', 'classificacao', 'origem', 'obs', 'criado_por'],
        'busca' => ['servidor', 'banco', 'tabela', 'coluna', 'tipo_dado', 'descricao', 'classificacao', 'criado_por'],
        'ordem' => 'id',
    ],
    'integracoes' => [
        'tabela' => tableName('integracoes'),
        'colunas' => ['nome', 'origem', 'ip_origem', 'destino', 'ip_destino', 'tipo', 'direcao', 'mecanismo', 'frequencia', 'dados_trafegados', 'classificacao', 'criticidade', 'resp_tecnico', 'resp_negocio', 'ambiente', 'status', 'ultima_revisao', 'obs', 'criado_por'],
        'busca' => ['nome', 'origem', 'ip_origem', 'destino', 'ip_destino', 'tipo', 'mecanismo', 'resp_tecnico', 'resp_negocio', 'status', 'criado_por'],
        'ordem' => 'nome',
    ],
    'jobs' => [
        'tabela' => tableName('jobs'),
        'colunas' => ['nome', 'tipo', 'servidor', 'banco', 'descricao', 'comando', 'frequencia', 'horario', 'agendamentos', 'criticidade', 'status', 'responsavel', 'obs', 'criado_por'],
        'busca' => ['nome', 'tipo', 'servidor', 'banco', 'frequencia', 'status', 'responsavel', 'criado_por'],
        'ordem' => 'nome',
    ],
];

/** Titulo de cada modulo, usado no assunto do e-mail de relatorio. */
$MODULOS_TITULOS = [
    'acessos' => 'Acessos',
    'mudancas' => 'Mudanças',
    'backup' => 'Backup',
    'restore' => 'Restore',
    'dicionario' => 'Dicionário',
    'integracoes' => 'Integrações',
    'jobs' => 'Jobs',
];

/**
 * Rotulo de cada coluna por modulo -- mesmo texto que aparece nos
 * formularios/cabecalhos do front (js/app.js, SCHEMA) -- pra o CSV mandado
 * por e-mail (backend/mailer.php) sair com o mesmo cabecalho do CSV
 * exportado direto pelo navegador.
 */
$COLUNA_LABELS = [
    'acessos' => [
        'data' => 'Data da concessão', 'usuario' => 'Usuário / login', 'tipo' => 'Tipo de conta',
        'servidor' => 'Servidor', 'objeto' => 'Banco / objeto', 'nivel' => 'Nível de acesso', 'justificativa' => 'Justificativa',
        'solicitante' => 'Solicitante', 'aprovador' => 'Aprovador', 'revisao' => 'Revisão prevista',
        'status' => 'Status', 'obs' => 'Observações', 'criado_por' => 'Adicionado por',
    ],
    'mudancas' => [
        'codigo' => 'Chamado', 'data' => 'Data', 'ambiente' => 'Ambiente', 'tipo' => 'Tipo',
        'descricao' => 'Descrição da mudança', 'script' => 'Objetos',
        'rollback' => 'Plano de rollback', 'solicitante' => 'Solicitante', 'aprovador' => 'Aprovador',
        'status' => 'Status', 'resultado' => 'Resultado / observações', 'criado_por' => 'Adicionado por',
    ],
    'backup' => [
        'banco' => 'Banco', 'criticidade' => 'Criticidade', 'tipo' => 'Tipo de backup',
        'frequencia' => 'Frequência', 'horario' => 'Horário', 'retencao' => 'Retenção',
        'local' => 'Local de armazenamento', 'rpo' => 'RPO alvo', 'rto' => 'RTO alvo', 'responsavel' => 'Responsável', 'criado_por' => 'Adicionado por',
    ],
    'restore' => [
        'data' => 'Data do teste', 'banco' => 'Banco', 'backup' => 'Backup testado', 'tempo' => 'Tempo de restore',
        'resultado' => 'Resultado', 'por' => 'Testado por', 'obs' => 'Observações', 'criado_por' => 'Adicionado por',
    ],
    'dicionario' => [
        'servidor' => 'Servidor', 'banco' => 'Banco', 'schema_nome' => 'Schema', 'tabela' => 'Tabela', 'coluna' => 'Coluna',
        'tipo_dado' => 'Tipo de dado', 'permite_nulo' => 'Permite nulo?', 'descricao' => 'Descrição / significado',
        'classificacao' => 'Classificação', 'origem' => 'Origem / sistema', 'obs' => 'Observações',
    ],
    'integracoes' => [
        'nome' => 'Integração', 'origem' => 'Sistema de origem', 'ip_origem' => 'IP de origem',
        'destino' => 'Sistema de destino', 'ip_destino' => 'IP de destino',
        'tipo' => 'Tipo', 'direcao' => 'Direção', 'mecanismo' => 'Mecanismo', 'frequencia' => 'Frequência',
        'dados_trafegados' => 'Dados trafegados', 'classificacao' => 'Classificação do dado',
        'criticidade' => 'Criticidade', 'resp_tecnico' => 'Responsável técnico', 'resp_negocio' => 'Responsável de negócio',
        'ambiente' => 'Ambiente', 'status' => 'Status', 'ultima_revisao' => 'Última revisão', 'obs' => 'Observações', 'criado_por' => 'Adicionado por',
    ],
    'jobs' => [
        'nome' => 'Job', 'tipo' => 'Tipo', 'servidor' => 'Servidor / instância', 'banco' => 'Banco',
        'descricao' => 'Descrição / finalidade', 'comando' => 'Comando / Passo', 'frequencia' => 'Frequência',
        'horario' => 'Tempo da Frequência', 'agendamentos' => 'Agendamentos',
        'criticidade' => 'Criticidade', 'status' => 'Status', 'responsavel' => 'Responsável',
        'obs' => 'Observações', 'criado_por' => 'Adicionado por',
    ],
];

/**
 * Categorias de listas geridas pelo menu Cadastro -- listas de selecao
 * usadas dentro das telas de Mudancas, Backup (inclui o sub-bloco de
 * Restore, que mora na mesma tela), Acessos (niveis de acesso) e
 * Integracoes (tipo, ambiente, criticidade e status da integracao).
 */
$CATEGORIAS_TIPOS = [
    'mudanca_ambiente', 'mudanca_tipo', 'mudanca_status',
    'backup_criticidade', 'backup_tipo', 'backup_resultado',
    'acesso_nivel',
    'integracao_tipo', 'integracao_ambiente', 'integracao_criticidade', 'integracao_status',
    'job_tipo', 'job_frequencia', 'job_criticidade', 'job_status',
];

// ---------------------------------------------------------------------------
// Roles e permissoes -- 3 papeis (role na tabela usuarios):
//   admin    -> acesso total a tudo, sempre.
//   escrita  -> leitura E escrita, mas so nos modulos da sua lista.
//   leitura  -> so leitura, e so nos modulos da sua lista.
// A "lista" e a coluna modulos_permitidos (texto "acessos,mudancas,...")
// com as chaves de $MODULOS que aquele login pode usar.
// ---------------------------------------------------------------------------

/**
 * Le a coluna modulos_permitidos do usuario como array de chaves.
 *
 * @param array<string, mixed> $user
 * @return list<string>
 */
function modulosPermitidos(array $user): array
{
    $bruto = (string) ($user['modulos_permitidos'] ?? '');
    $itens = array_filter(array_map('trim', explode(',', $bruto)), fn ($v) => $v !== '');
    return array_values($itens);
}

/**
 * true se o usuario pode acessar o modulo $prefixo no nivel pedido.
 *
 * @param array<string, mixed> $user
 */
function moduloPermitido(array $user, string $prefixo, bool $escrita): bool
{
    $role = (string) ($user['role'] ?? 'leitura');
    if ($role === 'admin' || $role === 'master') {
        return true;
    }
    if ($escrita && $role !== 'escrita') {
        return false;
    }
    return in_array($prefixo, modulosPermitidos($user), true);
}

/**
 * Igual moduloPermitido(), mas ja encerra a requisicao com 403 se nao puder.
 *
 * @param array<string, mixed> $user
 */
function exigirAcessoModulo(array $user, string $prefixo, bool $escrita): void
{
    if (!moduloPermitido($user, $prefixo, $escrita)) {
        $acao = $escrita ? 'alterar dados' : 'visualizar';
        responderErro(403, "Voce nao tem permissao para {$acao} neste modulo.");
    }
}

/**
 * Modulos que o usuario pode VER no dashboard/relatorios. admin/master veem
 * todos; os demais, so os da propria lista (mesma regra de moduloPermitido(),
 * sempre em nivel de leitura -- dashboard nunca escreve nada).
 */
/**
 * @param array<string, mixed> $user
 * @return list<string>
 */
function modulosVisiveis(array $user): array
{
    global $MODULOS;
    $role = (string) ($user['role'] ?? 'leitura');
    if ($role === 'admin' || $role === 'master') {
        /** @var list<string> $keys */
        $keys = array_keys($MODULOS);
        return $keys;
    }
    return modulosPermitidos($user);
}

// ---------------------------------------------------------------------------
// Helpers para a sub-tabela mudancas_objetos
// ---------------------------------------------------------------------------

/**
 * Retorna todos os objetos de uma mudanca, em ordem de insercao.
 * @return array<int, array<string, mixed>>
 */
function obterObjMudanca(PDO $pdo, int $mudancaId): array
{
    $tab  = quoteIdent(tableName('mudancas_objetos'));
    $stmt = $pdo->prepare(
        "SELECT id, nome, tipo FROM {$tab} WHERE mudanca_id = ? ORDER BY id"
    );
    $stmt->execute([$mudancaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Substitui os objetos de uma mudanca pelos enviados no corpo.
 * Apaga tudo e reinsere -- simples e seguro para listas pequenas.
 * @param array<int, array<string, mixed>> $objetos
 */
function sincronizarObjMudanca(PDO $pdo, int $mudancaId, array $objetos): void
{
    $tab  = quoteIdent(tableName('mudancas_objetos'));
    $pdo->prepare("DELETE FROM {$tab} WHERE mudanca_id = ?")->execute([$mudancaId]);
    $agora = date('Y-m-d H:i:s');
    $cMid  = quoteIdent('mudanca_id');
    $cNome = quoteIdent('nome');
    $cTipo = quoteIdent('tipo');
    $cData = quoteIdent('criado_em');
    foreach ($objetos as $obj) {
        $nome = trim((string) ($obj['nome'] ?? ''));
        if ($nome === '') {
            continue;
        }
        $tipo = trim((string) ($obj['tipo'] ?? ''));
        $pdo->prepare(
            "INSERT INTO {$tab} ({$cMid}, {$cNome}, {$cTipo}, {$cData}) VALUES (?, ?, ?, ?)"
        )->execute([$mudancaId, $nome, $tipo !== '' ? $tipo : null, $agora]);
    }
}


// ---------------------------------------------------------------------------
// Roteador -- compara metodo + caminho (sem prefixo "/api") e despacha.
// ---------------------------------------------------------------------------

function despachar(string $metodo, string $caminho): void
{
    global $MODULOS, $CATEGORIAS_TIPOS, $COLUNA_LABELS, $MODULOS_TITULOS;

    // Exponho metodo/caminho da requisicao para funcoes chamadas indiretamente
    // (ex.: o gate de must_change_password dentro de exigirLogin) saberem qual
    // rota esta sendo acessada.
    $GLOBALS['REQ_METODO']  = $metodo;
    $GLOBALS['REQ_CAMINHO'] = $caminho;

    if ($metodo === 'GET' && $caminho === '/') {
        responderJson(['ok' => true, 'titulo' => projectTitle()]);
    }

    // ---- /auth -------------------------------------------------------
    if ($metodo === 'POST' && $caminho === '/auth/login') {
        $body = corpoRequisicao();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            responderErro(422, 'Informe usuario e senha.');
        }

        // Protecao contra forca bruta (ver backend/auth.php): bloqueia esse
        // par usuario+IP por um tempo se ja errou demais antes de gastar uma
        // consulta no banco pra checar a senha.
        $ip = clienteIp();
        $minutosRestantes = checarBloqueioLogin($username, $ip);
        if ($minutosRestantes !== null) {
            responderErro(429, "Muitas tentativas de login. Tente novamente em {$minutosRestantes} minuto(s).");
        }
        // Segundo limite, por IP (independente do username): barra password
        // spraying (uma senha testada contra muitos usernames do mesmo IP). O
        // agregado por IP usa a linha com usuario = '' na tentativas_login.
        $minutosRestantesIp = checarBloqueioLogin('', $ip);
        if ($minutosRestantesIp !== null) {
            responderErro(429, "Muitas tentativas de login a partir deste endereco. Tente novamente em {$minutosRestantesIp} minuto(s).");
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('username') . ' = ?');
        $stmt->execute([$username]);
        /** @var array<string, mixed>|false $user */
        $user = $stmt->fetch();

        // Timing-safe: quando o usuario nao existe, rodamos um bcrypt contra
        // um hash dummy para que o tempo de resposta seja identico ao caso em
        // que o usuario existe mas a senha esta errada. Sem isso, medir a
        // diferenca de tempo permite descobrir logins validos (user enumeration).
        //
        // IMPORTANTE: o hash dummy deve ser VALIDO e ter o MESMO cost usado por
        // hashPassword() (PASSWORD_BCRYPT sem cost explicito = 10). Um hash
        // invalido retorna false imediatamente, sem rodar bcrypt, recriando
        // a diferenca de tempo que queremos eliminar.
        static $DUMMY_HASH = null;
        if ($DUMMY_HASH === null) {
            // Gerado UMA VEZ por processo PHP; o custo e identico ao dos usuarios reais.
            $DUMMY_HASH = password_hash('gdt-timing-sentinel', PASSWORD_BCRYPT);
        }
        /** @var string $dummyHash */
        $dummyHash    = (string) $DUMMY_HASH;
        $hashVerificar = ($user !== false) ? (string) $user['password_hash'] : $dummyHash;
        $senhaCorreta  = verifyPassword($password, $hashVerificar) && $user !== false;
        if (!$senhaCorreta) {
            registrarTentativaFalhaLogin($username, $ip);
            registrarTentativaFalhaLogin('', $ip, LOGIN_IP_MAX_TENTATIVAS);
            // Distingue usuario inexistente de senha errada em conta valida --
            // ajuda a separar ruido de ataque real na analise forense.
            $acaoFalha = ($user === false) ? 'login_falha_usuario_inexistente' : 'login_falha';
            registrarAuditoria('auth', null, $acaoFalha, $username);
            responderErro(401, 'Usuario ou senha invalidos.');
        }

        if ((int) ($user['ativo'] ?? 1) === 0) {
            // Nao revelar que a senha estava correta (enumeracao de credenciais).
            // O motivo real fica apenas na auditoria.
            registrarTentativaFalhaLogin($username, $ip);
            registrarAuditoria('auth', null, 'login_falha_conta_desativada', $username);
            responderErro(401, 'Usuario ou senha invalidos.');
        }

        limparTentativasLogin($username, $ip);

        // MFA por e-mail (ver backend/auth.php): se o usuario ativou o
        // segundo fator, ainda nao devolve o token de acesso -- manda um
        // codigo por e-mail e devolve so um "mfa_token" temporario,
        // identificando essa tentativa pro passo seguinte
        // (/auth/mfa/verificar). Sem e-mail cadastrado o MFA fica
        // inutilizavel; nesse caso (raro -- so acontece se o e-mail foi
        // removido depois de ativar) preferimos nao trancar o usuario fora
        // da propria conta, so registramos na auditoria.
        if ((int) ($user['mfa_ativo'] ?? 0) === 1 && !empty($user['email'])) {
            [$mfaToken, $codigo] = mfaGerarCodigo((string) $user['username']);
            $cfgEmail = configEmail();
            try {
                if ($cfgEmail === null) {
                    throw new RuntimeException('Servidor de e-mail nao configurado.');
                }
                smtpEnviar(
                    $cfgEmail,
                    (string) $user['email'],
                    projectTitle() . ' - Codigo de verificacao',
                    "Seu codigo de verificacao e: {$codigo}\n\nEle expira em " . MFA_CODIGO_TTL_MINUTOS . ' minutos.'
                );
            } catch (Throwable $e) {
                registrarAuditoria('auth', (int) $user['id'], 'mfa_envio_falhou', (string) $user['username']);
                error_log('Falha ao enviar codigo MFA: ' . $e->getMessage());
                $detalhe = (config()['app_debug'] ?? false) === true ? $e->getMessage() : null;
                responderErro(400, 'Nao foi possivel enviar o codigo de verificacao.' . ($detalhe !== null ? ' ' . $detalhe : ''));
            }
            registrarAuditoria('auth', (int) $user['id'], 'login_mfa_pendente', (string) $user['username']);
            responderJson(['mfa_required' => true, 'mfa_token' => $mfaToken]);
        }
        if ((int) ($user['mfa_ativo'] ?? 0) === 1) {
            registrarAuditoria('auth', (int) $user['id'], 'mfa_sem_email', (string) $user['username']);
        }

        $token = criarToken(['sub' => (string) $user['username'], 'role' => (string) $user['role']]);
        registrarAuditoria('auth', (int) $user['id'], 'login_sucesso', (string) $user['username']);
        $mustChange = (int) ($user['must_change_password'] ?? 0) === 1;
        responderJson(['access_token' => $token, 'token_type' => 'bearer', 'must_change_password' => $mustChange]);
    }

    // Segunda etapa do login com MFA: confere o codigo de 6 digitos contra o
    // mfa_token devolvido por /auth/login. So aqui o token de acesso de
    // verdade e emitido.
    if ($metodo === 'POST' && $caminho === '/auth/mfa/verificar') {
        $body = corpoRequisicao();
        $mfaToken = trim((string) ($body['mfa_token'] ?? ''));
        $codigo = trim((string) ($body['codigo'] ?? ''));

        $usuario = mfaUsuarioPorToken($mfaToken);
        $erro = mfaConferirCodigo($mfaToken, $codigo);
        if ($erro !== null) {
            registrarAuditoria('auth', null, 'login_mfa_falha', $usuario);
            responderErro(401, $erro);
        }
        mfaApagarPorToken($mfaToken);

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('username') . ' = ?');
        $stmt->execute([$usuario]);
        /** @var array<string, mixed>|false $user */
        $user = $stmt->fetch();
        if ($user === false) {
            responderErro(401, 'Sessao de verificacao invalida. Faca login novamente.');
        }

        $token = criarToken(['sub' => (string) $user['username'], 'role' => (string) $user['role']]);
        registrarAuditoria('auth', (int) $user['id'], 'login_sucesso', (string) $user['username']);
        $mustChange = (int) ($user['must_change_password'] ?? 0) === 1;
        responderJson(['access_token' => $token, 'token_type' => 'bearer', 'must_change_password' => $mustChange]);
    }

    // Reenvia um codigo novo (invalida o anterior e o mfa_token muda --
    // o front-end precisa atualizar o mfa_token guardado com o devolvido aqui).
    if ($metodo === 'POST' && $caminho === '/auth/mfa/reenviar') {
        $body = corpoRequisicao();
        $mfaTokenAntigo = trim((string) ($body['mfa_token'] ?? ''));
        $usuario = mfaUsuarioPorToken($mfaTokenAntigo);
        if ($usuario === null) {
            responderErro(401, 'Sessao de verificacao expirada. Faca login novamente.');
        }

        // Rate limit: cooldown de 60s e maximo de 5 reenvios por codigo.
        $erroRateLimit = mfaChecarReenvio($mfaTokenAntigo);
        if ($erroRateLimit !== null) {
            responderErro(429, $erroRateLimit);
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('username') . ' = ?');
        $stmt->execute([$usuario]);
        /** @var array<string, mixed>|false $user */
        $user = $stmt->fetch();
        if ($user === false || empty($user['email'])) {
            responderErro(400, 'Nao foi possivel reenviar o codigo.');
        }

        // Le o contador ja incrementado por mfaChecarReenvio() e carrega-o
        // para a linha nova (mfaGerarCodigo recria a linha). Sem isso o teto
        // e o cooldown de reenvio seriam zerados a cada reenvio.
        $stmtReenvios = $pdo->prepare('SELECT ' . quoteIdent('reenvios') . ' FROM ' . quoteIdent(tableName('mfa_codigos')) . ' WHERE ' . quoteIdent('token') . ' = ?');
        $stmtReenvios->execute([$mfaTokenAntigo]);
        $reenviosAtual = (int) $stmtReenvios->fetchColumn();

        [$novoToken, $codigo] = mfaGerarCodigo($usuario, $reenviosAtual, date('Y-m-d H:i:s'));
        $cfgEmail = configEmail();
        try {
            if ($cfgEmail === null) {
                throw new RuntimeException('Servidor de e-mail nao configurado.');
            }
            smtpEnviar(
                $cfgEmail,
                (string) $user['email'],
                projectTitle() . ' - Codigo de verificacao',
                "Seu codigo de verificacao e: {$codigo}\n\nEle expira em " . MFA_CODIGO_TTL_MINUTOS . ' minutos.'
            );
        } catch (Throwable $e) {
            error_log('Falha ao reenviar codigo MFA: ' . $e->getMessage());
            $detalhe = (config()['app_debug'] ?? false) === true ? $e->getMessage() : null;
            responderErro(400, 'Nao foi possivel reenviar o codigo.' . ($detalhe !== null ? ' ' . $detalhe : ''));
        }
        responderJson(['mfa_token' => $novoToken]);
    }

    // Ativa/desativa o MFA da PROPRIA conta -- exige confirmar a senha atual
    // (mesmo cuidado do /auth/change-password), pra alguem com a sessao
    // aberta numa maquina compartilhada nao conseguir desligar a propria
    // protecao sem saber a senha. So pode ativar com e-mail cadastrado.
    if ($metodo === 'PUT' && $caminho === '/auth/mfa') {
        $user = exigirLogin();
        $body = corpoRequisicao();
        $ativo = !empty($body['ativo']);
        $senhaAtual = (string) ($body['senha_atual'] ?? '');

        if ($senhaAtual === '' || !verifyPassword($senhaAtual, (string) $user['password_hash'])) {
            responderErro(400, 'Senha atual incorreta.');
        }
        if ($ativo && empty($user['email'])) {
            responderErro(422, 'Cadastre um e-mail no seu perfil antes de ativar a verificacao em duas etapas.');
        }
        // So deixa ativar se o servico de e-mail estiver configurado E com um
        // teste de envio confirmado (testado_ok) -- senao o usuario fica sem
        // receber o codigo no proximo login e trava o proprio acesso.
        if ($ativo && !emailProntoParaUso()) {
            responderErro(422, 'O servico de e-mail ainda nao esta configurado/testado. Peca a um administrador para configurar e testar o envio em Administracao > E-mail antes de ativar a verificacao em duas etapas.');
        }

        $pdo = db();
        $pdo->prepare(
            'UPDATE ' . quoteIdent(tableName('usuarios')) . ' SET ' . quoteIdent('mfa_ativo') . ' = ?, ' .
            quoteIdent('atualizado_em') . ' = ? WHERE ' . quoteIdent('id') . ' = ?'
        )->execute([$ativo ? 1 : 0, date('Y-m-d H:i:s'), $user['id']]);

        registrarAuditoria('auth', (int) $user['id'], $ativo ? 'mfa_ativado' : 'mfa_desativado', (string) $user['username']);

        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$user['id']]);
        /** @var array<string, mixed>|false $atualizado */
        $atualizado = $stmt->fetch();
        responderJson(semSenha($atualizado !== false ? $atualizado : []));
    }

    if ($metodo === 'GET' && $caminho === '/auth/me') {
        $user = exigirLogin();
        $meus = semSenha($user);
        $meus['tem_foto'] = usuarioTemFoto((int) $user['id']);
        responderJson($meus);
    }

    // Auto-atualizacao do proprio perfil (nome/e-mail) -- qualquer usuario
    // logado pode editar os PROPRIOS dados, sem precisar ser admin. Editar
    // dados de OUTRO usuario continua sendo so via /usuarios (admin).
    if ($metodo === 'PUT' && $caminho === '/auth/me') {
        $user = exigirLogin();
        $body = corpoRequisicao();
        $nome = trim((string) ($body['nome_completo'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            responderErro(422, 'E-mail invalido.');
        }
        $temasValidos = ['padrao', 'azul', 'verde', 'violeta'];
        $corTema = (string) ($body['cor_tema'] ?? $user['cor_tema'] ?? 'padrao');
        if (!in_array($corTema, $temasValidos, true)) {
            $corTema = 'padrao';
        }
        $estilosValidos = ['claro', 'slate'];
        $estiloSide = (string) ($body['estilo_side'] ?? $user['estilo_side'] ?? 'claro');
        if (!in_array($estiloSide, $estilosValidos, true)) {
            $estiloSide = 'claro';
        }

        $pdo = db();
        $sql = 'UPDATE ' . quoteIdent(tableName('usuarios')) . ' SET ' . quoteIdent('nome_completo') . ' = ?, ' .
            quoteIdent('email') . ' = ?, ' . quoteIdent('cor_tema') . ' = ?, ' . quoteIdent('estilo_side') . ' = ?, ' . quoteIdent('atualizado_em') . ' = ? WHERE ' . quoteIdent('id') . ' = ?';
        $pdo->prepare($sql)->execute([
            $nome !== '' ? $nome : null,
            $email !== '' ? $email : null,
            $corTema,
            $estiloSide,
            date('Y-m-d H:i:s'),
            $user['id'],
        ]);

        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$user['id']]);
        /** @var array<string, mixed>|false $perfil */
        $perfil = $stmt->fetch();
        responderJson(semSenha($perfil !== false ? $perfil : []));
    }

    if ($metodo === 'POST' && $caminho === '/auth/change-password') {
        $user = exigirLogin();
        $body = corpoRequisicao();
        $senhaAtual = (string) ($body['senha_atual'] ?? '');
        $novaSenha  = (string) ($body['nova_senha'] ?? '');
        // 'force' so e aceito quando must_change_password=1 (fluxo de
        // primeiro login / senha redefinida pelo admin). Nesse caso nao
        // exigimos a senha atual pois o usuario pode nao conhece-la.
        $force = ($body['force'] ?? false) === true && (int) ($user['must_change_password'] ?? 0) === 1;

        if ($novaSenha === '') {
            responderErro(422, 'Informe a nova senha.');
        }
        if (!$force) {
            if ($senhaAtual === '') {
                responderErro(422, 'Informe a senha atual e a nova senha.');
            }
            if (!verifyPassword($senhaAtual, (string) $user['password_hash'])) {
                responderErro(400, 'Senha atual incorreta.');
            }
        }
        $erroSenha = avaliarForcaSenha($novaSenha);
        if ($erroSenha !== null) {
            responderErro(400, $erroSenha);
        }

        $pdo = db();
        $sql = 'UPDATE ' . quoteIdent(tableName('usuarios')) . ' SET '
             . quoteIdent('password_hash')       . ' = ?, '
             . quoteIdent('must_change_password') . ' = 0, '
             . quoteIdent('atualizado_em')        . ' = ? WHERE ' . quoteIdent('id') . ' = ?';
        $pdo->prepare($sql)->execute([hashPassword($novaSenha), date('Y-m-d H:i:s'), $user['id']]);

        // Revoga o token atual -- se alguem roubou a sessao antes da troca
        // de senha, so trocar a senha nao bastaria: o token antigo
        // continuaria valido ate vencer naturalmente. Revogando aqui, esse
        // acesso e encerrado na mesma hora (ver revogarToken() em
        // backend/auth.php).
        $payloadAtual = decodificarToken(substr(cabecalhoAutorizacao(), 7));
        if ($payloadAtual && !empty($payloadAtual['jti'])) {
            revogarToken((string) $payloadAtual['jti'], (int) ($payloadAtual['exp'] ?? time()));
        }

        registrarAuditoria('auth', (int) $user['id'], 'trocar_senha', (string) $user['username']);
        responderJson(['ok' => true]);
    }

    if ($metodo === 'POST' && $caminho === '/auth/logout') {
        $user = exigirLogin();
        $payloadAtual = decodificarToken(substr(cabecalhoAutorizacao(), 7));
        if ($payloadAtual && !empty($payloadAtual['jti'])) {
            revogarToken((string) $payloadAtual['jti'], (int) ($payloadAtual['exp'] ?? time()));
        }
        registrarAuditoria('auth', (int) $user['id'], 'logout', (string) $user['username']);
        responderJson(['ok' => true]);
    }

    // ---- /usuarios (contas do portal, so admin) -----------------------
    if ($caminho === '/usuarios' && $metodo === 'GET') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $pdo = db();
        $stmtAll = $pdo->query('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' ORDER BY ' . quoteIdent('id'));
        if ($stmtAll === false) {
            responderErro(500, 'Erro ao consultar usuarios.');
        }
        /** @var list<array<string, mixed>> $todosUsuarios */
        $todosUsuarios = $stmtAll->fetchAll();
        // Quem tem foto: uma consulta unica so com os ids, sem trazer imagem.
        $comFoto = [];
        $stmtF = $pdo->query('SELECT ' . quoteIdent('usuario_id') . ' FROM ' . quoteIdent(tableName('usuarios_foto')));
        if ($stmtF !== false) {
            foreach ($stmtF->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $comFoto[(int) $uid] = true;
            }
        }
        responderJson(array_map(static function (array $u) use ($comFoto): array {
            $lim = semSenha($u);
            $lim['tem_foto'] = isset($comFoto[(int) $u['id']]);
            return $lim;
        }, $todosUsuarios));
    }

    if ($caminho === '/usuarios' && $metodo === 'POST') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $body = corpoRequisicao();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? 'leitura');
        // So o proprio master pode conceder o papel Master (evita escalonamento
        // de privilegio por um administrador comum).
        if ($role === 'master' && (string) ($admin['role'] ?? '') !== 'master') {
            responderErro(403, 'Apenas o usuario master pode conceder o papel Master.');
        }
        $rolesPermitidos = ['leitura', 'escrita', 'admin'];
        if ((string) ($admin['role'] ?? '') === 'master') {
            $rolesPermitidos[] = 'master';
        }
        if (!in_array($role, $rolesPermitidos, true)) {
            $role = 'leitura';
        }

        // Se a senha vier em branco, gera uma senha temporária aleatória
        // e marcamos o usuario para trocar no primeiro login.
        $senhaTemporaria = false;
        if ($password === '') {
            $password = bin2hex(random_bytes(6)); // 12 caracteres hex
            $senhaTemporaria = true;
        }
        if ($username === '') {
            responderErro(422, 'Informe o login.');
        }
        $erroSenha = avaliarForcaSenha($password);
        if ($erroSenha !== null) {
            responderErro(422, $erroSenha);
        }
        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            responderErro(422, 'E-mail invalido.');
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('username') . ' = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            responderErro(400, 'Ja existe um usuario com esse login.');
        }

        // 'admin' nao precisa de lista (acesso total); leitura/escrita usam
        // so os modulos marcados na hora de criar o usuario.
        $modulosEnviados = is_array($body['modulos'] ?? null) ? $body['modulos'] : [];
        $modulosEnviadosStr = array_map(static fn (mixed $v): string => (string) $v, $modulosEnviados);
        $modulosValidos = array_values(array_intersect($modulosEnviadosStr, array_keys($MODULOS)));
        $modulosTexto = implode(',', $modulosValidos);

        $agora = date('Y-m-d H:i:s');
        $colunas = implode(', ', array_map('quoteIdent', [
            'username', 'nome_completo', 'email', 'role', 'password_hash', 'modulos_permitidos', 'must_change_password', 'criado_em', 'atualizado_em',
        ]));
        $valores = [
            $username,
            $body['nome_completo'] ?? null,
            $email !== '' ? $email : null,
            $role,
            hashPassword($password),
            $modulosTexto,
            $senhaTemporaria ? 1 : 0,
            $agora,
            $agora,
        ];
        $sql = 'INSERT INTO ' . quoteIdent(tableName('usuarios')) . " ({$colunas}) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        if (dbDriver() === 'pgsql') {
            $sql .= ' RETURNING ' . quoteIdent('id');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($valores);
            $novoId = (int) $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($valores);
            $novoId = (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$novoId]);
        /** @var array<string, mixed>|false $novoUsuario */
        $novoUsuario = $stmt->fetch();
        $novoUsuarioArr = $novoUsuario !== false ? $novoUsuario : [];
        registrarAuditoria('usuarios', $novoId, 'criar', (string) $admin['username'], null, semSenha($novoUsuarioArr));

        // Envia e-mail de boas-vindas com as credenciais.
        // Falha silenciosa: usuario foi criado com sucesso mesmo que o e-mail falhe.
        if ($email !== '' && $senhaTemporaria) {
            $cfgEmail = configEmail();
            if ($cfgEmail !== null) {
                $titulo = projectTitle();
                $corpo  = "Olá! Sua conta no {$titulo} foi criada.\n\n"
                        . "Login: {$username}\n"
                        . "Senha temporária: {$password}\n\n"
                        . "Você será solicitado a definir uma nova senha no primeiro acesso.\n"
                        . "Acesse o portal e faça login com as credenciais acima.";
                try {
                    smtpEnviar($cfgEmail, $email, "Bem-vindo ao {$titulo} — suas credenciais", $corpo);
                } catch (Throwable $e) {
                    error_log('Falha ao enviar e-mail de boas-vindas: ' . $e->getMessage());
                }
            }
        }

        responderJson(semSenha($novoUsuarioArr), 201);
    }

    // ---- /usuarios/{id} (editar dados: nome, email, senha, ativo) -------
    if (preg_match('#^/usuarios/(\d+)$#', $caminho, $m) && $metodo === 'PUT') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $id   = (int) $m[1];
        $body = corpoRequisicao();
        $pdo  = db();

        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $alvo */
        $alvo = $stmt->fetch();
        if ($alvo === false) {
            responderErro(404, 'Usuário não encontrado.');
        }

        $agora       = date('Y-m-d H:i:s');
        $sets        = [];
        $vals        = [];

        if (array_key_exists('nome_completo', $body)) {
            $sets[] = quoteIdent('nome_completo') . ' = ?';
            $vals[] = trim((string) ($body['nome_completo'] ?? '')) ?: null;
        }
        if (array_key_exists('email', $body)) {
            $emailNovo = trim((string) ($body['email'] ?? ''));
            if ($emailNovo !== '' && !filter_var($emailNovo, FILTER_VALIDATE_EMAIL)) {
                responderErro(422, 'E-mail inválido.');
            }
            $sets[] = quoteIdent('email') . ' = ?';
            $vals[] = $emailNovo ?: null;
        }
        if (array_key_exists('ativo', $body)) {
            $sets[] = quoteIdent('ativo') . ' = ?';
            $vals[] = (int) ($body['ativo'] ?? 1);
        }
        if (array_key_exists('password', $body) && (string) ($body['password'] ?? '') !== '') {
            $novaSenha = (string) $body['password'];
            $erroSenha = avaliarForcaSenha($novaSenha);
            if ($erroSenha !== null) { responderErro(422, $erroSenha); }
            $sets[] = quoteIdent('password_hash') . ' = ?';
            $vals[] = hashPassword($novaSenha);
            // Ao redefinir a senha pelo admin, o usuário deve trocar no próximo login.
            $sets[] = quoteIdent('must_change_password') . ' = ?';
            $vals[] = 1;
            // Revogar tokens ativos para forçar novo login imediato.
            revogarTodosTokens($id);
        }

        if (empty($sets)) {
            responderJson(semSenha((array) $alvo));
        }

        $sets[] = quoteIdent('atualizado_em') . ' = ?';
        $vals[] = $agora;
        $vals[] = $id;

        $antes = semSenha((array) $alvo);
        $pdo->prepare(
            'UPDATE ' . quoteIdent(tableName('usuarios')) . ' SET ' . implode(', ', $sets) . ' WHERE ' . quoteIdent('id') . ' = ?'
        )->execute($vals);

        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $atualizado */
        $atualizado = $stmt->fetch();
        $atualizadoArr = $atualizado !== false ? semSenha((array) $atualizado) : $antes;
        registrarAuditoria('usuarios', $id, 'atualizar', (string) $admin['username'], $antes, $atualizadoArr);
        responderJson($atualizadoArr);
    }

    // ---- /usuarios/{id}/foto (ver, definir e remover a foto do usuario) ---
    // A imagem mora na tabela usuarios_foto (ver migrate() em backend/db.php).
    // Cada um mexe na propria foto; administrador mexe na de qualquer um.
    if (preg_match('#^/usuarios/(\d+)/foto$#', $caminho, $m)) {
        $user = exigirLogin();
        $id = (int) $m[1];
        $ehDono = (int) $user['id'] === $id;
        $ehAdmin = in_array((string) ($user['role'] ?? ''), ['admin', 'master'], true);
        $pdo = db();
        $tabFoto = quoteIdent(tableName('usuarios_foto'));

        // Ver: qualquer usuario logado (as fotos aparecem em listas do portal).
        if ($metodo === 'GET') {
            $stmt = $pdo->prepare('SELECT ' . quoteIdent('foto') . ' FROM ' . $tabFoto . ' WHERE ' . quoteIdent('usuario_id') . ' = ?');
            $stmt->execute([$id]);
            $foto = $stmt->fetchColumn();
            if ($foto === false) {
                responderErro(404, 'Usuario sem foto.');
            }
            responderJson(['foto' => (string) $foto]);
        }

        if (!$ehDono && !$ehAdmin) {
            responderErro(403, 'Voce so pode alterar a sua propria foto.');
        }

        if ($metodo === 'PUT') {
            $body = corpoRequisicao();
            $foto = trim((string) ($body['foto'] ?? ''));
            if ($foto === '') {
                responderErro(422, 'Envie a imagem.');
            }
            // ~1MB de data URI ja e folgado: o front reduz para 256x256 antes
            // de enviar, o que costuma dar menos de 60KB.
            $erro = erroImagemDataUri($foto, 1_100_000);
            if ($erro !== null) {
                responderErro(422, $erro);
            }
            $agora = date('Y-m-d H:i:s');
            $existe = usuarioTemFoto($id);
            if ($existe) {
                $pdo->prepare('UPDATE ' . $tabFoto . ' SET ' . quoteIdent('foto') . ' = ?, ' . quoteIdent('atualizado_em') . ' = ? WHERE ' . quoteIdent('usuario_id') . ' = ?')
                    ->execute([$foto, $agora, $id]);
            } else {
                $pdo->prepare('INSERT INTO ' . $tabFoto . ' (' . quoteIdent('usuario_id') . ', ' . quoteIdent('foto') . ', ' . quoteIdent('atualizado_em') . ') VALUES (?, ?, ?)')
                    ->execute([$id, $foto, $agora]);
            }
            registrarAuditoria('usuarios', $id, 'atualizar', (string) $user['username'], null, ['foto' => 'atualizada']);
            responderJson(['ok' => true]);
        }

        if ($metodo === 'DELETE') {
            $pdo->prepare('DELETE FROM ' . $tabFoto . ' WHERE ' . quoteIdent('usuario_id') . ' = ?')->execute([$id]);
            registrarAuditoria('usuarios', $id, 'atualizar', (string) $user['username'], null, ['foto' => 'removida']);
            responderVazio(204);
        }
    }

    // ---- /usuarios/{id}/permissoes (definir role + modulos, so admin) --
    if (preg_match('#^/usuarios/(\d+)/permissoes$#', $caminho, $m) && $metodo === 'PUT') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $id = (int) $m[1];
        $body = corpoRequisicao();

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $alvo */
        $alvo = $stmt->fetch();
        if ($alvo === false) {
            responderErro(404, 'Usuario nao encontrado.');
        }

        // So o proprio master pode alterar as permissoes de um usuario
        // master -- sem isso, um administrador comum poderia rebaixar o
        // unico master do portal e ficar sem ninguem com acesso a
        // auditoria.
        if ((string) ($alvo['role'] ?? '') === 'master' && (string) ($admin['role'] ?? '') !== 'master') {
            responderErro(403, 'Apenas o usuario master pode alterar outro usuario master.');
        }

        $role = (string) ($body['role'] ?? $alvo['role']);
        // So o proprio master pode conceder o papel Master.
        if ($role === 'master' && (string) ($admin['role'] ?? '') !== 'master') {
            responderErro(403, 'Apenas o usuario master pode conceder o papel Master.');
        }
        $rolesPermitidos = ['leitura', 'escrita', 'admin'];
        if ((string) ($admin['role'] ?? '') === 'master') {
            $rolesPermitidos[] = 'master';
        }
        if (!in_array($role, $rolesPermitidos, true)) {
            responderErro(422, 'Role invalida. Use leitura, escrita, admin ou master.');
        }
        $modulosEnviados = is_array($body['modulos'] ?? null) ? $body['modulos'] : [];
        $modulosEnviadosStr = array_map(static fn (mixed $v): string => (string) $v, $modulosEnviados);
        $modulosValidos = array_values(array_intersect($modulosEnviadosStr, array_keys($MODULOS)));
        $modulosTexto = implode(',', $modulosValidos);

        $sql = 'UPDATE ' . quoteIdent(tableName('usuarios')) . ' SET ' . quoteIdent('role') . ' = ?, ' .
            quoteIdent('modulos_permitidos') . ' = ?, ' . quoteIdent('atualizado_em') . ' = ? WHERE ' . quoteIdent('id') . ' = ?';
        $pdo->prepare($sql)->execute([$role, $modulosTexto, date('Y-m-d H:i:s'), $id]);

        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $usuarioAtualizado */
        $usuarioAtualizado = $stmt->fetch();
        $usuarioAtualizadoArr = $usuarioAtualizado !== false ? $usuarioAtualizado : [];
        registrarAuditoria(
            'usuarios',
            $id,
            'alterar_permissoes',
            (string) $admin['username'],
            semSenha($alvo),
            semSenha($usuarioAtualizadoArr)
        );
        responderJson(semSenha($usuarioAtualizadoArr));
    }

    if (preg_match('#^/usuarios/(\d+)$#', $caminho, $m) && $metodo === 'DELETE') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $id = (int) $m[1];

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $user */
        $user = $stmt->fetch();

        if ($user === false) {
            responderErro(404, 'Usuario nao encontrado.');
        }
        if ((int) $admin['id'] === $id) {
            responderErro(400, 'Voce nao pode remover seu proprio usuario enquanto esta logado com ele.');
        }
        if ((string) ($user['role'] ?? '') === 'master' && (string) ($admin['role'] ?? '') !== 'master') {
            responderErro(403, 'Apenas o usuario master pode remover outro usuario master.');
        }

        $pdo->prepare('DELETE FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?')->execute([$id]);
        // O projeto nao usa FOREIGN KEY: a limpeza da foto e feita aqui.
        $pdo->prepare('DELETE FROM ' . quoteIdent(tableName('usuarios_foto')) . ' WHERE ' . quoteIdent('usuario_id') . ' = ?')->execute([$id]);
        registrarAuditoria('usuarios', $id, 'excluir', (string) $admin['username'], semSenha($user), null);
        responderVazio(204);
    }

    // ---- /tipos (menu Cadastro: listas de opcoes de Mudancas/Backup) ----
    // Leitura liberada pra qualquer logado (e o que preenche os <select> dos
    // formularios de Mudancas/Backup); so admin pode adicionar/excluir.
    if ($metodo === 'GET' && $caminho === '/tipos') {
        exigirLogin();
        $pdo = db();
        $stmtTipos = $pdo->query(
            'SELECT * FROM ' . quoteIdent(tableName('config_tipos')) .
            ' ORDER BY ' . quoteIdent('categoria') . ', ' . quoteIdent('id')
        );
        if ($stmtTipos === false) {
            responderErro(500, 'Erro ao consultar tipos.');
        }
        responderJson($stmtTipos->fetchAll());
    }

    if ($metodo === 'POST' && $caminho === '/tipos') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $body = corpoRequisicao();
        $categoria = (string) ($body['categoria'] ?? '');
        $nome = trim((string) ($body['nome'] ?? ''));

        if (!in_array($categoria, $CATEGORIAS_TIPOS, true)) {
            responderErro(422, 'Categoria invalida.');
        }
        if ($nome === '') {
            responderErro(422, 'Informe um valor.');
        }

        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT id FROM ' . quoteIdent(tableName('config_tipos')) .
            ' WHERE ' . quoteIdent('categoria') . ' = ? AND LOWER(' . quoteIdent('nome') . ') = LOWER(?)'
        );
        $stmt->execute([$categoria, $nome]);
        if ($stmt->fetch()) {
            responderErro(400, 'Esse valor ja existe nessa lista.');
        }

        $agora = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO ' . quoteIdent(tableName('config_tipos')) . ' (' .
            implode(', ', array_map('quoteIdent', ['categoria', 'nome', 'criado_em'])) . ') VALUES (?, ?, ?)';

        if (dbDriver() === 'pgsql') {
            $sql .= ' RETURNING ' . quoteIdent('id');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$categoria, $nome, $agora]);
            $novoId = (int) $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$categoria, $nome, $agora]);
            $novoId = (int) $pdo->lastInsertId();
        }

        registrarAuditoria('tipos', $novoId, 'criar', (string) $admin['username'], null, ['categoria' => $categoria, 'nome' => $nome]);
        responderJson(['id' => $novoId, 'categoria' => $categoria, 'nome' => $nome], 201);
    }

    if (preg_match('#^/tipos/(\d+)$#', $caminho, $m) && $metodo === 'PUT') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $id = (int) $m[1];
        $body = corpoRequisicao();
        $nome = trim((string) ($body['nome'] ?? ''));
        if ($nome === '') {
            responderErro(422, 'Informe um valor.');
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('config_tipos')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $atual */
        $atual = $stmt->fetch();
        if ($atual === false) {
            responderErro(404, 'Item nao encontrado.');
        }

        $dup = $pdo->prepare(
            'SELECT id FROM ' . quoteIdent(tableName('config_tipos')) .
            ' WHERE ' . quoteIdent('categoria') . ' = ? AND LOWER(' . quoteIdent('nome') . ') = LOWER(?) AND ' . quoteIdent('id') . ' <> ?'
        );
        $dup->execute([(string) $atual['categoria'], $nome, $id]);
        if ($dup->fetch()) {
            responderErro(400, 'Esse valor ja existe nessa lista.');
        }

        $upd = $pdo->prepare('UPDATE ' . quoteIdent(tableName('config_tipos')) . ' SET ' . quoteIdent('nome') . ' = ? WHERE ' . quoteIdent('id') . ' = ?');
        $upd->execute([$nome, $id]);
        registrarAuditoria(
            'tipos',
            $id,
            'atualizar',
            (string) $admin['username'],
            ['categoria' => (string) $atual['categoria'], 'nome' => (string) $atual['nome']],
            ['categoria' => (string) $atual['categoria'], 'nome' => $nome]
        );
        responderJson(['id' => $id, 'categoria' => (string) $atual['categoria'], 'nome' => $nome]);
    }

    if (preg_match('#^/tipos/(\d+)$#', $caminho, $m) && $metodo === 'DELETE') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $id = (int) $m[1];

        $pdo = db();
        $antesStmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('config_tipos')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $antesStmt->execute([$id]);
        /** @var array<string, mixed>|false $antes */
        $antes = $antesStmt->fetch();

        $stmt = $pdo->prepare('DELETE FROM ' . quoteIdent(tableName('config_tipos')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            responderErro(404, 'Item nao encontrado.');
        }
        registrarAuditoria('tipos', $id, 'excluir', (string) $admin['username'], $antes !== false ? $antes : null, null);
        responderVazio(204);
    }

    // ---- /auditoria (trilha de auditoria, so MASTER) ---------------------
    // Lista quem fez o que, quando e de qual IP -- CRUD dos modulos de
    // dados e eventos de autenticacao (ver registrarAuditoria() em
    // backend/auditoria.php). Filtros e paginacao via querystring.
    // Atencao: exigirMaster() aqui, NAO exigirAdmin() -- administrador comum
    // nao ve a trilha de auditoria, so o usuario master.
    if ($metodo === 'GET' && $caminho === '/auditoria') {
        $admin = exigirLogin();
        exigirMaster($admin);
        $filtros = [
            'usuario' => strGet('usuario'),
            'tabela' => strGet('tabela'),
            'acao' => strGet('acao'),
            'inicio' => strGet('inicio'),
            'fim' => strGet('fim'),
        ];
        $pagina = max(1, intGet('pagina', 1));
        $porPagina = intGet('por_pagina', 50);
        responderJson(listarAuditoria($filtros, $pagina, $porPagina));
    }


    // ---- /seguranca/stats (tentativas de login recusadas, admin+master) -----
    // Retorna top-10 de login_falha agrupado por usuario e por IP no periodo
    // informado via ?dias=N (padrao 30). Acessivel a admin e master -- nivel
    // abaixo da trilha completa de auditoria (que exige master).
    if ($metodo === 'GET' && $caminho === '/seguranca/stats') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $dias   = max(7, min(365, intGet('dias', 30)));
        $inicio = date('Y-m-d H:i:s', (int) strtotime("-{$dias} days"));
        $pdo    = db();
        $tab    = quoteIdent(tableName('auditoria_log'));
        $cAcao  = quoteIdent('acao');
        $cUsr   = quoteIdent('usuario');
        $cIp    = quoteIdent('ip');
        $cData  = quoteIdent('criado_em');

        $limit = dbDriver() === 'sqlsrv'
            ? ' OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY'
            : ' LIMIT 10';

        $tabUsr  = quoteIdent(tableName('usuarios'));
        $cUsrId  = quoteIdent('id');
        $cAtivo  = quoteIdent('ativo');
        $uName   = quoteIdent('username');
        // LEFT JOIN para trazer usuario_id e status ativo (conta pode ter
        // sido excluida -- nesse caso usuario_id vira null).
        $sqlUsr = "SELECT a.{$cUsr} AS usuario, COUNT(*) AS total,"
            . " u.{$cUsrId} AS usuario_id, COALESCE(u.{$cAtivo}, 1) AS ativo"
            . " FROM {$tab} a LEFT JOIN {$tabUsr} u ON u.{$uName} = a.{$cUsr}"
            . " WHERE a.{$cAcao} = ? AND a.{$cData} >= ?"
            . " GROUP BY a.{$cUsr}, u.{$cUsrId}, u.{$cAtivo} ORDER BY total DESC{$limit}";
        $stU = $pdo->prepare($sqlUsr);
        $stU->execute(['login_falha', $inicio]);
        $porUsuario = $stU->fetchAll(PDO::FETCH_ASSOC);

        $sqlIp = "SELECT {$cIp} AS ip, COUNT(*) AS total FROM {$tab}"
            . " WHERE {$cAcao} = ? AND {$cData} >= ?"
            . " GROUP BY {$cIp} ORDER BY total DESC{$limit}";
        $stI = $pdo->prepare($sqlIp);
        $stI->execute(['login_falha', $inicio]);
        $porIp = $porIp = $stI->fetchAll(PDO::FETCH_ASSOC);

        $stT = $pdo->prepare("SELECT COUNT(*) FROM {$tab} WHERE {$cAcao} = ? AND {$cData} >= ?");
        $stT->execute(['login_falha', $inicio]);
        $total = (int) $stT->fetchColumn();

        responderJson(['por_usuario' => $porUsuario, 'por_ip' => $porIp, 'total' => $total, 'dias' => $dias]);
    }

    // ---- /usuarios/{id}/ativo (ativar/desativar conta, admin) ---------------
    // Admin pode desativar qualquer conta exceto a propria e a do master
    // (somente outro master pode desativar um master). Registra auditoria.
    if (preg_match('#^/usuarios/(\d+)/ativo$#', $caminho, $m) && $metodo === 'PATCH') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $id   = (int) $m[1];
        $body = corpoRequisicao();

        $pdo  = db();
        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $alvo */
        $alvo = $stmt->fetch();
        if ($alvo === false) {
            responderErro(404, 'Usuario nao encontrado.');
        }

        if ((int) $admin['id'] === $id) {
            responderErro(400, 'Nao e possivel desativar sua propria conta.');
        }

        if ((string) ($alvo['role'] ?? '') === 'master' && (string) ($admin['role'] ?? '') !== 'master') {
            responderErro(403, 'Apenas o usuario master pode desativar outro master.');
        }

        // Se ?ativo=1/0 foi enviado usa esse valor; senao inverte o estado atual.
        $novoAtivo = isset($body['ativo'])
            ? ((bool) $body['ativo'] ? 1 : 0)
            : (((int) ($alvo['ativo'] ?? 1)) === 1 ? 0 : 1);

        $pdo->prepare(
            'UPDATE ' . quoteIdent(tableName('usuarios'))
            . ' SET ' . quoteIdent('ativo') . ' = ?, ' . quoteIdent('atualizado_em') . ' = ?'
            . ' WHERE ' . quoteIdent('id') . ' = ?'
        )->execute([$novoAtivo, date('Y-m-d H:i:s'), $id]);

        $acao = $novoAtivo === 1 ? 'usuario_ativado' : 'usuario_desativado';
        registrarAuditoria('usuarios', $id, $acao, (string) $admin['username'],
            ['ativo' => (int) ($alvo['ativo'] ?? 1)], ['ativo' => $novoAtivo]);

        $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent(tableName('usuarios')) . ' WHERE ' . quoteIdent('id') . ' = ?');
        $stmt->execute([$id]);
        /** @var array<string, mixed>|false $atualizado */
        $atualizado = $stmt->fetch();
        responderJson(semSenha($atualizado !== false ? $atualizado : []));
    }


    // ---- /config/email (servidor SMTP, so admin) ------------------------
    if ($metodo === 'GET' && $caminho === '/config/email') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        responderJson(configEmailSemSenha());
    }

    if ($metodo === 'PUT' && $caminho === '/config/email') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $body = corpoRequisicao();
        if (trim((string) ($body['host'] ?? '')) === '' || trim((string) ($body['remetente_email'] ?? '')) === '') {
            responderErro(422, 'Informe pelo menos o servidor (host) e o e-mail do remetente.');
        }
        if (!filter_var(trim((string) $body['remetente_email']), FILTER_VALIDATE_EMAIL)) {
            responderErro(422, 'E-mail do remetente invalido.');
        }
        responderJson(salvarConfigEmail($body));
    }

    if ($metodo === 'POST' && $caminho === '/config/email/testar') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $body = corpoRequisicao();
        $para = trim((string) ($body['para'] ?? ''));
        if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
            responderErro(422, 'Informe um e-mail de destino valido para o teste.');
        }
        $cfgEmail = configEmail();
        if ($cfgEmail === null) {
            responderErro(400, 'Salve as configuracoes de e-mail antes de testar.');
        }
        try {
            smtpEnviar(
                $cfgEmail,
                $para,
                'Teste de e-mail - ' . projectTitle(),
                'Este e um e-mail de teste enviado pelo portal ' . projectTitle() . '. ' .
                'Se voce recebeu esta mensagem, a configuracao de SMTP esta funcionando corretamente.'
            );
        } catch (Throwable $e) {
            responderErro(400, $e->getMessage());
        }
        // So a partir de um envio real confirmado o servico de e-mail e
        // considerado "pronto" -- e o que libera a ativacao do MFA (ver
        // checagem em PUT /auth/mfa abaixo).
        marcarEmailTestadoOk();
        responderJson(['ok' => true]);
    }

    // Status minimo do e-mail (pronto ou nao) pra QUALQUER usuario logado --
    // diferente de GET /config/email (so admin), esta rota nao expoe
    // host/usuario/credenciais, so um booleano. E o que a tela de Perfil usa
    // pra avisar/bloquear a ativacao do MFA antes mesmo de tentar.
    if ($metodo === 'GET' && $caminho === '/auth/mfa/email-status') {
        exigirLogin();
        responderJson(['pronto' => emailProntoParaUso()]);
    }

    // ---- /config/projeto (nome + logo do portal, so admin) --------------
    // GET fica liberado pra qualquer usuario logado (a tela de Configuracoes
    // do projeto so aparece no menu pra admin, mas o nome/logo tambem
    // precisam ser lidos por usuarios comuns -- ex.: pra atualizar o titulo
    // da aba sem precisar recarregar a pagina). Quem GRAVA continua restrito
    // a admin.
    if ($metodo === 'GET' && $caminho === '/config/projeto') {
        exigirLogin();
        $cfgProjeto = configProjeto();
        responderJson([
            'nome_projeto' => trim((string) ($cfgProjeto['nome_projeto'] ?? '')),
            'logo_data' => trim((string) ($cfgProjeto['logo_data'] ?? '')),
            'titulo_efetivo' => projectTitle(),
            'timeout_inatividade_min' => (int) ($cfgProjeto['timeout_inatividade_min'] ?? 30),
        ]);
    }

    if ($metodo === 'PUT' && $caminho === '/config/projeto') {
        $admin = exigirLogin();
        exigirAdmin($admin);
        $body = corpoRequisicao();

        if (array_key_exists('timeout_inatividade_min', $body)) {
            $timeoutInatividade = (int) $body['timeout_inatividade_min'];
            if ($timeoutInatividade < 5 || $timeoutInatividade > 480) {
                responderErro(422, 'O timeout de inatividade precisa estar entre 5 e 480 minutos.');
            }
        }

        if (array_key_exists('logo_data', $body) && trim((string) $body['logo_data']) !== '') {
            $logoData = trim((string) $body['logo_data']);
            // Aceita so imagens (data URI "data:image/..."), e limita o
            // tamanho pra nao deixar alguem entupir a coluna do banco (e a
            // pagina de login, que carrega isso a cada acesso) com um
            // arquivo gigante. ~2MB em base64 e bem mais que suficiente pra
            // um logo.
            // SVG pode conter <script> e ser usado como vetor de XSS --
            // restringir a formatos rasterizados sem capacidade de script.
            $tiposPermitidos = ['data:image/png;', 'data:image/jpeg;', 'data:image/webp;', 'data:image/gif;'];
            $tipoOk = false;
            foreach ($tiposPermitidos as $prefixoMime) {
                if (str_starts_with($logoData, $prefixoMime)) {
                    $tipoOk = true;
                    break;
                }
            }
            if (!$tipoOk) {
                responderErro(422, 'A logo precisa ser PNG, JPEG, WEBP ou GIF (SVG nao e aceito por seguranca).');
            }
            if (strlen($logoData) > 2_800_000) {
                responderErro(422, 'Logo muito grande. Envie uma imagem com no maximo ~2MB.');
            }
            // Verificacao adicional: o binario decodificado deve ser uma
            // imagem valida (evita que um arquivo disfarced de imagem seja aceito).
            $binario = base64_decode(substr($logoData, (int) strpos($logoData, ',') + 1), true);
            if ($binario === false || @getimagesizefromstring($binario) === false) {
                responderErro(422, 'O arquivo enviado nao e uma imagem valida.');
            }
        }

        responderJson(salvarConfigProjeto($body));
    }

    // ---- /relatorios/{chave}/email (envia o CSV do modulo por e-mail) ---
    if (preg_match('#^/relatorios/([a-z]+)/email$#', $caminho, $m) && $metodo === 'POST') {
        $prefixo = $m[1];
        if (!isset($MODULOS[$prefixo])) {
            responderErro(404, 'Relatorio nao encontrado.');
        }
        $user = exigirLogin();
        exigirAcessoModulo($user, $prefixo, false);

        $body = corpoRequisicao();
        $para = trim((string) ($body['para'] ?? ''));
        if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
            responderErro(422, 'Informe um e-mail de destino valido.');
        }

        $cfgEmail = configEmail();
        if ($cfgEmail === null) {
            responderErro(400, 'Configure o servidor de e-mail em Administracao > E-mail antes de enviar.');
        }

        /** @var list<string> $colunas */
        $colunas = $MODULOS[$prefixo]['colunas'];
        /** @var array<string, string> $rotulos */
        $rotulos = $COLUNA_LABELS[$prefixo] ?? [];
        $inicio = trim((string) ($body['inicio'] ?? ''));
        $fim = trim((string) ($body['fim'] ?? ''));

        // Nota: $MODULOS[$prefixo]['tabela'] ja vem com o prefixo aplicado
        // (tableName() foi chamado na montagem de $MODULOS). todosRegistros()
        // espera o nome LOGICO e aplica tableName() de novo -- por isso a
        // consulta e feita direto aqui, sem passar pela funcao, pra nao
        // prefixar duas vezes (ex.: "gdt_gdt_restore_testes").
        $pdoRel = db();
        $sqlRel = 'SELECT * FROM ' . quoteIdent($MODULOS[$prefixo]['tabela']);
        $argsRel = [];
        $condRel = [];
        if ($inicio !== '') {
            $condRel[] = quoteIdent('criado_em') . ' >= ?';
            $argsRel[] = "{$inicio} 00:00:00";
        }
        if ($fim !== '') {
            $condRel[] = quoteIdent('criado_em') . ' <= ?';
            $argsRel[] = "{$fim} 23:59:59";
        }
        if (count($condRel) > 0) {
            $sqlRel .= ' WHERE ' . implode(' AND ', $condRel);
        }
        $stmtRel = $pdoRel->prepare($sqlRel);
        $stmtRel->execute($argsRel);
        $linhas = $stmtRel->fetchAll();

        $cabecalho = implode(';', array_map(fn ($c) => $rotulos[$c] ?? $c, $colunas));
        $corpoCsv = [$cabecalho];
        foreach ($linhas as $linha) {
            $campos = array_map(function ($c) use ($linha) {
                $v = (string) ($linha[$c] ?? '');
                // Campos-lista guardados como JSON (ex.: jobs.comando = passos,
                // jobs.agendamentos) saem legiveis no relatorio, nao como JSON cru.
                if ($v !== '' && $v[0] === '[') {
                    $arr = json_decode($v, true);
                    if (is_array($arr)) {
                        $partes = [];
                        foreach ($arr as $it) {
                            if (!is_array($it)) { continue; }
                            if (array_key_exists('nome', $it) || array_key_exists('comando', $it)) {
                                $nm = trim((string) ($it['nome'] ?? ''));
                                $cmd = trim((string) ($it['comando'] ?? ''));
                                $partes[] = trim($nm . ($cmd !== '' ? ': ' . $cmd : ''));
                            } elseif (array_key_exists('inicio', $it) || array_key_exists('fim', $it)) {
                                $ini = trim((string) ($it['inicio'] ?? ''));
                                $f = trim((string) ($it['fim'] ?? ''));
                                $partes[] = $ini . ($f !== '' ? '-' . $f : '');
                            }
                        }
                        $partes = array_values(array_filter($partes, static fn ($p) => $p !== ''));
                        if (count($partes) > 0) { $v = implode(' | ', $partes); }
                    }
                }
                // Neutraliza CSV injection: = + - @ no inicio da celula
                if (preg_match('/^[=+\-@\t\r]/', $v)) {
                    $v = "'" . $v;
                }
                $v = str_replace('"', '""', $v);
                return preg_match('/[;"\n]/', $v) ? '"' . $v . '"' : $v;
            }, $colunas);
            $corpoCsv[] = implode(';', $campos);
        }
        $csv = "\xEF\xBB\xBF" . implode("\r\n", $corpoCsv);

        try {
            smtpEnviar(
                $cfgEmail,
                $para,
                'Relatorio - ' . ($MODULOS_TITULOS[$prefixo] ?? $prefixo),
                'Relatorio em anexo, gerado pelo portal ' . projectTitle() . ' em ' . date('d/m/Y H:i') . '.',
                ['nome' => $prefixo . '.csv', 'tipo' => 'text/csv', 'conteudo' => $csv]
            );
        } catch (Throwable $e) {
            responderErro(400, $e->getMessage());
        }
        responderJson(['ok' => true]);
    }

    // ---- /dashboard ----------------------------------------------------
    if ($metodo === 'GET' && $caminho === '/dashboard/info') {
        // Login exigido: expor motor e versao sem autenticacao permite
        // fingerprinting da infraestrutura. A tela de login nao precisa
        // dessa informacao; o front pode exibi-la so apos o login.
        exigirLogin();
        responderJson(['motor_banco' => dbEngineName(), 'versao' => '1.0.0']);
    }

    if ($metodo === 'GET' && $caminho === '/dashboard/stats') {
        $user = exigirLogin();
        responderJson(dashboardStats($user));
    }

    if ($metodo === 'GET' && $caminho === '/dashboard/export') {
        $user = exigirLogin();
        responderJson(dashboardExport($user));
    }

    // Manual de uso em PDF -- servido via API (autenticado por token) em vez
    // de arquivo estatico, pra nao depender de configuracao do servidor web
    // pra extensao .pdf dentro de docs/.
    if ($metodo === 'GET' && $caminho === '/documentacao') {
        exigirLogin();
        $caminhoPdf = __DIR__ . '/../docs/manual_uso_portal.pdf';
        if (!is_file($caminhoPdf)) {
            responderErro(404, 'Documentação não encontrada.');
        }
        // attachment, nao inline -- o front faz download direto do blob em vez
        // de exibir num iframe (exibicao inline dependia de CSP frame-ancestors
        // herdado, que travava em alguns navegadores/ambientes).
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="manual_uso_portal.pdf"');
        header('Content-Length: ' . (string) filesize($caminhoPdf));
        readfile($caminhoPdf);
        exit;
    }

    if ($metodo === 'GET' && $caminho === '/documentacao/diagrama') {
        exigirLogin();
        $caminhoPdf = __DIR__ . '/../docs/diagrama_dicionario_banco.pdf';
        if (!is_file($caminhoPdf)) {
            responderErro(404, 'Diagrama do banco não encontrado.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="diagrama_dicionario_banco.pdf"');
        header('Content-Length: ' . (string) filesize($caminhoPdf));
        readfile($caminhoPdf);
        exit;
    }

    // Manual da API em PDF -- mesma logica das duas rotas acima.
    if ($metodo === 'GET' && $caminho === '/documentacao/api') {
        exigirLogin();
        $caminhoPdf = __DIR__ . '/../docs/manual_api_portal.pdf';
        if (!is_file($caminhoPdf)) {
            responderErro(404, 'Manual da API não encontrado.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="manual_api_portal.pdf"');
        header('Content-Length: ' . (string) filesize($caminhoPdf));
        readfile($caminhoPdf);
        exit;
    }

    // ---- English documentation routes -----------------------------------
    if ($metodo === 'GET' && $caminho === '/documentacao/manual-en') {
        exigirLogin();
        $caminhoPdf = __DIR__ . '/../docs/manual_uso_portal_en.pdf';
        if (!is_file($caminhoPdf)) {
            responderErro(404, 'English user manual not found.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="manual_uso_portal_en.pdf"');
        header('Content-Length: ' . (string) filesize($caminhoPdf));
        readfile($caminhoPdf);
        exit;
    }

    if ($metodo === 'GET' && $caminho === '/documentacao/diagrama-en') {
        exigirLogin();
        $caminhoPdf = __DIR__ . '/../docs/diagrama_dicionario_banco_en.pdf';
        if (!is_file($caminhoPdf)) {
            responderErro(404, 'English data dictionary not found.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="diagrama_dicionario_banco_en.pdf"');
        header('Content-Length: ' . (string) filesize($caminhoPdf));
        readfile($caminhoPdf);
        exit;
    }

    if ($metodo === 'GET' && $caminho === '/documentacao/api-en') {
        exigirLogin();
        $caminhoPdf = __DIR__ . '/../docs/manual_api_portal_en.pdf';
        if (!is_file($caminhoPdf)) {
            responderErro(404, 'English API reference not found.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="manual_api_portal_en.pdf"');
        header('Content-Length: ' . (string) filesize($caminhoPdf));
        readfile($caminhoPdf);
        exit;
    }

    // ---- /sistema/info (driver, versao e tamanho do banco) ---------------
    // Acessivel a qualquer usuario autenticado -- nao e dado sensivel, e
    // util pra exibir no rodape da sidebar como referencia rapida.
    if ($metodo === 'GET' && $caminho === '/sistema/info') {
        exigirLogin();
        $pdo    = db();
        $driver = dbDriver();

        // Versao do banco -- cada motor tem sua propria funcao/variavel.
        try {
            switch ($driver) {
                case 'sqlite':
                    $stV = $pdo->query('SELECT sqlite_version()');
                    $versao = $stV !== false ? (string) $stV->fetchColumn() : null;
                    break;
                case 'mysql':
                    $stV = $pdo->query('SELECT VERSION()');
                    $versao = $stV !== false ? (string) $stV->fetchColumn() : null;
                    // MySQL devolve "8.0.32-..." -- trunca no primeiro traco
                    if ($versao !== null) { $versao = explode('-', $versao)[0]; }
                    break;
                case 'pgsql':
                    $stV = $pdo->query("SELECT current_setting('server_version')");
                    $versao = $stV !== false ? (string) $stV->fetchColumn() : null;
                    if ($versao !== null) { $versao = explode(' ', $versao)[0]; }
                    break;
                case 'sqlsrv':
                    $stV = $pdo->query("SELECT SERVERPROPERTY('ProductVersion')");
                    $versao = $stV !== false ? (string) $stV->fetchColumn() : null;
                    break;
                default:
                    $versao = null;
            }
        } catch (Throwable $e) {
            $versao = null;
        }

        // Tamanho do banco em bytes -- best effort (pode nao estar disponivel
        // em alguns ambientes por falta de permissao na information_schema).
        $tamanhoBytes = null;
        try {
            switch ($driver) {
                case 'sqlite':
                    // page_count * page_size e o tamanho real do arquivo SQLite.
                    $stPc = $pdo->query('PRAGMA page_count');
                    $stPs = $pdo->query('PRAGMA page_size');
                    if ($stPc !== false && $stPs !== false) {
                        $tamanhoBytes = (int) $stPc->fetchColumn() * (int) $stPs->fetchColumn();
                    }
                    break;
                case 'mysql':
                    $sql = 'SELECT COALESCE(SUM(data_length + index_length), 0)
                            FROM information_schema.tables
                            WHERE table_schema = DATABASE()';
                    $stSz = $pdo->query($sql);
                    $tamanhoBytes = $stSz !== false ? (int) $stSz->fetchColumn() : null;
                    break;
                case 'pgsql':
                    $stSz = $pdo->query('SELECT pg_database_size(current_database())');
                    $tamanhoBytes = $stSz !== false ? (int) $stSz->fetchColumn() : null;
                    break;
                case 'sqlsrv':
                    $sql = "SELECT SUM(CAST(size AS BIGINT) * 8 * 1024)
                            FROM sys.database_files WHERE type_desc = 'ROWS'";
                    $stSz = $pdo->query($sql);
                    $tamanhoBytes = $stSz !== false ? (int) $stSz->fetchColumn() : null;
                    break;
            }
        } catch (Throwable $e) {
            $tamanhoBytes = null;
        }

        responderJson([
            'driver'         => $driver,
            'versao'         => $versao,
            'tamanho_bytes'  => $tamanhoBytes,
        ]);
    }


    // ---- /mudancas (rotas especificas -- substitui o CRUD generico) ------
    // Precisa vir ANTES do foreach generico, pois responderJson/Vazio chamam
    // exit e encerram a requisicao sem chegar no loop.
    if ($metodo !== 'OPTIONS' && str_starts_with($caminho, '/mudancas')) {
        $user    = exigirLogin();
        $pdo     = db();
        /** @var array{tabela: string, colunas: list<string>, busca: list<string>, ordem: string} $modMud */
        $modMud  = $MODULOS['mudancas'];
        $tabObj  = quoteIdent(tableName('mudancas_objetos'));

        // GET /mudancas
        if ($caminho === '/mudancas' && $metodo === 'GET') {
            exigirAcessoModulo($user, 'mudancas', false);
            $q      = trim(strGet('q'));
            $inicio = trim(strGet('inicio'));
            $fim    = trim(strGet('fim'));
            $itens  = crudListar($modMud['tabela'], $modMud['busca'], $modMud['ordem'], $q, $inicio, $fim);
            if ($itens) {
                /** @var list<array<string, mixed>> $itens */
                $ids   = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $itens);
                $marc  = implode(', ', array_fill(0, count($ids), '?'));
                $stmt  = $pdo->prepare(
                    "SELECT id, mudanca_id, nome, tipo FROM {$tabObj} WHERE mudanca_id IN ({$marc}) ORDER BY mudanca_id, id"
                );
                $stmt->execute($ids);
                /** @var array<int, list<array<string, mixed>>> $porMud */
                $porMud = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $obj) {
                    $porMud[(int) $obj['mudanca_id']][] = $obj;
                }
                foreach ($itens as &$item) {
                    $item['objetos'] = $porMud[(int) $item['id']] ?? [];
                }
                unset($item);
            }
            responderJson($itens);
        }

        // GET /mudancas/exportar  (download CSV desnormalizado)
        if ($caminho === '/mudancas/exportar' && $metodo === 'GET') {
            exigirAcessoModulo($user, 'mudancas', false);
            $tabMud = quoteIdent(tableName('mudancas'));
            $sql    = "SELECT m.codigo, m.data, m.ambiente, m.tipo, m.descricao,
                              o.nome AS objeto_nome, o.tipo AS objeto_tipo,
                              m.solicitante, m.aprovador, m.status, m.resultado
                       FROM {$tabMud} m
                       LEFT JOIN {$tabObj} o ON o.mudanca_id = m.id
                       ORDER BY m.data DESC, m.id, o.id";
            $stExp = $pdo->query($sql);
            /** @var list<array<string, mixed>> $rowsExp */
            $rowsExp = $stExp !== false ? $stExp->fetchAll(PDO::FETCH_ASSOC) : [];

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="mudancas_' . date('Ymd_His') . '.csv"');
            header('Cache-Control: no-store');
            // BOM UTF-8 para compatibilidade com Excel
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($out !== false) {
                fputcsv($out, [
                    'Chamado', 'Data', 'Ambiente', 'Tipo', 'Descricao',
                    'Objeto', 'Tipo_Objeto', 'Solicitante', 'Aprovador', 'Status', 'Resultado',
                ]);
                foreach ($rowsExp as $row) {
                    // Neutraliza CSV injection: celulas que iniciam com = + - @
                // recebem apostrofo prefixado para impedir execucao no Excel/Sheets.
                $csvSanitize = static function (string $v): string {
                    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
                };
                fputcsv($out, [
                        $csvSanitize((string) ($row['codigo']      ?? '')),
                        $csvSanitize((string) ($row['data']         ?? '')),
                        $csvSanitize((string) ($row['ambiente']     ?? '')),
                        $csvSanitize((string) ($row['tipo']         ?? '')),
                        $csvSanitize((string) ($row['descricao']    ?? '')),
                        $csvSanitize((string) ($row['objeto_nome']  ?? '')),
                        $csvSanitize((string) ($row['objeto_tipo']  ?? '')),
                        $csvSanitize((string) ($row['solicitante']  ?? '')),
                        $csvSanitize((string) ($row['aprovador']    ?? '')),
                        $csvSanitize((string) ($row['status']       ?? '')),
                        $csvSanitize((string) ($row['resultado']    ?? '')),
                    ]);
                }
                fclose($out);
            }
            exit;
        }

        // POST /mudancas
        if ($caminho === '/mudancas' && $metodo === 'POST') {
            exigirAcessoModulo($user, 'mudancas', true);
            $body    = corpoRequisicao();
            $objetos = is_array($body['objetos'] ?? null) ? $body['objetos'] : [];
            $body['criado_por'] = (string) $user['username'];
            $item    = crudCriar($modMud['tabela'], $modMud['colunas'], $body);
            sincronizarObjMudanca($pdo, (int) $item['id'], $objetos);
            $item['objetos'] = obterObjMudanca($pdo, (int) $item['id']);
            registrarAuditoria('mudancas', (int) $item['id'], 'criar', (string) $user['username'], null, $item);
            responderJson($item, 201);
        }

        // DELETE /mudancas/lote
        if ($caminho === '/mudancas/lote' && $metodo === 'DELETE') {
            exigirAcessoModulo($user, 'mudancas', true);
            exigirAdmin($user);
            $body    = corpoRequisicao();
            $tamanho = max(1, min(5000, (int) ($body['tamanho'] ?? 5000)));
            /** @var list<int>|null $ids */
            $ids = null;
            if (is_array($body['ids'] ?? null)) {
                $ids = array_values(array_unique(array_filter(
                    array_map(static fn (mixed $v): int => (int) $v, $body['ids']),
                    static fn (int $v): bool => $v > 0
                )));
            }
            // Apagar objetos antes das mudancas para nao violar FK (se existir)
            $tabMud = quoteIdent(tableName('mudancas'));
            if ($ids !== null) {
                if ($ids) {
                    $marc = implode(', ', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM {$tabObj} WHERE mudanca_id IN ({$marc})")->execute($ids);
                }
            } else {
                // Sem IDs: descobre quais serao removidos e apaga seus objetos primeiro
                if (dbDriver() === 'sqlsrv') {
                    $selSql = "SELECT TOP ({$tamanho}) id FROM {$tabMud}";
                } else {
                    $selSql = "SELECT id FROM {$tabMud} LIMIT {$tamanho}";
                }
                $stIds = $pdo->query($selSql);
                if ($stIds !== false) {
                    $idsLote = $stIds->fetchAll(PDO::FETCH_COLUMN);
                    if ($idsLote) {
                        $marc = implode(', ', array_fill(0, count($idsLote), '?'));
                        $pdo->prepare("DELETE FROM {$tabObj} WHERE mudanca_id IN ({$marc})")->execute($idsLote);
                    }
                }
            }
            $apagados = crudExcluirLote($modMud['tabela'], $tamanho, $ids);
            if ($apagados > 0) {
                registrarAuditoria('mudancas', null, 'excluir_lote', (string) $user['username'], null, ['apagados' => $apagados]);
            }
            responderJson(['apagados' => $apagados]);
        }

        // GET /mudancas/{id}  |  PUT /mudancas/{id}  |  DELETE /mudancas/{id}
        if (preg_match('#^/mudancas/(\d+)$#', $caminho, $m)) {
            $id = (int) $m[1];

            if ($metodo === 'GET') {
                exigirAcessoModulo($user, 'mudancas', false);
                $item = crudObter($modMud['tabela'], $id);
                if ($item === null) {
                    responderErro(404, 'Registro nao encontrado.');
                }
                $item['objetos'] = obterObjMudanca($pdo, $id);
                responderJson($item);
            }

            if ($metodo === 'PUT') {
                exigirAcessoModulo($user, 'mudancas', true);
                $body    = corpoRequisicao();
                $objetos = is_array($body['objetos'] ?? null) ? $body['objetos'] : [];
                unset($body['criado_por']);
                $antes   = crudObter($modMud['tabela'], $id);
                $item    = crudAtualizar($modMud['tabela'], $modMud['colunas'], $id, $body);
                if ($item === null) {
                    responderErro(404, 'Registro nao encontrado.');
                }
                sincronizarObjMudanca($pdo, $id, $objetos);
                $item['objetos'] = obterObjMudanca($pdo, $id);
                registrarAuditoria('mudancas', $id, 'atualizar', (string) $user['username'], $antes, $item);
                responderJson($item);
            }

            if ($metodo === 'DELETE') {
                exigirAcessoModulo($user, 'mudancas', true);
                $antes = crudObter($modMud['tabela'], $id);
                $pdo->prepare("DELETE FROM {$tabObj} WHERE mudanca_id = ?")->execute([$id]);
                if (!crudExcluir($modMud['tabela'], $id)) {
                    responderErro(404, 'Registro nao encontrado.');
                }
                registrarAuditoria('mudancas', $id, 'excluir', (string) $user['username'], $antes, null);
                responderVazio(204);
            }
        }
    }


    // ---- Modulos de dados (CRUD generico) -------------------------------
    foreach ($MODULOS as $prefixo => $modulo) {
        /** @var array{tabela: string, colunas: list<string>, busca: list<string>, ordem: string} $modulo */
        $base = "/{$prefixo}";

        if ($caminho === $base && $metodo === 'GET') {
            $user = exigirLogin();
            exigirAcessoModulo($user, $prefixo, false);
            $q = trim(strGet('q'));
            $inicio = trim(strGet('inicio'));
            $fim = trim(strGet('fim'));
            responderJson(crudListar($modulo['tabela'], $modulo['busca'], $modulo['ordem'], $q, $inicio, $fim));
        }

        if ($caminho === $base && $metodo === 'POST') {
            $user = exigirLogin();
            exigirAcessoModulo($user, $prefixo, true);
            $body = corpoRequisicao();
            if ($prefixo === 'dicionario') {
                dicionarioVerificarDuplicado($modulo['tabela'], $body);
            }
            // Registra quem criou -- definido pelo servidor, nunca pelo cliente.
            $body['criado_por'] = (string) $user['username'];
            $item = crudCriar($modulo['tabela'], $modulo['colunas'], $body);
            registrarAuditoria($prefixo, (int) $item['id'], 'criar', (string) $user['username'], null, $item);
            responderJson($item, 201);
        }

        if ($caminho === "{$base}/lote" && $metodo === 'DELETE') {
            $user = exigirLogin();
            exigirAcessoModulo($user, $prefixo, true);
            // Exclusao em massa e bem mais perigosa que excluir um
            // registro por vez -- por isso tem trava propria, alem da
            // permissao normal de escrita no modulo. No dicionario (onde
            // isso costuma envolver milhares de linhas vindas de
            // importacao) so o usuario master pode; nos demais modulos,
            // admin ou master.
            if ($prefixo === 'dicionario') {
                exigirMaster($user);
            } else {
                exigirAdmin($user);
            }
            $body = corpoRequisicao();
            $tamanho = max(1, min(5000, (int) ($body['tamanho'] ?? 5000)));
            $ids = null;
            if (is_array($body['ids'] ?? null)) {
                $ids = array_values(array_unique(array_filter(
                    array_map(static fn (mixed $v): int => (int) $v, $body['ids']),
                    static fn (int $v): bool => $v > 0
                )));
            }
            $apagados = crudExcluirLote($modulo['tabela'], $tamanho, $ids);
            if ($apagados > 0) {
                registrarAuditoria($prefixo, null, 'excluir_lote', (string) $user['username'], null, ['apagados' => $apagados]);
            }
            responderJson(['apagados' => $apagados]);
        }

        if (preg_match("#^{$base}/(\d+)$#", $caminho, $m)) {
            $id = (int) $m[1];

            if ($metodo === 'GET') {
                $user = exigirLogin();
                exigirAcessoModulo($user, $prefixo, false);
                $item = crudObter($modulo['tabela'], $id);
                if ($item === null) {
                    responderErro(404, 'Registro nao encontrado.');
                }
                responderJson($item);
            }

            if ($metodo === 'PUT') {
                $user = exigirLogin();
                exigirAcessoModulo($user, $prefixo, true);
                $body = corpoRequisicao();
                $antes = crudObter($modulo['tabela'], $id);
                if ($prefixo === 'dicionario' && $antes !== null) {
                    dicionarioVerificarDuplicado($modulo['tabela'], $body, $antes, $id);
                }
                // criado_por e imutavel: remove do corpo para nao sobrescrever.
                unset($body['criado_por']);
                $item = crudAtualizar($modulo['tabela'], $modulo['colunas'], $id, $body);
                if ($item === null) {
                    responderErro(404, 'Registro nao encontrado.');
                }
                registrarAuditoria($prefixo, $id, 'atualizar', (string) $user['username'], $antes, $item);
                responderJson($item);
            }

            if ($metodo === 'DELETE') {
                $user = exigirLogin();
                exigirAcessoModulo($user, $prefixo, true);
                $antes = crudObter($modulo['tabela'], $id);
                if (!crudExcluir($modulo['tabela'], $id)) {
                    responderErro(404, 'Registro nao encontrado.');
                }
                registrarAuditoria($prefixo, $id, 'excluir', (string) $user['username'], $antes, null);
                responderVazio(204);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Bootstrap da requisicao
// ---------------------------------------------------------------------------

migrate();

$cfg = config();

// Issue: CORS "*" em producao e inseguro. Se app_debug === false e a origem
// ainda for "*", recusamos com 500 para forcar a configuracao correta.
$corsOrigin  = (string) ($cfg['cors_allowed_origin'] ?? '*');
$appDebugCfg = ($cfg['app_debug'] ?? false) === true;
if (!$appDebugCfg && $corsOrigin === '*') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['detail' => 'Configuracao insegura: defina cors_allowed_origin com o dominio real do portal em conf/config.php antes de usar em producao.']);
    exit;
}
header('Access-Control-Allow-Origin: ' . $corsOrigin);
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
// Allow-Credentials nao deve ser enviado quando a origem for "*"
// (navegador rejeita a combinacao; e aqui nao chegamos com "*" em producao).
if ($corsOrigin !== '*') {
    header('Access-Control-Allow-Credentials: true');
}

// Headers de seguranca proprios da API -- ela so devolve JSON, nunca HTML,
// entao pode ser bem mais restritiva que o restante do site (ver
// enviarCabecalhosSeguranca() em index.php, que cobre as paginas HTML).
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store'); // respostas tem token/dados sensiveis -- nunca cachear
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// $uriPath, se existir, ja vem de index.php com a subpasta de instalacao
// removida (ver caminhoBaseApp()) -- reaproveita em vez de recalcular do
// zero, senao instalacao em subpasta quebra o roteamento da API (o "/api"
// nao comeca mais no caractere 0 de REQUEST_URI nesse caso).
$uri = $uriPath ?? (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$caminho = rtrim(substr($uri, 4), '/'); // remove o prefixo "/api"
if ($caminho === '') {
    $caminho = '/';
}
$metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    despachar($metodo, $caminho);
} catch (Throwable $e) {
    $appDebug = ($cfg['app_debug'] ?? false) === true;
    error_log('Erro na API: ' . $e->getMessage());
    responderErro(500, $appDebug ? $e->getMessage() : 'Erro interno do servidor.');
}

responderErro(404, 'Rota nao encontrada.');
