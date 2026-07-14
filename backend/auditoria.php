<?php

declare(strict_types=1);

/**
 * Trilha de auditoria: registra quem fez o que, em qual registro, quando e
 * a partir de qual IP -- tanto nas operacoes de CRUD dos modulos de dados
 * quanto em eventos de autenticacao (login, logout, troca de senha,
 * alteracao de permissoes). So administradores podem consultar essa
 * trilha (ver GET /auditoria em backend/api.php).
 */

/**
 * IP do cliente para trilha de auditoria.
 *
 * So honra X-Forwarded-For quando REMOTE_ADDR for um proxy confiavel
 * listado em conf/config.php ['trusted_proxies'] (array de CIDRs/IPs).
 * Sem isso, qualquer cliente pode forjar o IP gravado na auditoria.
 */
function enderecoIp(): string
{
    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $proxiesConf = config()['trusted_proxies'] ?? [];
    /** @var list<string> $proxies */
    $proxies = is_array($proxiesConf) ? $proxiesConf : [];

    if (count($proxies) > 0 && in_array($remoteAddr, $proxies, true)) {
        $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            // Pega o IP mais a DIREITA que nao seja um proxy confiavel.
            // Pegar partes[0] (mais a esquerda) permite spoofing: o atacante
            // envia "X-Forwarded-For: 9.9.9.9" e o proxy ANEXA o IP real,
            // mas partes[0] fica com o valor forjado.
            $partes = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($partes as $parte) {
                if ($parte !== '' && !in_array($parte, $proxies, true)) {
                    return $parte;
                }
            }
        }
    }

    return $remoteAddr;
}

/**
 * Grava uma linha na trilha de auditoria. $dadosAntes/$dadosDepois sao
 * arrays (gravados como JSON) ou null quando nao se aplica -- ex.: login
 * e logout nao tem um "registro" de tabela, so o evento em si.
 *
 * Nunca derruba a requisicao se a gravacao falhar (ex.: banco
 * momentaneamente fora do ar): auditoria e "best effort" e nao pode ser a
 * causa de uma operacao legitima do usuario falhar.
 */
/**
 * @param array<string, mixed>|null $dadosAntes
 * @param array<string, mixed>|null $dadosDepois
 */
function registrarAuditoria(
    string $tabela,
    ?int $registroId,
    string $acao,
    ?string $usuario,
    ?array $dadosAntes = null,
    ?array $dadosDepois = null
): void {
    try {
        $pdo = db();
        $colunas = ['tabela', 'registro_id', 'acao', 'usuario', 'dados_antes', 'dados_depois', 'ip', 'criado_em'];
        $sql = 'INSERT INTO ' . quoteIdent(tableName('auditoria_log')) . ' (' .
            implode(', ', array_map('quoteIdent', $colunas)) . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $pdo->prepare($sql)->execute([
            $tabela,
            $registroId,
            $acao,
            $usuario,
            $dadosAntes !== null ? json_encode($dadosAntes, JSON_UNESCAPED_UNICODE) : null,
            $dadosDepois !== null ? json_encode($dadosDepois, JSON_UNESCAPED_UNICODE) : null,
            enderecoIp(),
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Best effort -- ver comentario da funcao acima.
    }
}

/**
 * Lista a trilha de auditoria com filtros opcionais (usuario, tabela,
 * acao, periodo) e paginacao. Usado pelo GET /auditoria (admin-only).
 */
/**
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function listarAuditoria(array $filtros, int $pagina, int $porPagina): array
{
    $pdo = db();
    $where = [];
    $args = [];

    if (trim((string) ($filtros['usuario'] ?? '')) !== '') {
        $operador = dbDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $where[] = quoteIdent('usuario') . " {$operador} ?";
        $args[] = '%' . trim((string) $filtros['usuario']) . '%';
    }
    if (trim((string) ($filtros['tabela'] ?? '')) !== '') {
        $where[] = quoteIdent('tabela') . ' = ?';
        $args[] = trim((string) $filtros['tabela']);
    }
    if (trim((string) ($filtros['acao'] ?? '')) !== '') {
        $where[] = quoteIdent('acao') . ' = ?';
        $args[] = trim((string) $filtros['acao']);
    }
    if (trim((string) ($filtros['inicio'] ?? '')) !== '') {
        $where[] = quoteIdent('criado_em') . ' >= ?';
        $args[] = trim((string) $filtros['inicio']) . ' 00:00:00';
    }
    if (trim((string) ($filtros['fim'] ?? '')) !== '') {
        $where[] = quoteIdent('criado_em') . ' <= ?';
        $args[] = trim((string) $filtros['fim']) . ' 23:59:59';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $tabela = quoteIdent(tableName('auditoria_log'));

    $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM ' . $tabela . $whereSql);
    $stmtTotal->execute($args);
    $total = (int) $stmtTotal->fetchColumn();

    $pagina = max(1, $pagina);
    $porPagina = max(1, min(200, $porPagina));
    $offset = ($pagina - 1) * $porPagina;

    $ordemSql = ' ORDER BY ' . quoteIdent('criado_em') . ' DESC, ' . quoteIdent('id') . ' DESC';
    if (dbDriver() === 'sqlsrv') {
        // SQL Server nao entende "LIMIT ... OFFSET" -- precisa de
        // "OFFSET ... ROWS FETCH NEXT ... ROWS ONLY" (exige ORDER BY, que
        // ja temos acima). Sem esse branch, toda consulta de auditoria
        // falhava com erro de sintaxe nesse motor.
        $sql = 'SELECT * FROM ' . $tabela . $whereSql . $ordemSql .
            ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $porPagina . ' ROWS ONLY';
    } else {
        $sql = 'SELECT * FROM ' . $tabela . $whereSql . $ordemSql .
            ' LIMIT ' . $porPagina . ' OFFSET ' . $offset;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $linhas = $stmt->fetchAll();

    return [
        'itens' => $linhas,
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => (int) ceil($total / $porPagina),
    ];
}
