<?php

declare(strict_types=1);

/**
 * CRUD generico -- as 5 telas de dados (acessos, mudancas, backup, restore,
 * dicionario) tem todas a mesma forma: listar (com busca), pegar um, criar,
 * editar, excluir. Em vez de repetir, escrevemos uma vez aqui; cada modulo
 * so informa sua tabela e colunas (ver $MODULOS em backend/api.php).
 */

/**
 * @param list<string> $camposBusca
 * @return list<array<string, mixed>>
 */
function crudListar(string $tabela, array $camposBusca, string $campoOrdem, string $q, string $inicio = '', string $fim = ''): array
{
    $pdo = db();
    $sql = 'SELECT * FROM ' . quoteIdent($tabela);
    $args = [];
    $condicoes = [];

    if ($q !== '' && count($camposBusca) > 0) {
        // Postgres distingue maiusculas/minusculas no LIKE comum -- por
        // isso usa ILIKE. Os outros motores ja sao "case-insensitive" por
        // padrao com o LIKE normal.
        $operador = dbDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $buscaCond = [];
        // Escapa % e _ para nao serem interpretados como coringas LIKE,
        // exceto os que o proprio sistema adiciona nas extremidades.
        $qEscapado = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        foreach ($camposBusca as $campo) {
            $buscaCond[] = quoteIdent($campo) . " {$operador} ?";
            $args[] = "%{$qEscapado}%";
        }
        $condicoes[] = '(' . implode(' OR ', $buscaCond) . ')';
    }

    // Filtro de periodo (relatorios) -- usa criado_em, que existe em toda
    // tabela do CRUD generico (ver crudCriar), entao funciona pra qualquer
    // modulo sem precisar saber se ele tem um campo "data" proprio.
    if ($inicio !== '') {
        $condicoes[] = quoteIdent('criado_em') . ' >= ?';
        $args[] = "{$inicio} 00:00:00";
    }
    if ($fim !== '') {
        $condicoes[] = quoteIdent('criado_em') . ' <= ?';
        $args[] = "{$fim} 23:59:59";
    }

    if (count($condicoes) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }

    $sql .= ' ORDER BY ' . quoteIdent($campoOrdem) . ' DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    /** @var list<array<string, mixed>> $rows */
    $rows = $stmt->fetchAll();
    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function crudObter(string $tabela, mixed $id): ?array
{
    if ($id === null || !is_numeric($id)) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM ' . quoteIdent($tabela) . ' WHERE ' . quoteIdent('id') . ' = ?');
    $stmt->execute([(int) $id]);
    /** @var array<string, mixed>|false $linha */
    $linha = $stmt->fetch();
    return $linha !== false ? $linha : null;
}

/**
 * @param list<string> $colunas
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function crudCriar(string $tabela, array $colunas, array $body): array
{
    $pdo = db();
    $agora = date('Y-m-d H:i:s');
    $listaColunas = ['criado_em', 'atualizado_em'];
    $valores = [$agora, $agora];
    foreach ($colunas as $campo) {
        $listaColunas[] = $campo;
        $valores[] = array_key_exists($campo, $body) ? $body[$campo] : null;
    }

    $colunasSql = implode(', ', array_map('quoteIdent', $listaColunas));
    $marcadores = implode(', ', array_fill(0, count($listaColunas), '?'));
    $sql = 'INSERT INTO ' . quoteIdent($tabela) . " ({$colunasSql}) VALUES ({$marcadores})";

    if (dbDriver() === 'pgsql') {
        // PDO_PGSQL nao garante lastInsertId() sem o nome da sequence,
        // entao pedimos o id de volta direto no INSERT.
        $sql .= ' RETURNING ' . quoteIdent('id');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($valores);
        $novoId = (int) $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($valores);
        $novoId = (int) $pdo->lastInsertId();
    }

    return (array) crudObter($tabela, $novoId);
}

/**
 * @param list<string> $colunas
 * @param array<string, mixed> $body
 * @return array<string, mixed>|null
 */
function crudAtualizar(string $tabela, array $colunas, mixed $id, array $body): ?array
{
    if (crudObter($tabela, $id) === null) {
        return null;
    }
    $pdo = db();

    // Igual a exclude_unset=True do Pydantic: so altera a coluna se a
    // chave veio no corpo da requisicao (mesmo que o valor seja null).
    $sets = [quoteIdent('atualizado_em') . ' = ?'];
    $valores = [date('Y-m-d H:i:s')];
    foreach ($colunas as $campo) {
        if (array_key_exists($campo, $body)) {
            $sets[] = quoteIdent($campo) . ' = ?';
            $valores[] = $body[$campo];
        }
    }
    $valores[] = (int) $id;

    $sql = 'UPDATE ' . quoteIdent($tabela) . ' SET ' . implode(', ', $sets) . ' WHERE ' . quoteIdent('id') . ' = ?';
    $pdo->prepare($sql)->execute($valores);

    return crudObter($tabela, $id);
}

/** true se excluiu, false se o id nao existia. */
function crudExcluir(string $tabela, mixed $id): bool
{
    if (crudObter($tabela, $id) === null) {
        return false;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM ' . quoteIdent($tabela) . ' WHERE ' . quoteIdent('id') . ' = ?')->execute([(int) $id]);
    return true;
}

/**
 * Apaga ate $tamanhoLote linhas numa unica instrucao, usando a sintaxe
 * nativa de cada motor -- equivale a UMA volta de
 *   WHILE 1=1 BEGIN DELETE TOP (5000) FROM tabela; IF @@ROWCOUNT < 5000 BREAK; END
 * Quem chama (ver rota DELETE /{modulo}/lote em backend/api.php) repete a
 * chamada enquanto o retorno vier igual a $tamanhoLote -- cada chamada e
 * uma "volta do loop" feita numa requisicao HTTP separada, o que permite
 * mostrar uma barra de progresso real pro usuario entre um lote e outro
 * (ver tblExcluirTodos() em js/app.js). Um DELETE sem filtro nenhum, com
 * tabelas de dezenas de milhares de linhas, e o que vinha dando erro
 * interno do servidor no SQL Server.
 *
 * Sem $ids: apaga as primeiras $tamanhoLote linhas da TABELA TODA (caso
 * comum -- "excluir tudo" sem busca ativa). Com $ids: apaga so esses ids
 * (usado quando a tela tinha uma busca/filtro ativo -- "todos" nesse caso
 * significa "todos os filtrados", nao a tabela inteira).
 */
/**
 * @param list<int>|null $ids
 */
function crudExcluirLote(string $tabela, int $tamanhoLote, ?array $ids = null): int
{
    $pdo = db();
    $tabelaSql = quoteIdent($tabela);
    $idCol = quoteIdent('id');

    if ($ids !== null) {
        if (!$ids) {
            return 0;
        }
        $marcadores = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM {$tabelaSql} WHERE {$idCol} IN ({$marcadores})");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    if (dbDriver() === 'sqlsrv') {
        // SQL Server nao tem "LIMIT" -- TOP (n) no DELETE faz o mesmo papel.
        $sql = "DELETE TOP ({$tamanhoLote}) FROM {$tabelaSql}";
    } elseif (dbDriver() === 'mysql') {
        // MySQL aceita LIMIT direto no DELETE.
        $sql = "DELETE FROM {$tabelaSql} LIMIT {$tamanhoLote}";
    } else {
        // sqlite e pgsql nao aceitam LIMIT/TOP no DELETE -- seleciona os
        // ids do lote numa subconsulta e apaga so esses.
        $sql = "DELETE FROM {$tabelaSql} WHERE {$idCol} IN (SELECT {$idCol} FROM {$tabelaSql} LIMIT {$tamanhoLote})";
    }
    return (int) $pdo->exec($sql);
}
