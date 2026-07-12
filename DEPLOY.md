# Deploy em produção (sem Docker)

Guia para subir o Portal de Gestão de Dados num servidor real (VPS, servidor on-premise, etc.), direto no sistema operacional, sem container. Recomendação principal: **Linux + Nginx + PHP-FPM**. No fim do documento tem o que muda se você usar Apache ou IIS (Windows Server) em vez de Nginx.

**Arquitetura de entrada única, sem dependências:** um `index.php` na raiz do projeto resolve tudo — assistente de instalação (`/setup`), API (`/api/...`), arquivos estáticos (`css/`, `js/`, `img/`) e a página do portal, na mesma porta/domínio. É o mesmo arquivo que você usa em desenvolvimento local (`php -S`), só que em produção quem o aciona é o servidor web. O document root do site é sempre a **raiz do projeto** (a pasta que contém `index.php`, `setup/`, `backend/`, `conf/`, `css/`, `js/` e `img/` lado a lado). Não há `vendor/` nem `composer.json` — o backend não usa nenhuma biblioteca externa, então não há passo de "instalar dependências" neste guia.

**Importante sobre o `/setup`:** o projeto é distribuído sem título fixo e sem usuário administrador padrão — quem instala define os dois pelo assistente em `/setup` (motor de banco, conexão, título do projeto e login/senha do admin). Depois de instalar com sucesso, a pasta `setup/` é removida automaticamente; se isso falhar no seu ambiente, o próprio assistente avisa na tela final pra você remover manualmente.

Os arquivos de configuração de exemplo citados aqui ficam na pasta `deploy/` na raiz do projeto.

## 0. Antes de começar

- Decida o motor de banco antes: MySQL, PostgreSQL ou SQL Server (SQLite funciona, mas é melhor para teste/uso de uma pessoa só — em produção com várias pessoas acessando ao mesmo tempo, prefira MySQL/PostgreSQL/SQL Server).
- O front controller (`index.php`) já tem proteção embutida contra acesso direto a `backend/`, `conf/` e outros arquivos internos — mas isso depende do servidor web estar configurado certo (passo 5). Não pule a configuração do servidor pensando que "é só apontar pra pasta e funciona".

## 1. Preparar o servidor

No servidor (exemplo para Ubuntu/Debian — adapte o gerenciador de pacotes se for outra distro):

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-cli php-mbstring php-xml

# extensão do banco que você for usar:
sudo apt install -y php-mysql      # MySQL
# sudo apt install -y php-pgsql    # PostgreSQL
# sqlsrv/pdo_sqlsrv do SQL Server não vem em repositório padrão do apt —
# baixe do site da Microsoft (pacotes msodbcsql18 + pacotes PECL sqlsrv/pdo_sqlsrv)
```

Confirme a versão: `php -v` (precisa ser 8.1+). Não há Composer nem `composer.json` neste projeto — nada para instalar além do próprio PHP e a extensão do banco escolhido.

## 2. Copiar o projeto para o servidor

Use o método que preferir (`git clone`, `scp`, `rsync`, etc.). Sugestão de caminho:

```bash
sudo mkdir -p /var/www/meu-portal
# copie index.php, .htaccess, setup/, backend/, conf/, css/, js/ e img/ para dentro dessa pasta
```

A pasta final no servidor deve ter `index.php`, `setup/`, `backend/`, `conf/`, `css/`, `js/` e `img/` lado a lado, exatamente como no projeto original. Não é necessário copiar `deploy/` nem `DEPLOY.md` para o servidor — são só material de referência (e o `.htaccess`/configs já bloqueiam o acesso via web a eles mesmo se você copiar por engano). **Não crie `conf/config.php` manualmente neste momento** — é o assistente `/setup` (próximo passo) que vai gerar esse arquivo.

## 3. Instalar pelo assistente `/setup`

Com o servidor web já apontando para a pasta do projeto (ver passo 5) e sem `conf/config.php` ainda existindo, acesse:

```
https://portal.minhaempresa.com.br/setup/
```

Preencha o formulário com os dados de **produção**: título do projeto, motor de banco (MySQL/PostgreSQL/SQL Server — veja o passo 4 para criar o banco antes), host/porta/nome/usuário/senha de conexão e o login/senha do administrador. Use o botão "Testar conexão" antes de instalar.

Ao confirmar, o assistente:

- grava `conf/config.php` sozinho, já com uma `secret_key` aleatória e segura (não precisa gerar nem editar essa chave na mão);
- cria as tabelas no banco escolhido;
- cria o usuário administrador com a senha que você definiu;
- remove a própria pasta `setup/` do servidor, por segurança.

Depois de instalado, se quiser revisar ou ajustar algo manualmente (trocar `cors_allowed_origin`, por exemplo), edite `conf/config.php` direto — é um array PHP puro (`return [...]`), sem sintaxe nova. Os pontos que mais importa revisar em produção:

- `cors_allowed_origin`: troque o `*` pelo domínio real do portal (ex.: `https://portal.minhaempresa.com.br`). Isso impede que outros sites façam requisições à sua API usando o token de um usuário logado.
- `app_debug`: deve ficar `false` (é o padrão gerado pelo assistente). Com `true`, erros vazam detalhes internos pra quem está usando o navegador — só use `true` durante depuração, nunca num servidor exposto.

