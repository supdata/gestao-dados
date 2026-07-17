<?php

/**
 * Configuracao do portal. Copie este arquivo para "config.php" (mesma
 * pasta) e ajuste os valores -- "config.php" nao entra no controle de
 * versao (veja .gitignore), so este "config.example.php" fica versionado.
 *
 * Por que um arquivo PHP em vez de um ".env"? Porque e mais simples ainda:
 * zero codigo de leitura/parsing -- o PHP ja entende isso nativamente
 * quando voce faz "require". Sem nenhuma dependencia externa.
 *
 * Na pratica, voce nao costuma preencher este arquivo na mao: o assistente
 * de instalacao (acesse /setup no navegador logo apos copiar o projeto pro
 * servidor) pergunta esses dados num formulario e grava o config.php pra
 * voce. Este arquivo aqui e so o modelo de referencia / instalacao manual.
 */

return [
    // Nome do projeto -- aparece no titulo da aba do navegador. O projeto
    // nao tem marca fixa no codigo; normalmente este campo e preenchido
    // pelo assistente de instalacao (/setup), nao precisa editar na mao.
    'project_title' => 'Portal de Dados',

    // Prefixo colocado na frente do nome de toda tabela no banco (ex.:
    // "gdt" gera "gdt_usuarios", "gdt_acessos", etc). Util quando varios
    // projetos compartilham o mesmo banco/servidor (comum em hospedagem
    // compartilhada) -- cada instalacao usa seu proprio prefixo e as
    // tabelas nunca se misturam. O assistente de instalacao (/setup)
    // pergunta isso num campo opcional; se a pessoa deixar em branco, o
    // padrao "gdt" e usado automaticamente.
    'table_prefix' => 'gdt',

    // Motor do banco: mysql | pgsql | sqlsrv | sqlite
    // Por padrao usamos MySQL. Pra zero configuracao (nem instalar nada),
    // troque 'db_driver' para 'sqlite'.
    'db_driver' => 'mysql',

    // Usados quando db_driver e mysql, pgsql ou sqlsrv
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'portal_dados',
    'db_user' => 'root',
    'db_password' => '',

    // Usado somente quando db_driver e sqlite. O assistente de instalacao
    // (/setup) preenche isso sozinho com "db/database.db" na raiz do
    // projeto, criando a pasta "db/" automaticamente -- nao precisa
    // informar caminho nenhum. Deixe assim que o sistema usa esse mesmo
    // padrao.
    'db_sqlite_path' => __DIR__ . '/../db/database.db',

    // Chave usada pra assinar o token de login (JWT). Troque por um valor
    // longo e aleatorio antes de usar em producao. O assistente de
    // instalacao gera um valor aleatorio automaticamente.
    'secret_key' => 'troque-esta-chave-por-um-valor-aleatorio-e-grande',

    // Por quanto tempo (em minutos) o login fica valido sem precisar logar de novo
    'token_expire_minutes' => 480,

    // Dominio de onde o portal e servido. Em desenvolvimento local "*"
    // (libera qualquer origem) e pratico; em producao, troque pelo dominio
    // real do portal -- nunca deixe "*" numa API que carrega token de login.
    // Exemplo: 'cors_allowed_origin' => 'https://portal.minhaempresa.com.br',
    'cors_allowed_origin' => '*',

    // Deixe false em producao: erros mostram so uma mensagem amigavel pro
    // cliente, e o detalhe completo vai pro log do PHP-FPM/servidor. So
    // mude pra true temporariamente, durante depuracao local.
    'app_debug' => false,

    // Proxy reverso: se o portal ficar ATRAS de um proxy (Nginx como proxy,
    // IIS ARR, load balancer, Cloudflare etc.), liste aqui os IPs/CIDRs desses
    // proxies. So assim o portal passa a usar o X-Forwarded-For pra descobrir
    // o IP real do cliente -- tanto na auditoria quanto no limite de login por
    // IP. Sem isso, o IP visto e o do proxy para TODOS os usuarios, e o teto
    // por IP pode acabar bloqueando o login de todos de uma vez. Liste APENAS
    // os IPs dos seus proxies; nunca faixas publicas amplas (reabre spoofing
    // de X-Forwarded-For). Sem proxy reverso, deixe vazio.
    // Exemplo: 'trusted_proxies' => ['10.0.0.5', '192.168.0.0/24'],
    'trusted_proxies' => [],
];