> **Automação/CI-CD:** se seu processo de deploy não permite passar por um formulário no navegador, dá pra pular o `/setup` e gerar `conf/config.php` programaticamente a partir de `conf/config.example.php` (mesmo formato de array PHP) — nesse caso é você quem garante uma `secret_key` aleatória forte e cria o primeiro usuário administrador direto no banco, com senha já em bcrypt (`password_hash()`).

## 4. Banco de dados

Antes de rodar o `/setup`, crie o banco e o usuário de acesso (exemplo em MySQL):

```sql
CREATE DATABASE portal_dados CHARACTER SET utf8mb4;
CREATE USER 'portal_dados'@'localhost' IDENTIFIED BY 'uma-senha-forte-aqui';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON portal_dados.* TO 'portal_dados'@'localhost';
FLUSH PRIVILEGES;
```

Por que não `GRANT ALL PRIVILEGES`: o portal nunca executa `DROP`/`TRUNCATE` em produção — `migrate()` (`backend/db.php`) só cria tabela/coluna que ainda não existe (por isso precisa de `CREATE`/`ALTER`/`INDEX`) e o dia a dia da aplicação é `SELECT`/`INSERT`/`UPDATE`/`DELETE`. Limitar o grant a essa lista reduz o estrago possível caso a senha desse usuário seja exposta algum dia (quem a obtiver não consegue apagar tabelas nem ler outros bancos do mesmo servidor).

Não precisa criar as tabelas na mão — o assistente `/setup` (passo 3) cria todas elas, e também o usuário administrador, no momento da instalação.

## 5. Configurar o servidor web (Nginx + PHP-FPM)

Copie o exemplo e ajuste os pontos marcados com "AJUSTE AQUI" (domínio, caminho do projeto, socket do PHP-FPM):

```bash
sudo cp /caminho/onde/voce/copiou/deploy/nginx.conf.example /etc/nginx/sites-available/meu-portal
sudo nano /etc/nginx/sites-available/meu-portal   # ajuste os pontos marcados
sudo ln -s /etc/nginx/sites-available/meu-portal /etc/nginx/sites-enabled/
sudo nginx -t      # testa a sintaxe antes de aplicar
sudo systemctl reload nginx
```

O `root` do site aponta para a raiz do projeto (`/var/www/meu-portal`). O Nginx serve os arquivos estáticos reais (`css/`, `js/`, `img/`) direto (rápido, sem nem chamar o PHP) e manda qualquer outra coisa — rotas do SPA e tudo que começa com `/api/` — para o `index.php` único via PHP-FPM (`try_files $uri $uri/ /index.php;` — o padrão mais simples de front controller, sem nenhum truque de caminho). Esse `index.php` é quem decide, internamente, se a requisição é API, arquivo estático ou página.

Confira que o serviço do PHP-FPM está rodando e habilitado para iniciar com o servidor:

```bash
sudo systemctl status php8.1-fpm     # ajuste a versão conforme o que foi instalado
sudo systemctl enable php8.1-fpm
```

## 6. Permissões de arquivo

O usuário que o Nginx/PHP-FPM usa (geralmente `www-data`) precisa conseguir ler todo o projeto e, se você estiver usando **SQLite**, também escrever na pasta `db/` (é lá que o assistente `/setup` cria o arquivo `database.db` sozinho — não precisa criar essa pasta na mão antes):

```bash
sudo chown -R www-data:www-data /var/www/meu-portal
```

Com MySQL/PostgreSQL/SQL Server isso é menos crítico (o PHP só precisa ler os arquivos do projeto; quem grava os dados é o próprio banco, em outro processo).

**Trave a leitura de `conf/config.php` (tem senha do banco e `secret_key` em texto puro):** em servidor compartilhado com outros usuários/processos no mesmo SO, restrinja a leitura desse arquivo só ao usuário do PHP-FPM:

```bash
sudo chmod 640 /var/www/meu-portal/conf/config.php
sudo chown www-data:www-data /var/www/meu-portal/conf/config.php
```

Isso não substitui o bloqueio via servidor web (passo 0/checklist) — é uma segunda camada, contra outro processo/usuário local lendo o arquivo direto do disco, fora do Nginx/Apache.

## 7. HTTPS

Depois que o domínio estiver apontando para o IP do servidor (registro DNS tipo A), use o Certbot para emitir e configurar o certificado automaticamente:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d portal.minhaempresa.com.br
```

Ele edita o `nginx.conf` sozinho, adicionando o bloco HTTPS e o redirecionamento de HTTP para HTTPS.

## 8. Primeiro acesso

Abra `https://portal.minhaempresa.com.br` (ou o domínio configurado). Não existe usuário administrador padrão: entre com o login e a senha que você mesmo escolheu no formulário do `/setup` (passo 3). Se ainda não instalou, o portal te redireciona automaticamente para `/setup/` nesse primeiro acesso.

Como administrador, crie os demais logins da equipe na aba "Usuários" — não há necessidade de compartilhar a senha do administrador com mais ninguém.

## 9. Como atualizar depois de alguma mudança no código

```bash
cd /var/www/meu-portal
# traga o código novo (git pull, ou copie os arquivos atualizados)
sudo systemctl reload php8.1-fpm
sudo systemctl reload nginx                          # só se o nginx.conf mudou
```

Sem Composer, não há `vendor/` pra reinstalar — atualizar o código é só substituir os arquivos `.php`/`.css`/`.js`. Atualizações de código não apagam dados do banco — `migrate()` (em `backend/db.php`) só cria tabelas que ainda não existem, nunca apaga nada.

## Checklist de segurança antes de divulgar a URL pro time

- [ ] `secret_key` (em `conf/config.php`) é um valor único, longo e aleatório (não é o do `config.example.php`)
- [ ] `cors_allowed_origin` é o domínio real, não `*` (em produção a API recusa iniciar com `*` — é intencional)
- [ ] `app_debug` é `false`
- [ ] A pasta `setup/` não existe mais no servidor (o assistente se autoexclui ao final; confira manualmente se o aviso de falha na autoexclusão apareceu na tela de sucesso)
- [ ] Acessar `https://seu-dominio/setup/` agora redireciona para a tela de login (ou dá 404), nunca reabre o formulário de instalação
- [ ] HTTPS configurado (certificado válido, não autoassinado)
- [ ] HSTS ativado nos exemplos de deploy (`deploy/`) após validar o certificado — comece com `max-age=86400` (1 dia) e só suba para 1 ano quando tiver certeza
- [ ] Acesso direto a `https://seu-dominio/conf/config.php` (e variações, tipo `/backend/`, `/conf/`, `/db/database.db`, `/DEPLOY.md`) retorna 403/404, nunca o conteúdo do arquivo
- [ ] Backup do banco de dados configurado (rotina externa à aplicação — isso é responsabilidade da infraestrutura, não do portal)


## Segurança atrás de proxy reverso / load balancer

O bloqueio de força bruta no login (`backend/auth.php`) usa o par **usuário + IP** para contar tentativas, com o IP vindo de `$_SERVER['REMOTE_ADDR']`.

**Limitação conhecida:** atrás de um proxy reverso (Nginx, Apache, AWS ALB, Cloudflare…) sem configuração adicional, todos os clientes chegam com o mesmo IP — o do proxy. Isso significa que cinco tentativas de senha errada de qualquer pessoa podem bloquear temporariamente o acesso de todo mundo para aquele usuário.

**Como corrigir:** configure o servidor web para repassar o IP real do cliente:

- **Nginx:** `real_ip_header X-Forwarded-For;` + `set_real_ip_from <ip-do-proxy>;`
- **Apache:** módulo `mod_remoteip` com `RemoteIPHeader X-Forwarded-For`

> **Atenção:** confiar em `X-Forwarded-For` sem definir quais proxies são confiáveis é falsificável — qualquer cliente pode enviar esse header com o valor que quiser. Sempre restrinja com `set_real_ip_from` / `RemoteIPTrustedProxy` ao IP real do seu proxy.

## Usando Apache em vez de Nginx

Use `deploy/apache-vhost.conf.example` como ponto de partida (ajuste os pontos marcados). O `DocumentRoot` também aponta para a raiz do projeto — quem faz o trabalho de rotear API vs. estáticos vs. página é o `.htaccess` que já vem na raiz do projeto (não precisa criar nada a mais), desde que `AllowOverride All` esteja habilitado (já está no exemplo). Os passos 1–4 e 6–9 deste guia continuam os mesmos; só o passo 5 muda: copie para `/etc/apache2/sites-available/`, habilite com `sudo a2ensite meu-portal && sudo a2enmod rewrite && sudo systemctl reload apache2`. O vhost de exemplo já inclui blocos `Require all denied` para `backend/` e `conf/`, como reforço além do `.htaccess`.

## Usando IIS (Windows Server) em vez de Nginx

Mais trabalhoso de configurar do que Nginx/Apache porque o PHP no Windows depende do módulo FastCGI do IIS. Resumo:

1. Instale o PHP para Windows (versão *Non Thread Safe*, recomendada para uso com FastCGI) e o **PHP Manager for IIS** (facilita registrar o FastCGI).
2. Instale o módulo **URL Rewrite** da Microsoft no IIS.
3. Crie o site no IIS com "Physical path" apontando para a raiz do projeto (a pasta com `index.php`, `setup/`, `backend/`, `conf/`, `css/`, `js/` e `img/`). Copie `deploy/iis-web.config.example` para `web.config` nessa mesma pasta.
4. Ajuste o `scriptProcessor` do `web.config` para o caminho real do `php-cgi.exe` instalado.
5. É um único site, com o `index.php` decidindo tudo (API, estático ou página), igual ao Linux — não precisa criar uma "Application" separada para `/api`.
6. Para HTTPS no IIS, use um certificado (Let's Encrypt via `win-acme` é a opção gratuita mais comum) e vincule-o nas "Site Bindings".

Os passos 1–4 e 6–9 deste guia (config, banco de dados, permissões, primeiro acesso, checklist) valem igual, só trocando comandos de `systemctl`/`apt` por equivalentes do Windows (gerenciador de serviços, etc.).
