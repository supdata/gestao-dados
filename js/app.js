/* ===========================================================================
 * Portal de Gestão de Dados
 * Lógica do front-end. Tudo aqui fala com o backend via api.js (fetch).
 * Nada de dados ficam só no navegador — cada criação/edição/exclusão grava
 * direto no banco através da API.
 * ======================================================================== */

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const fmtDate = (d) => { if (!d) return ''; const p = d.split('-'); return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : d; };

let view = 'overview';
let query = '';
let editId = null;
let editKey = null;
let currentUser = null;
let mfaToken = null; // identifica a tentativa de login pendente de codigo MFA (ver /auth/login)
let cache = { acessos: [], mudancas: [], backup: [], restore: [], dicionario: [], integracoes: [], usuarios: [] };
let lang = localStorage.getItem('lang') || 'pt';

// ---------------------------------------------------------------------------
// Estado da tabela moderna (toolbar de filtros/colunas/ordenacao, paginacao
// e selecao em massa). Tudo client-side sobre o cache[key] ja carregado --
// a unica chamada nova a API e a de tblBulkDelete (excluir selecionados).
// ---------------------------------------------------------------------------
const PAGE_SIZE = 10;
let tblState = {};
function tblStateFor(key) {
  if (!tblState[key]) tblState[key] = { page: 1, sortCol: null, sortDir: 'asc', filterCol: null, filterVal: null, sel: new Set(), hidden: loadHiddenCols(key) };
  return tblState[key];
}
function loadHiddenCols(key) {
  try { return new Set(JSON.parse(localStorage.getItem('tblHidden_' + key) || '[]')); } catch (e) { return new Set(); }
}
function saveHiddenCols(key, st) {
  localStorage.setItem('tblHidden_' + key, JSON.stringify([...st.hidden]));
}

const AVATAR_COLORS = ['#3D6B8C', '#3D8C6B', '#8C6B3D', '#6B3D8C', '#8C3D5E', '#3D7A8C'];
function initials(nome) {
  const partes = String(nome || '').trim().split(/\s+/).filter(Boolean);
  if (!partes.length) return '?';
  return (partes[0][0] + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase();
}
function avatarColor(seed) {
  let h = 0;
  const s = String(seed || '');
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return AVATAR_COLORS[h % AVATAR_COLORS.length];
}
function avatarCellHtml(nome) {
  return `<div class="cell-avatar"><span class="cell-avatar-ico" style="background:${avatarColor(nome)}">${esc(initials(nome))}</span><span class="cell-avatar-txt">${esc(nome || '—')}</span></div>`;
}

// ---------------------------------------------------------------------------
// Opções dos campos <select> (mesmas do console original)
// ---------------------------------------------------------------------------
const OPT = {
  tipoObjeto: ['Procedure','Função','Trigger','View','Tabela','Índice','Sequence','Package','Job','Tipo','Sinônimo','Grant','Script','Outro'],
  tipoConta: ['Login SQL', 'Login Windows', 'Grupo AD'],
  statusAc: ['Ativo', 'Revogado', 'Suspenso'],
  ambiente: ['Produção', 'Homologação', 'Desenvolvimento'],
  tipoChg: ['DDL / estrutura', 'Configuração', 'Patch / versão', 'Índice', 'Segurança', 'Outro'],
  statusChg: ['Planejada', 'Aprovada', 'Executada', 'Revertida', 'Cancelada'],
  criticidade: ['Alta', 'Média', 'Baixa'],
  tipoBkp: ['Full', 'Full + Diff', 'Full + Log', 'Full + Diff + Log'],
  resultado: ['OK', 'Falha', 'OK com ressalvas'],
  nulo: ['Sim', 'Não'],
  classif: ['Pública', 'Interna', 'Confidencial', 'Confidencial (PII)', 'Restrita'],
  direcaoInt: ['Entrada', 'Saída', 'Bidirecional'],
  freqInt: ['Tempo real', 'Cada minuto', 'Horária', 'Diária', 'Semanal', 'Mensal', 'Sob demanda', 'Eventual'],
  classifInt: ['Público', 'Interno', 'Confidencial', 'Pessoal / Sensível (LGPD)'],
};

// ---------------------------------------------------------------------------
// Tipos cadastráveis (menu Cadastro): essas categorias eram listas fixas no
// código (OPT) e agora vêm do banco, editáveis pelo admin em Cadastro.
// tiposPorCategoria é um cache em memória, recarregado por fetchTipos().
// ---------------------------------------------------------------------------
const CATEGORIAS_CADASTRO = [
  { cat: 'mudanca_ambiente', grupo: 'Mudanças', label: 'Ambiente', ico: 'db' },
  { cat: 'mudanca_tipo', grupo: 'Mudanças', label: 'Tipo', ico: 'refresh' },
  { cat: 'mudanca_status', grupo: 'Mudanças', label: 'Status', ico: 'clock' },
  { cat: 'backup_criticidade', grupo: 'Backup', label: 'Criticidade', ico: 'alert' },
  { cat: 'backup_tipo', grupo: 'Backup', label: 'Tipo de backup', ico: 'db' },
  { cat: 'backup_resultado', grupo: 'Backup', label: 'Resultado (Restore)', ico: 'shield' },
  { cat: 'acesso_nivel', grupo: 'Acessos', label: 'Nível de acesso', ico: 'shield' },
  { cat: 'integracao_tipo', grupo: 'Integrações', label: 'Tipo', ico: 'refresh' },
  { cat: 'integracao_ambiente', grupo: 'Integrações', label: 'Ambiente', ico: 'db' },
  { cat: 'integracao_criticidade', grupo: 'Integrações', label: 'Criticidade', ico: 'alert' },
  { cat: 'integracao_status', grupo: 'Integrações', label: 'Status', ico: 'clock' },
];
let tiposPorCategoria = {};

async function fetchTipos() {
  try {
    const lista = await api.get('/tipos');
    const agrupado = {};
    lista.forEach((t) => { (agrupado[t.categoria] = agrupado[t.categoria] || []).push(t); });
    tiposPorCategoria = agrupado;
  } catch (e) { /* mantém o cache anterior se a chamada falhar */ }
  return tiposPorCategoria;
}

/** Opções de um <select>: vêm do Cadastro (f.cat) ou da lista fixa antiga (f.o). */
function optionsFor(f) {
  if (f.cat) return (tiposPorCategoria[f.cat] || []).map((t) => t.nome);
  return f.o || [];
}

// ---------------------------------------------------------------------------
// Esquema de cada módulo: que campos existem, qual o tipo de input, qual
// aparece na tabela. É a mesma ideia do console original; só os nomes
// "codigo" (mudanças) e "schema_nome"/"permite_nulo" (dicionário) mudaram
// para bater com as colunas do banco.
// ---------------------------------------------------------------------------
const SCHEMA = {
  acessos: { title: 'Matriz de acessos', sub: 'Quem tem acesso a quê, com justificativa e aprovador', singular: 'acesso',
    fields: [
      { k: 'data', l: 'Data da concessão', t: 'date', table: 1, mono: 1 },
      { k: 'usuario', l: 'Usuário / login', t: 'text', table: 1, mono: 1 },
      { k: 'tipo', l: 'Tipo de conta', t: 'select', o: OPT.tipoConta, table: 1 },
      { k: 'servidor', l: 'Servidor', t: 'text', table: 1, mono: 1 },
      { k: 'objeto', l: 'Banco / objeto', t: 'text', table: 1 },
      { k: 'nivel', l: 'Nível de acesso', t: 'tagselect', cat: 'acesso_nivel', table: 1, mono: 1, trunc: 1 },
      { k: 'justificativa', l: 'Justificativa', t: 'textarea', full: 1, table: 1, trunc: 1 },
      { k: 'solicitante', l: 'Solicitante', t: 'text' },
      { k: 'aprovador', l: 'Aprovador', t: 'text', table: 1 },
      { k: 'revisao', l: 'Revisão prevista', t: 'date', table: 1, mono: 1 },
      { k: 'status', l: 'Status', t: 'select', o: OPT.statusAc, table: 1, pill: 1 },
      { k: 'obs', l: 'Observações', t: 'textarea', full: 1 },
    ] },
  mudancas: { title: 'Registro de mudanças', sub: 'Alterações em produção com aprovação e rollback', singular: 'mudança',
    fields: [
      { k: 'codigo', l: 'Chamado', t: 'text', table: 1, mono: 1 },
      { k: 'data', l: 'Data', t: 'date', table: 1, mono: 1 },
      { k: 'ambiente', l: 'Ambiente', t: 'select', cat: 'mudanca_ambiente', table: 1, pill: 1 },
      { k: 'tipo', l: 'Tipo', t: 'select', cat: 'mudanca_tipo', table: 1 },
      { k: 'descricao', l: 'Descrição da mudança', t: 'textarea', full: 1, table: 1, trunc: 1 },
      { k: 'objetos', l: 'Objetos', t: 'objetos', full: 1, table: 1, trunc: 1 },
      { k: 'rollback', l: 'Plano de rollback', t: 'textarea', full: 1 },
      { k: 'solicitante', l: 'Solicitante', t: 'text' },
      { k: 'aprovador', l: 'Aprovador', t: 'text', table: 1 },
      { k: 'status', l: 'Status', t: 'select', cat: 'mudanca_status', table: 1, pill: 1 },
      { k: 'resultado', l: 'Resultado / observações', t: 'textarea', full: 1, trunc: 1 },
    ] },
  backup: { title: 'Política de backup', sub: 'Regra de backup por banco', singular: 'política',
    fields: [
      { k: 'banco', l: 'Banco', t: 'text', table: 1, mono: 1 },
      { k: 'criticidade', l: 'Criticidade', t: 'select', cat: 'backup_criticidade', table: 1, pill: 1 },
      { k: 'tipo', l: 'Tipo de backup', t: 'select', cat: 'backup_tipo', table: 1 },
      { k: 'frequencia', l: 'Frequência', t: 'text', table: 1 },
      { k: 'horario', l: 'Horário', t: 'text', mono: 1 },
      { k: 'retencao', l: 'Retenção', t: 'text', table: 1 },
      { k: 'local', l: 'Local de armazenamento', t: 'text', mono: 1, trunc: 1, table: 1 },
      { k: 'rpo', l: 'RPO alvo', t: 'text', table: 1, mono: 1 },
      { k: 'rto', l: 'RTO alvo', t: 'text', table: 1, mono: 1 },
      { k: 'responsavel', l: 'Responsável', t: 'text' },
    ] },
  restore: { title: 'Restore', sub: 'Registro das recuperações testadas', singular: 'teste',
    fields: [
      { k: 'data', l: 'Data do teste', t: 'date', table: 1, mono: 1 },
      { k: 'banco', l: 'Banco', t: 'text', table: 1, mono: 1 },
      { k: 'backup', l: 'Backup testado', t: 'select', o: OPT.nulo, table: 1 },
      { k: 'tempo', l: 'Tempo de restore', t: 'text', table: 1, mono: 1 },
      { k: 'resultado', l: 'Resultado', t: 'select', cat: 'backup_resultado', table: 1, pill: 1 },
      { k: 'por', l: 'Testado por', t: 'text', table: 1 },
      { k: 'obs', l: 'Observações', t: 'textarea', full: 1, trunc: 1, table: 1 },
    ] },
  dicionario: { title: 'Dicionário de dados', sub: 'Significado, origem e classificação de cada coluna', singular: 'campo',
    fields: [
      { k: 'servidor', l: 'Servidor', t: 'text', table: 1, mono: 1 },
      { k: 'banco', l: 'Banco', t: 'text', table: 1, mono: 1 },
      { k: 'schema_nome', l: 'Schema', t: 'text', table: 1, mono: 1 },
      { k: 'tabela', l: 'Tabela', t: 'text', table: 1, mono: 1 },
      { k: 'coluna', l: 'Coluna', t: 'text', table: 1, mono: 1 },
      { k: 'tipo_dado', l: 'Tipo de dado', t: 'text', table: 1, mono: 1 },
      { k: 'permite_nulo', l: 'Permite nulo?', t: 'select', o: OPT.nulo, table: 1 },
      { k: 'descricao', l: 'Descrição / significado', t: 'textarea', full: 1, table: 1, trunc: 1 },
      { k: 'classificacao', l: 'Classificação', t: 'select', o: OPT.classif, table: 1, pill: 1 },
      { k: 'origem', l: 'Origem / sistema', t: 'text' },
      { k: 'obs', l: 'Observações', t: 'textarea', full: 1, trunc: 1, table: 1 },
    ] },
  integracoes: { title: 'Integrações de sistemas', sub: 'Conexões, fluxos de dados e dependências entre sistemas', singular: 'integração',
    fields: [
      { k: 'nome', l: 'Integração', t: 'text', table: 1, mono: 1 },
      { k: 'origem', l: 'Sistema de origem', t: 'text', table: 1 },
      { k: 'ip_origem', l: 'IP de origem', t: 'text', table: 1, mono: 1 },
      { k: 'destino', l: 'Sistema de destino', t: 'text', table: 1 },
      { k: 'ip_destino', l: 'IP de destino', t: 'text', table: 1, mono: 1 },
      { k: 'tipo', l: 'Tipo', t: 'select', cat: 'integracao_tipo', table: 1 },
      { k: 'direcao', l: 'Direção', t: 'select', o: OPT.direcaoInt },
      { k: 'mecanismo', l: 'Mecanismo', t: 'text', mono: 1 },
      { k: 'frequencia', l: 'Frequência', t: 'select', o: OPT.freqInt },
      { k: 'dados_trafegados', l: 'Dados trafegados', t: 'textarea', full: 1 },
      { k: 'classificacao', l: 'Classificação do dado', t: 'select', o: OPT.classifInt, table: 1, pill: 1 },
      { k: 'criticidade', l: 'Criticidade', t: 'select', cat: 'integracao_criticidade', table: 1, pill: 1 },
      { k: 'resp_tecnico', l: 'Responsável técnico', t: 'text', table: 1 },
      { k: 'resp_negocio', l: 'Responsável de negócio', t: 'text', table: 1 },
      { k: 'ambiente', l: 'Ambiente', t: 'select', cat: 'integracao_ambiente' },
      { k: 'status', l: 'Status', t: 'select', cat: 'integracao_status', table: 1, pill: 1 },
      { k: 'ultima_revisao', l: 'Última revisão', t: 'date', table: 1, mono: 1 },
      { k: 'obs', l: 'Observações', t: 'textarea', full: 1 },
    ] },
};

const ENDPOINT = { acessos: '/acessos', mudancas: '/mudancas', backup: '/backup', restore: '/restore', dicionario: '/dicionario', integracoes: '/integracoes' };

const MODULOS_KEYS = Object.keys(ENDPOINT);
const MODULO_LABELS = { acessos: 'Acessos', mudancas: 'Mudanças', backup: 'Backup', restore: 'Restore', dicionario: 'Dicionário', integracoes: 'Integrações' };
const ROLE_LABEL = { admin: 'Administrador', escrita: 'Escrita', leitura: 'Leitura', master: 'Master' };
const ROLE_PILL = { admin: 'p-teal', escrita: 'p-amber', leitura: 'p-gray', master: 'p-violet' };

const PILL = {
  Ativo: 'p-green', Revogado: 'p-gray', Suspenso: 'p-amber',
  Produção: 'p-red', Homologação: 'p-amber', Desenvolvimento: 'p-teal',
  Planejada: 'p-amber', Aprovada: 'p-teal', Executada: 'p-green', Revertida: 'p-red', Cancelada: 'p-gray',
  Alta: 'p-red', Média: 'p-amber', Baixa: 'p-gray',
  OK: 'p-green', Falha: 'p-red', 'OK com ressalvas': 'p-amber',
  Pública: 'p-gray', Interna: 'p-teal', Confidencial: 'p-amber', 'Confidencial (PII)': 'p-red', Restrita: 'p-red',
  Crítica: 'p-red',
  Ativa: 'p-green', Inativa: 'p-gray', 'Em implantação': 'p-amber', 'A desativar': 'p-red', Desconhecida: 'p-gray',
  'Público': 'p-gray', 'Interno': 'p-teal', 'Pessoal / Sensível (LGPD)': 'p-red',
};
const HEXMAP = { 'p-green': '#2E7D52', 'p-amber': '#B5751F', 'p-red': '#B23B3B', 'p-gray': '#8A93A3', 'p-teal': '#B08D3E' };
const colorFor = (label) => HEXMAP[PILL[label]] || '#8A93A3';

const I18N = {
  en: {
    'Dashboard': 'Dashboard',
    'Acessos': 'Access', 'Mudanças': 'Changes', 'Dicionário': 'Dictionary', 'Relatórios': 'Reports',
    'Administração': 'Administration', 'Cadastro': 'Registry', 'Usuários': 'Users', 'E-mail': 'Email',
    'Auditoria': 'Audit', 'Trilha de quem fez o quê, quando e a partir de qual IP': 'Trail of who did what, when, and from which IP',
    'Anterior': 'Previous', 'Próxima': 'Next',
    'Documentação': 'Documentation',
    'Guia de Segurança': 'Security Guide',
    'Segurança': 'Security',
    'Desativar usuário': 'Disable user',
    'Reativar usuário': 'Reactivate user',
    'Desativado': 'Disabled',
    'Conta desativada com sucesso': 'Account successfully disabled',
    'Conta reativada com sucesso': 'Account successfully reactivated',
    'Por usuário': 'By user',
    'Por IP de origem': 'By source IP',
    'Monitoramento de acessos e checklist de segurança': 'Access monitoring and security checklist',
    'Tentativas de login recusadas': 'Refused login attempts',
    'Checklist de Segurança': 'Security Checklist',
    'Checklist de verificações periódicas para manter o ambiente seguro': 'Periodic security verification checklist',
    'Abrir checklist': 'Open checklist',
    'Baixando documentação...': 'Downloading documentation...', 'Erro ao baixar documentação': 'Error downloading documentation',
    'Nenhum indicador disponível': 'No indicator available', 'Seu perfil não tem leitura em nenhum módulo com indicadores.': 'Your profile has no read access to any module with indicators.',
    'Trocar senha': 'Change password', 'Sair': 'Log out', 'Editar meu perfil': 'Edit my profile',
    'Buscar...': 'Search...', 'Alternar tema claro/escuro': 'Toggle light/dark theme', 'Adicionar': 'Add',
    'Usuário': 'Username', 'Senha': 'Password', 'Entrar': 'Sign in', 'Entrando...': 'Signing in...',
    'Esqueceu sua senha? Fale com o administrador deste portal.': 'Forgot your password? Contact this portal\'s administrator.',
    'Cancelar': 'Cancel', 'Salvar registro': 'Save record', 'Novo registro': 'New record', 'Editar': 'Edit',
    'Salvar nova senha': 'Save new password', 'Senha atual': 'Current password', 'Nova senha': 'New password',
    'Novo usuário': 'New user', 'Login': 'Login', 'Nome completo': 'Full name',
    'Mínimo 8 caracteres, com letra e número.': 'At least 8 characters, with a letter and a number.',
    'Papel': 'Role', 'Leitura': 'Read', 'Escrita': 'Write', 'Administrador': 'Administrator',
    'Artefatos permitidos': 'Allowed records', 'Criar usuário': 'Create user',
    'Editar permissões': 'Edit permissions', 'Salvar permissões': 'Save permissions',
    'Enviar relatório por e-mail': 'Send report by email', 'E-mail de destino': 'Recipient email', 'Enviar': 'Send',
    'Resumo do seu ambiente de dados': 'Summary of your data environment',
    'Quem pode acessar este console': 'Who can access this console',
    'Roles': 'Roles', 'Papel e artefatos permitidos por usuário': 'Role and allowed records per user',
    'Exporte os dados de cada artefato': 'Export the data from each record type',
    'Listas de opções usadas em Mudanças e Backup': 'Option lists used in Changes and Backup',
    'Servidor SMTP usado para enviar relatórios': 'SMTP server used to send reports',
    'Meu perfil': 'My profile', 'Seus dados de acesso a este portal': 'Your access data for this portal',
    'Matriz de acessos': 'Access matrix', 'Quem tem acesso a quê, com justificativa e aprovador': 'Who has access to what, with justification and approver',
    'Registro de mudanças': 'Change log',
    'Política de backup': 'Backup policy', 'Regra de backup por banco': 'Backup rule per database',
    'Restore': 'Restore', 'Registro das recuperações testadas': 'Log of tested recoveries',
    'Dicionário de dados': 'Data dictionary', 'Significado, origem e classificação de cada coluna': 'Meaning, source and classification of each column',
    'Data da concessão': 'Grant date', 'Usuário / login': 'User / login', 'Tipo de conta': 'Account type',
    'Banco / objeto': 'Database / object', 'Nível de acesso': 'Access level', 'Justificativa': 'Justification',
    'Solicitante': 'Requester', 'Aprovador': 'Approver', 'Revisão prevista': 'Scheduled review', 'Status': 'Status',
    'Observações': 'Notes', 'ID': 'ID', 'Data': 'Date', 'Ambiente': 'Environment', 'Tipo': 'Type',
    'Descrição da mudança': 'Change description',
    'Plano de rollback': 'Rollback plan', 'Resultado / observações': 'Result / notes',
    'Banco': 'Database', 'Criticidade': 'Criticality', 'Tipo de backup': 'Backup type', 'Frequência': 'Frequency',
    'Horário': 'Time', 'Retenção': 'Retention', 'Local de armazenamento': 'Storage location', 'RPO alvo': 'Target RPO',
    'RTO alvo': 'Target RTO', 'Responsável': 'Owner', 'Data do teste': 'Test date', 'Backup testado': 'Backup tested',
    'Tempo de restore': 'Restore time', 'Resultado': 'Result', 'Testado por': 'Tested by', 'Schema': 'Schema',
    'Tabela': 'Table', 'Coluna': 'Column', 'Tipo de dado': 'Data type', 'Permite nulo?': 'Allows null?',
    'Descrição / significado': 'Description / meaning', 'Classificação': 'Classification', 'Origem / sistema': 'Source / system',
    'Ações': 'Actions', 'Exportar CSV': 'Export CSV', 'Nenhum registro encontrado': 'No record found',
    'Tente outro termo de busca': 'Try another search term', 'Clique em Adicionar para criar o primeiro.': 'Click Add to create the first one.',
    'Somente leitura neste artefato.': 'Read-only in this record type.',
    'Excluir este registro? Esta ação não pode ser desfeita.': 'Delete this record? This action cannot be undone.',
    'Excluir': 'Delete',
    'Acessos ativos': 'Active access', 'registros no total': 'records in total',
    'Acessos a revisar': 'Access to review', 'janela de revisão próxima': 'review window approaching',
    'Mudanças (30 dias)': 'Changes (30 days)', 'pendente(s) de execução': 'pending execution',
    'Último teste de restore': 'Last restore test', 'em ': 'on ', 'nenhum registrado': 'none recorded',
    'Sinais de atenção': 'Attention signals', 'Bancos sem teste de restore': 'Databases without restore test',
    'todos testados': 'all tested', 'Dados sensíveis catalogados': 'Sensitive data cataloged',
    'colunas confidenciais / PII': 'confidential / PII columns', 'Bancos com política': 'Databases with policy',
    'em Política de backup': 'in Backup policy',
    'Nome': 'Name', 'Criado em': 'Created on', 'Remover': 'Remove', 'Todos': 'All',
    'Nenhum usuário cadastrado': 'No users registered', 'Remover este usuário do portal?': 'Remove this user from the portal?',
    'Excluir este valor da lista?': 'Delete this value from the list?', 'Nenhum valor cadastrado': 'No value registered',
    'Novo valor': 'New value', 'Nenhuma opção encontrada': 'No option found',
    'Servidor (host)': 'Server (host)', 'Servidor': 'Server', 'Porta': 'Port', 'Segurança': 'Security', 'Nenhuma': 'None',
    'Nome do remetente': 'Sender name', 'E-mail do remetente': 'Sender email', 'Salvar': 'Save',
    'e-mail para teste': 'test email', 'Enviar teste': 'Send test',
    'Informe um e-mail para receber o teste': 'Enter an email to receive the test',
    'já configurada — deixe em branco para manter': 'already set — leave blank to keep',
    'Relatório completo': 'Full report', 'Todos os artefatos reunidos em um único arquivo JSON': 'All records combined into a single JSON file',
    'Exportar tudo (JSON)': 'Export all (JSON)', 'CSV': 'CSV', 'Política por banco': 'Policy by database',
    'Meus dados': 'My data',
    'Sua sessão expirou. Faça login novamente.': 'Your session has expired. Please sign in again.',
    'Entrando...': 'Signing in...', 'Não foi possível entrar.': 'Could not sign in.',

    'Enviamos um código de verificação para o seu e-mail. Ele expira em 10 minutos.': 'We sent a verification code to your email. It expires in 10 minutes.',
    'Código de verificação': 'Verification code', 'Verificar': 'Verify', 'Verificando...': 'Verifying...',
    'Reenviar código': 'Resend code', 'Não foi possível verificar o código.': 'Could not verify the code.',
    'Novo código enviado': 'New code sent',
    'Verificação em duas etapas': 'Two-factor verification', 'Confirmar': 'Confirm',
    'Exige um código enviado por e-mail a cada login.': 'Requires a code sent by email at every login.',
    'Ativada': 'Enabled', 'Desativada': 'Disabled', 'Ativar': 'Enable', 'Desativar': 'Disable',
    'Verificação em duas etapas ativada': 'Two-factor verification enabled',
    'Verificação em duas etapas desativada': 'Two-factor verification disabled',
    'Login (código enviado)': 'Login (code sent)', 'Código MFA inválido': 'Invalid MFA code',
    'Falha ao enviar código MFA': 'Failed to send MFA code', 'MFA sem e-mail cadastrado': 'MFA without registered email',
    'MFA ativado': 'MFA enabled', 'MFA desativado': 'MFA disabled',
    'Proteja sua conta': 'Protect your account',
    'Ative a verificação em duas etapas para aumentar a segurança do seu acesso.': 'Enable two-factor verification to increase your account security.',
    'Ativar agora': 'Enable now',
    'Peça a um administrador para configurar e testar o servidor de e-mail antes de ativar a verificação em duas etapas.': 'Ask an administrator to configure and test the email server before enabling two-factor verification.',

    // Valores de listas fixas (campos com f.o no SCHEMA) -- ver cellVal()/
    // buildForm() em js/app.js: só esses traduzem na tela, o valor salvo no
    // banco continua sempre em português.
    'Login SQL': 'SQL Login', 'Login Windows': 'Windows Login', 'Grupo AD': 'AD Group',
    'Customizado': 'Custom', 'Ativo': 'Active', 'Revogado': 'Revoked', 'Suspenso': 'Suspended',
    'Sim': 'Yes', 'Não': 'No', 'Pública': 'Public', 'Interna': 'Internal',
    'Confidencial': 'Confidential', 'Confidencial (PII)': 'Confidential (PII)', 'Restrita': 'Restricted',

    'Valor adicionado': 'Value added', 'Valor excluído': 'Value deleted', 'Valor atualizado': 'Value updated',
    'Informe um valor': 'Enter a value', 'Resultado (Restore)': 'Result (Restore)',

    'Configurações': 'Settings',
    'Nome e logo exibidos na tela de login e no menu lateral': 'Name and logo shown on the login screen and side menu',
    'Timeout de inatividade (minutos)': 'Inactivity timeout (minutes)',
    'Encerra a sessão automaticamente após este tempo sem uso (5 a 480 minutos).': 'Automatically ends the session after this much idle time (5 to 480 minutes).',
    'Sessão encerrada por inatividade. Faça login novamente.': 'Session ended due to inactivity. Please sign in again.',
    'PDF': 'PDF', 'Exportar PDF': 'Export PDF',
    'Identidade do projeto': 'Project identity', 'Nome do projeto': 'Project name', 'Gestão de Dados': 'Data Management',
    'Logo do projeto': 'Project logo', 'Logo padrão': 'Default logo', 'Enviar nova logo': 'Upload new logo',
    'Remover logo customizada': 'Remove custom logo',
    'PNG, JPG, SVG ou WEBP — no máximo ~2MB': 'PNG, JPG, SVG or WEBP — max ~2MB',
    'Remover a logo customizada e voltar pra logo padrão?': 'Remove the custom logo and go back to the default logo?',
    'Configurações do projeto salvas. Recarregando...': 'Project settings saved. Reloading...',
    'Logo removida. Recarregando...': 'Logo removed. Reloading...',
    'Imagem muito grande. Envie no máximo ~2MB.': 'Image too large. Send at most ~2MB.',

    'db_datareader': 'db_datareader', 'db_datawriter': 'db_datawriter', 'db_owner': 'db_owner',
    'db_ddladmin': 'db_ddladmin', 'db_securityadmin': 'db_securityadmin', 'sysadmin': 'sysadmin',
    'Outros': 'Other',

    'Chamado': 'Ticket', 'Objetos': 'Objects', 'Adicionar objeto': 'Add object',
    'Tipo': 'Type',
    'Função': 'Function',
    'Índice': 'Index',
    'Sinônimo': 'Synonym',
    'Sequência': 'Sequence', 'Nome do objeto': 'Object name',
    'Alterações em produção com aprovação e rollback': 'Production changes with approval and rollback',
    'O que cada papel pode fazer': 'What each role can do', 'Master': 'Master',
    'Visualiza os artefatos liberados, sem criar, editar ou excluir registros.': 'Views the allowed records, without creating, editing or deleting them.',
    'Cria, edita e exclui registros nos artefatos liberados para o usuário.': 'Creates, edits and deletes records in the records allowed for the user.',
    'Acesso total: todos os artefatos, usuários, cadastro, e-mail e configurações do projeto.': 'Full access: all records, users, registry, email and project settings.',
    'Tudo que o Administrador pode, além de ser o único perfil com acesso à trilha de auditoria.': 'Everything the Administrator can do, plus being the only role with access to the audit trail.',

    'Filtros': 'Filters', 'Colunas': 'Columns', 'Ordenar': 'Sort', 'Padrão': 'Default',
    'selecionado(s)': 'selected', 'Excluir selecionados': 'Delete selected',
    'Mostrando': 'Showing', 'de': 'of', 'Buscar...': 'Search...',
    'Excluir os registros selecionados? Esta ação não pode ser desfeita.': 'Delete the selected records? This action cannot be undone.',
    'Registros excluídos': 'Records deleted',
    'Atualizar observações em massa': 'Bulk-update notes', 'Atualizar observações': 'Update notes',
    'Aplicar a todos': 'Apply to all', 'Observações atualizadas': 'Notes updated',
    'registro(s) selecionado(s). O texto abaixo substituirá as observações de todos eles.': 'record(s) selected. The text below will replace the notes for all of them.',
    'Excluir os valores selecionados?': 'Delete the selected values?', 'Valores excluídos': 'Values deleted',
    'Nenhuma opção encontrada': 'No option found',
    'Baixe o modelo antes de importar': 'Download the template before importing',
    'Importando registros': 'Importing records', 'Excluindo registros': 'Deleting records',
    'Gerado em': 'Generated on', 'Período': 'Period', 'até': 'to', 'PDF exportado': 'PDF exported',
    'Excluir todos': 'Delete all', 'Excluir todos os': 'Delete all',
    'registros? Esta ação não pode ser desfeita.': 'records? This action cannot be undone.',
    'Importação concluída': 'Import complete', 'Importados': 'Imported', 'Não importados': 'Not imported',
    'Fechar': 'Close', 'Importar': 'Import',
    'Nenhum período definido — as exportações trarão todos os registros': 'No period set — exports will include all records',
    'Período aplicado às exportações': 'Period applied to exports',
    'Filtro de período removido': 'Period filter removed',
    'Itens no dicionário': 'Dictionary items', 'colunas catalogadas': 'columns cataloged',

    'Integrações de sistemas': 'System integrations',
    'Conexões, fluxos de dados e dependências entre sistemas': 'Connections, data flows and dependencies between systems',
    'Integrações': 'Integrations', 'Integração': 'Integration',
    'Sistema de origem': 'Source system', 'Sistema de destino': 'Target system',
    'IP de origem': 'Source IP', 'IP de destino': 'Destination IP',
    'Direção': 'Direction', 'Mecanismo': 'Mechanism',
    'Dados trafegados': 'Data transmitted', 'Classificação do dado': 'Data classification',
    'Responsável técnico': 'Technical owner', 'Responsável de negócio': 'Business owner',
    'Última revisão': 'Last review',
    'Entrada': 'Inbound', 'Saída': 'Outbound', 'Bidirecional': 'Bidirectional',
    'Tempo real': 'Real time', 'Cada minuto': 'Every minute', 'Horária': 'Hourly', 'Diária': 'Daily',
    'Semanal': 'Weekly', 'Mensal': 'Monthly', 'Sob demanda': 'On demand', 'Eventual': 'Occasional',
    'Público': 'Public', 'Interno': 'Internal', 'Pessoal / Sensível (LGPD)': 'Personal / sensitive (LGPD)',
    'Integrações cadastradas': 'Integrations registered', 'Críticas': 'Critical',
    'Dado pessoal / sensível': 'Personal / sensitive data', 'Sem responsável técnico': 'No technical owner',
    'Revisão vencida ou ausente': 'Review overdue or missing',
    'Integrações mapeadas': 'Integrations mapped', 'conexões entre sistemas': 'connections between systems',
    'Integrações críticas': 'Critical integrations', 'classificadas como Crítica': 'classified as Critical',
    'Integrações com dado sensível': 'Integrations with sensitive data', 'dado pessoal / LGPD': 'personal data / LGPD',
    'integrações sem dono definido': 'integrations without a defined owner',
    'sem revisão há mais de 1 ano': 'no review in over 1 year',
    'Descobrir integrações no banco': 'Discover integrations in the database',
    'Consultas prontas para encontrar conexões, jobs, replicação e aplicações conectadas na base de dados.': 'Ready-made queries to find connections, jobs, replication and connected applications in the database.',
    'Banco de dados atual': 'Current database',
    'Conexões entre bancos / servidores vinculados': 'Cross-database connections / linked servers',
    'Jobs agendados / ETL': 'Scheduled jobs / ETL',
    'Replicação': 'Replication',
    'Aplicações conectadas': 'Connected applications',
    'Copiar': 'Copy', 'Consulta copiada': 'Query copied', 'Não foi possível copiar': 'Could not copy',
  },
};

function tr(s) {
  if (lang !== 'en' || s == null) return s;
  return I18N.en[s] !== undefined ? I18N.en[s] : s;
}

const SING_EN = { acesso: 'access record', mudança: 'change', política: 'policy', teste: 'test', campo: 'field' };
function trNenhum(sing) {
  return lang === 'en' ? `No ${SING_EN[sing] || sing} registered yet` : `Nenhum ${sing} registrado ainda`;
}
function textoImportProgress(atual, total) {
  return lang === 'en' ? `Processing ${atual} of ${total} records...` : `Processando ${atual} de ${total} registros...`;
}

function applyStaticI18n() {
  document.documentElement.lang = lang === 'en' ? 'en-US' : 'pt-BR';
  document.querySelectorAll('[data-i18n]').forEach((el) => {
    if (el.dataset.i18nOrig === undefined) el.dataset.i18nOrig = el.textContent;
    el.textContent = tr(el.dataset.i18nOrig);
  });
  document.querySelectorAll('[data-i18n-ph]').forEach((el) => {
    if (el.dataset.i18nPhOrig === undefined) el.dataset.i18nPhOrig = el.getAttribute('placeholder') || '';
    el.setAttribute('placeholder', tr(el.dataset.i18nPhOrig));
  });
  document.querySelectorAll('[data-i18n-title]').forEach((el) => {
    if (el.dataset.i18nTitleOrig === undefined) el.dataset.i18nTitleOrig = el.getAttribute('title') || '';
    el.setAttribute('title', tr(el.dataset.i18nTitleOrig));
  });
  const lbl = $('langToggleLabel');
  if (lbl) lbl.textContent = lang === 'en' ? 'PT' : 'EN';
}

function toast(msg, isErr) {
  const t = $('toast');
  $('toastMsg').textContent = tr(msg);
  t.classList.toggle('err', !!isErr);
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2600);
}

const I = {
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
  refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>',
  db: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/></svg>',
  shield: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  palette: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 1 0 0 20c1.4 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.3-.5-.8-.5-1.2 0-1 .8-1.5 1.8-1.5h2A4.5 4.5 0 0 0 22 11.5C22 6 17.5 2 12 2z"/><circle cx="7.5" cy="10.5" r="1.3"/><circle cx="11" cy="6.8" r="1.3"/><circle cx="15.5" cy="7.5" r="1.3"/><circle cx="17.5" cy="11.8" r="1.3"/></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
  mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
  filter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>',
  columns: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>',
  sort: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 8 4-4 4 4"/><path d="M7 4v16"/><path d="m21 16-4 4-4-4"/><path d="M17 20V4"/></svg>',
  chevDown: '<svg class="dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>',
  chevLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>',
  chevRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>',
  upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
  help: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 9a3.5 3.5 0 1 1 5.6 2.8c-.9.7-1.6 1-1.6 2.5"/><line x1="12.5" y1="18.5" x2="12.5" y2="18.51"/></svg>',
  book: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
  exchange: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3l4 4-4 4"/><path d="M20 7H4"/><path d="M8 21l-4-4 4-4"/><path d="M4 17h16"/></svg>',
  copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
};

const TEMA_CORES = [
  { id: 'padrao', label: 'Padrão', cor: '#A9802E' },
  { id: 'azul', label: 'Azul', cor: '#2C5FA8' },
  { id: 'verde', label: 'Verde', cor: '#1E8A5C' },
  { id: 'violeta', label: 'Violeta', cor: '#7C4FBF' },
];

function aplicarTemaCor(cor) {
  const c = cor || 'padrao';
  document.documentElement.setAttribute('data-color', c);
  localStorage.setItem('colorTheme', c);
}

const SIDE_ESTILOS = [
  { id: 'claro', label: 'Claro', cor: '#FFFFFF' },
  { id: 'slate', label: 'Suave', cor: '#1E2530' },
  { id: 'grafite', label: 'Grafite', cor: '#2A2622' },
];

function aplicarEstiloSide(estilo) {
  const e = estilo || 'claro';
  document.documentElement.setAttribute('data-side', e);
  localStorage.setItem('sideStyle', e);
}

window.onSessionExpired = () => { pararMonitorInatividade(); showLogin(tr('Sua sessão expirou. Faça login novamente.')); };

// ---------------------------------------------------------------------------
// Timeout de inatividade -- logout automático no front-end (não altera o
// tempo de expiração do token JWT no backend, so encerra a sessão na tela
// se o usuário ficar parado por tempo demais).
// ---------------------------------------------------------------------------
let idleTimeoutMin = 30;
let ultimaAtividade = Date.now();
let idleMonitorInterval = null;
const EVENTOS_ATIVIDADE = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];

function registrarAtividade() { ultimaAtividade = Date.now(); }

function iniciarMonitorInatividade() {
  ultimaAtividade = Date.now();
  EVENTOS_ATIVIDADE.forEach((ev) => document.addEventListener(ev, registrarAtividade, { passive: true }));
  if (idleMonitorInterval) clearInterval(idleMonitorInterval);
  idleMonitorInterval = setInterval(() => {
    const minutosOcioso = (Date.now() - ultimaAtividade) / 60000;
    if (minutosOcioso >= idleTimeoutMin) logoutPorInatividade();
  }, 15000);
}

function pararMonitorInatividade() {
  if (idleMonitorInterval) { clearInterval(idleMonitorInterval); idleMonitorInterval = null; }
  EVENTOS_ATIVIDADE.forEach((ev) => document.removeEventListener(ev, registrarAtividade));
}

async function logoutPorInatividade() {
  pararMonitorInatividade();
  try { await api.logout(); } catch (e) { /* sessão pode já ter expirado no backend */ }
  currentUser = null;
  showLogin(tr('Sessão encerrada por inatividade. Faça login novamente.'));
}

async function carregarTimeoutInatividade() {
  try {
    const cfg = await api.get('/config/projeto');
    idleTimeoutMin = Number(cfg.timeout_inatividade_min) || 30;
  } catch (e) {
    idleTimeoutMin = 30;
  }
}

function showLogin(err) {
  $('appShell').style.display = 'none';
  $('loginScreen').style.display = 'flex';
  $('loginForm').style.display = '';
  $('mfaForm').style.display = 'none';
  mfaToken = null;
  if (err) { $('loginErr').textContent = err; $('loginErr').classList.add('show'); }
}

function showApp() {
  $('loginScreen').style.display = 'none';
  $('appShell').style.display = 'flex';
}

async function tryAutoLogin() {
  if (!getToken()) { showLogin(); return; }
  try {
    currentUser = await api.get('/auth/me');
    aplicarTemaCor(currentUser.cor_tema);
    aplicarEstiloSide(currentUser.estilo_side);
    afterLogin();
  } catch (e) {
    showLogin();
  }
}

function permittedModules() {
  if (!currentUser) return [];
  if (currentUser.role === 'admin' || currentUser.role === 'master') return MODULOS_KEYS.slice();
  return String(currentUser.modulos_permitidos || '').split(',').map((s) => s.trim()).filter(Boolean);
}

function canWrite(moduleKey) {
  if (!currentUser) return false;
  if (currentUser.role === 'admin' || currentUser.role === 'master') return true;
  if (currentUser.role !== 'escrita') return false;
  return permittedModules().includes(moduleKey);
}

// "Excluir todos" apaga de uma vez todo o conjunto filtrado de uma tabela,
// sem selecao -- risco alto demais pra liberar pra qualquer perfil com
// permissao de escrita (ex.: "escrita" comum). Restrito a admin/master.
function isAdminOuMaster() {
  return !!currentUser && (currentUser.role === 'admin' || currentUser.role === 'master');
}

function isMaster() {
  return !!currentUser && currentUser.role === 'master';
}

// Modulos onde "Excluir todos" exige o perfil Master (acima de admin) --
// hoje so o dicionario, que costuma vir de importacoes com milhares de
// linhas e e o caso real de "limpar a tabela inteira". O backend tambem
// faz essa trava (ver rota DELETE /{modulo}/lote em backend/api.php) --
// isto aqui e so pra esconder o botao de quem nao pode usar.
const EXCLUIR_TODOS_SOMENTE_MASTER = ['dicionario'];

function podeExcluirTodos(key) {
  return EXCLUIR_TODOS_SOMENTE_MASTER.includes(key) ? isMaster() : isAdminOuMaster();
}

function canRead(moduleKey) {
  if (!currentUser) return false;
  if (currentUser.role === 'admin' || currentUser.role === 'master') return true;
  return permittedModules().includes(moduleKey);
}

function afterLogin() {
  showApp();
  $('userName').textContent = currentUser.nome_completo || currentUser.username;
  $('userAvatar').textContent = (currentUser.nome_completo || currentUser.username).slice(0, 1).toUpperCase();
  const isAdmin = currentUser.role === 'admin' || currentUser.role === 'master';
  const isMaster = currentUser.role === 'master';
  $('navUsuarios').style.display = isAdmin ? '' : 'none';
  $('navRoles').style.display = isAdmin ? '' : 'none';
  $('navCadastro').style.display = isAdmin ? '' : 'none';
  $('navEmail').style.display = isAdmin ? '' : 'none';
  $('navConfigProjeto').style.display = isAdmin ? '' : 'none';
  $('navAuditoria').style.display = isMaster ? '' : 'none';
  $('navSeguranca').style.display = isAdmin ? '' : 'none';
  $('navAdminLabel').style.display = isAdmin ? '' : 'none';
  document.querySelectorAll('.nav-item[data-view]').forEach((b) => {
    const v = b.dataset.view;
    if (MODULOS_KEYS.includes(v)) b.style.display = canRead(v) ? '' : 'none';
  });
  fetchTipos();
  atualizarMfaBanner();
  iniciarMonitorInatividade();
  carregarTimeoutInatividade();
  carregarDbInfo();
  navTo('overview');
}

/** Mostra o aviso de "ative o MFA" pra quem ainda não ativou; some sozinho ao ativar. */
function atualizarMfaBanner() {
  const banner = $('mfaBanner');
  if (!banner || !currentUser) return;
  banner.hidden = !!(currentUser.mfa_ativo == 1);
}

$('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('loginErr').classList.remove('show');
  const btn = $('loginBtn');
  btn.disabled = true;
  btn.innerHTML = `<span class="spinner"></span> ${esc(tr('Entrando...'))}`;
  try {
    const resp = await api.login($('loginUser').value.trim(), $('loginPass').value);
    if (resp.mfa_required) {
      mfaToken = resp.mfa_token;
      $('loginForm').style.display = 'none';
      $('mfaForm').style.display = '';
      $('mfaCodigo').value = '';
      $('mfaCodigo').focus();
      return;
    }
    setToken(resp.access_token);
    currentUser = await api.get('/auth/me');
    afterLogin();
  } catch (err) {
    $('loginErr').textContent = err.message || tr('Não foi possível entrar.');
    $('loginErr').classList.add('show');
  } finally {
    btn.disabled = false;
    btn.textContent = tr('Entrar');
  }
});

$('mfaForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('loginErr').classList.remove('show');
  const btn = $('mfaBtn');
  btn.disabled = true;
  btn.innerHTML = `<span class="spinner"></span> ${esc(tr('Verificando...'))}`;
  try {
    const resp = await api.post('/auth/mfa/verificar', { mfa_token: mfaToken, codigo: $('mfaCodigo').value.trim() });
    setToken(resp.access_token);
    mfaToken = null;
    currentUser = await api.get('/auth/me');
    afterLogin();
  } catch (err) {
    $('loginErr').textContent = err.message || tr('Não foi possível verificar o código.');
    $('loginErr').classList.add('show');
  } finally {
    btn.disabled = false;
    btn.textContent = tr('Verificar');
  }
});

$('mfaReenviarBtn').addEventListener('click', async () => {
  const btn = $('mfaReenviarBtn');
  btn.disabled = true;
  try {
    const resp = await api.post('/auth/mfa/reenviar', { mfa_token: mfaToken });
    mfaToken = resp.mfa_token;
    toast('Novo código enviado');
  } catch (err) {
    toast(err.message, true);
  } finally {
    btn.disabled = false;
  }
});

let mfaToggleAtivar = false; // true = vai ativar, false = vai desativar -- estado da confirmação aberta

async function abrirMfaToggle(ativar) {
  if (ativar) {
    // Não deixa nem abrir a confirmação se o serviço de e-mail não estiver
    // configurado/testado -- evita o usuário digitar a senha e só então
    // descobrir que vai travar o próprio login (a API bloqueia de qualquer
    // forma, isso aqui é só pra dar o aviso antes).
    try {
      const status = await api.get('/auth/mfa/email-status');
      if (!status.pronto) {
        toast(tr('Peça a um administrador para configurar e testar o servidor de e-mail antes de ativar a verificação em duas etapas.'), true);
        return;
      }
    } catch (e) {
      toast(e.message, true);
      return;
    }
  }
  mfaToggleAtivar = ativar;
  $('mfaTogglePass').value = '';
  $('mfaToggleOverlay').classList.add('show');
}
function closeMfaToggle() {
  $('mfaToggleOverlay').classList.remove('show');
  $('mfaTogglePass').value = '';
}
$('mfaToggleClose').addEventListener('click', closeMfaToggle);
$('mfaToggleCancel').addEventListener('click', closeMfaToggle);
$('mfaToggleSave').addEventListener('click', async () => {
  const btn = $('mfaToggleSave');
  btn.disabled = true;
  try {
    currentUser = await api.put('/auth/mfa', { ativo: mfaToggleAtivar, senha_atual: $('mfaTogglePass').value });
    closeMfaToggle();
    toast(mfaToggleAtivar ? 'Verificação em duas etapas ativada' : 'Verificação em duas etapas desativada');
    renderPerfil();
    atualizarMfaBanner();
  } catch (e) {
    toast(e.message, true);
  } finally {
    btn.disabled = false;
  }
});

$('logoutBtn').addEventListener('click', async () => {
  pararMonitorInatividade();
  await api.logout();
  currentUser = null;
  $('loginUser').value = ''; $('loginPass').value = '';
  showLogin();
});

$('changePassBtn').addEventListener('click', () => { $('pwOverlay').classList.add('show'); });
function closePw() { $('pwOverlay').classList.remove('show'); $('pwAtual').value = ''; $('pwNova').value = ''; }
$('pwClose').addEventListener('click', closePw);
$('pwCancel').addEventListener('click', closePw);
$('pwSave').addEventListener('click', async () => {
  try {
    await api.post('/auth/change-password', { senha_atual: $('pwAtual').value, nova_senha: $('pwNova').value });
    toast('Senha atualizada');
    closePw();
  } catch (e) { toast(e.message, true); }
});

function showSkeleton() {
  $('content').innerHTML = '<div class="card"><div class="skeleton">' + Array(5).fill('<div class="bar" style="width:' + (60 + Math.random() * 35) + '%"></div>').join('') + '</div></div>';
}

async function navTo(v) {
  view = v; query = ''; $('search').value = '';
  document.querySelectorAll('.nav-item').forEach((b) => b.classList.toggle('active', b.dataset.view === v));
  $('side').classList.remove('open');

  if (v === 'overview') {
    $('viewTitle').textContent = tr('Dashboard'); $('viewSub').textContent = tr('Resumo do seu ambiente de dados');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    showSkeleton();
    await renderOverview();
  } else if (v === 'usuarios') {
    $('viewTitle').textContent = tr('Usuários'); $('viewSub').textContent = tr('Quem pode acessar este console');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = '';
    showSkeleton();
    await renderUsuarios();
  } else if (v === 'roles') {
    $('viewTitle').textContent = tr('Roles'); $('viewSub').textContent = tr('Papel e artefatos permitidos por usuário');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    showSkeleton();
    await renderRoles();
  } else if (v === 'relatorios') {
    $('viewTitle').textContent = tr('Relatórios'); $('viewSub').textContent = tr('Exporte os dados de cada artefato');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    renderRelatorios();
  } else if (v === 'documentacao') {
    $('viewTitle').textContent = tr('Documentação'); $('viewSub').textContent = tr('Manual de uso e esquema do banco de dados do portal');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    renderDocumentacao();
  } else if (v === 'cadastro') {
    $('viewTitle').textContent = tr('Cadastro'); $('viewSub').textContent = tr('Listas de opções usadas em Mudanças e Backup');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    showSkeleton();
    await renderCadastro();
  } else if (v === 'email') {
    $('viewTitle').textContent = tr('E-mail'); $('viewSub').textContent = tr('Servidor SMTP usado para enviar relatórios');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    showSkeleton();
    await renderEmailConfig();
  } else if (v === 'configProjeto') {
    $('viewTitle').textContent = tr('Configurações'); $('viewSub').textContent = tr('Nome e logo exibidos na tela de login e no menu lateral');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    showSkeleton();
    await renderConfigProjeto();
  } else if (v === 'perfil') {
    $('viewTitle').textContent = tr('Meu perfil'); $('viewSub').textContent = tr('Seus dados de acesso a este portal');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    renderPerfil();
  } else if (v === 'auditoria') {
    $('viewTitle').textContent = tr('Auditoria'); $('viewSub').textContent = tr('Trilha de quem fez o quê, quando e a partir de qual IP');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    showSkeleton();
    await renderAuditoria();
  } else if (v === 'seguranca') {
    $('viewTitle').textContent = tr('Segurança'); $('viewSub').textContent = tr('Monitoramento de acessos e checklist de segurança');
    $('searchBox').style.display = 'none'; $('addBtn').style.display = 'none';
    showSkeleton();
    await renderSeguranca();
  } else if (v === 'backup') {
    $('viewTitle').textContent = tr(SCHEMA.backup.title); $('viewSub').textContent = tr(SCHEMA.backup.sub);
    $('searchBox').style.display = ''; $('addBtn').style.display = canWrite('backup') ? '' : 'none';
    showSkeleton();
    await renderBackup();
  } else {
    $('viewTitle').textContent = tr(SCHEMA[v].title); $('viewSub').textContent = tr(SCHEMA[v].sub);
    $('searchBox').style.display = ''; $('addBtn').style.display = canWrite(v) ? '' : 'none';
    showSkeleton();
    await renderTable(v);
  }
}

function tile(lab, val, hint, cls, icon) {
  return `<div class="tile ${cls || ''}"><div class="tile-ico">${icon}</div><div class="lab">${lab}</div><div class="val">${val}</div><div class="hint">${hint || ''}</div></div>`;
}

async function renderOverview() {
  let s;
  try {
    s = await api.get('/dashboard/stats');
  } catch (e) {
    $('content').innerHTML = `<div class="card"><div class="empty"><p>Não foi possível carregar os números</p><span>${esc(e.message)}</span></div></div>`;
    return;
  }

  const semTesteTxt = s.bancos_sem_teste.length ? s.bancos_sem_teste.slice(0, 3).join(', ') + (s.bancos_sem_teste.length > 3 ? '…' : '') : tr('todos testados');

  // Cada tile só aparece se o perfil logado tem leitura no módulo correspondente
  // (mesma regra usada pra esconder itens do menu e do Relatórios) -- assim um
  // usuário restrito ao dicionário, por exemplo, só vê o que é dele aqui.
  let principais = '';
  if (canRead('acessos')) principais += tile(tr('Acessos ativos'), s.acessos_ativos, s.acessos_total + ' ' + tr('registros no total'), '', I.users);
  if (canRead('acessos')) principais += tile(tr('Acessos a revisar'), s.acessos_a_revisar, tr('janela de revisão próxima'), s.acessos_a_revisar > 0 ? 'attn' : '', I.refresh);
  if (canRead('mudancas')) principais += tile(tr('Mudanças (30 dias)'), s.mudancas_30_dias, s.mudancas_pendentes + ' ' + tr('pendente(s) de execução'), '', I.clock);
  if (canRead('restore')) principais += tile(tr('Último teste de restore'), s.ultimo_restore ? s.dias_desde_ultimo_restore + 'd' : '—', s.ultimo_restore ? tr('em ') + fmtDate(s.ultimo_restore) : tr('nenhum registrado'), (s.ultimo_restore === null || s.dias_desde_ultimo_restore > 30) ? 'attn' : 'ok', I.db);
  if (canRead('dicionario')) principais += tile(tr('Itens no dicionário'), s.totais.dicionario, tr('colunas catalogadas'), '', I.book);
  if (canRead('integracoes')) principais += tile(tr('Integrações mapeadas'), s.integracoes_total, tr('conexões entre sistemas'), '', I.exchange);

  let atencao = '';
  if (canRead('backup') && canRead('restore')) atencao += tile(tr('Bancos sem teste de restore'), s.bancos_sem_teste.length, semTesteTxt, s.bancos_sem_teste.length > 0 ? 'attn' : 'ok', I.alert);
  if (canRead('dicionario')) atencao += tile(tr('Dados sensíveis catalogados'), s.dados_sensiveis, tr('colunas confidenciais / PII'), '', I.shield);
  if (canRead('backup')) atencao += tile(tr('Bancos com política'), s.bancos_com_politica, tr('em Política de backup'), '', I.db);
  if (canRead('integracoes')) atencao += tile(tr('Integrações críticas'), s.integracoes_criticas, tr('classificadas como Crítica'), s.integracoes_criticas > 0 ? 'attn' : 'ok', I.alert);
  if (canRead('integracoes')) atencao += tile(tr('Integrações com dado sensível'), s.integracoes_sensiveis, tr('dado pessoal / LGPD'), s.integracoes_sensiveis > 0 ? 'attn' : '', I.shield);
  if (canRead('integracoes')) atencao += tile(tr('Sem responsável técnico'), s.integracoes_sem_responsavel, tr('integrações sem dono definido'), s.integracoes_sem_responsavel > 0 ? 'attn' : 'ok', I.users);
  if (canRead('integracoes')) atencao += tile(tr('Revisão vencida ou ausente'), s.integracoes_revisao_vencida, tr('sem revisão há mais de 1 ano'), s.integracoes_revisao_vencida > 0 ? 'attn' : 'ok', I.clock);

  let h = '';
  if (principais) h += `<div class="tiles">${principais}</div>`;
  if (atencao) h += `<div class="sec-h">${esc(tr('Sinais de atenção'))}</div><div class="tiles">${atencao}</div>`;
  if (!principais && !atencao) {
    h = `<div class="card"><div class="empty">${I.db}<p>${esc(tr('Nenhum indicador disponível'))}</p><span>${esc(tr('Seu perfil não tem leitura em nenhum módulo com indicadores.'))}</span></div></div>`;
  }

  $('content').innerHTML = h;
}

function cellVal(f, r) {
  const raw = r[f.k];
  if (f.t === 'objetos') {
    if (!Array.isArray(raw) || !raw.length) return '—';
    return esc(raw.map((o) => o.nome + (o.tipo ? ` (${o.tipo})` : '')).join(', '));
  }
  let v = raw;
  if (f.t === 'date') v = fmtDate(v);
  // Só traduz valores de listas FIXAS (f.o: Sim/Não, tipo de conta, etc.)
  // -- valores do Cadastro (f.cat) são digitados pelo próprio admin e nunca
  // são traduzidos automaticamente, senão um valor customizado viraria outra
  // palavra ao trocar de idioma.
  // Alguns campos f.o guardam mais de um valor numa string só, separados
  // por vírgula -- traduz cada um individualmente, senão a string inteira
  // não bate com nenhuma chave do I18N e fica sem traduzir.
  if (f.o) v = v == null ? v : String(v).split(',').map((p) => tr(p.trim())).join(', ');
  if (f.pill) return raw ? `<span class="pill ${PILL[raw] || 'p-gray'}">${esc(v)}</span>` : '';
  return esc(v);
}

async function fetchList(key, q) {
  const path = ENDPOINT[key] + (q ? '?q=' + encodeURIComponent(q) : '');
  cache[key] = await api.get(path);
  return cache[key];
}

function tblApply(key, cols, rows) {
  const st = tblStateFor(key);
  let out = rows;
  if (st.filterCol && st.filterVal) out = out.filter((r) => r[st.filterCol] === st.filterVal);
  if (st.sortCol) {
    out = [...out].sort((a, b) => {
      let va = a[st.sortCol], vb = b[st.sortCol];
      va = va == null ? '' : va; vb = vb == null ? '' : vb;
      const cmp = String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' });
      return st.sortDir === 'desc' ? -cmp : cmp;
    });
  }
  return out;
}

/** Botoes "Filtros / Colunas / Ordenar" (ou a barra de selecao em massa,
 * quando ha linhas marcadas) acima da tabela. */
function tblToolbarHtml(key, cols, pillCol, pillOpts, podeEscrever, total) {
  const st = tblStateFor(key);
  if (st.sel.size > 0) {
    const temObs = !!(SCHEMA[key] && SCHEMA[key].fields.some((f) => f.k === 'obs'));
    return `<div class="tbl-toolbar tbl-toolbar-sel">
      <span class="tbl-sel-info">${st.sel.size} ${esc(tr('selecionado(s)'))}</span>
      <div class="tbl-toolbar-actions">
        <button type="button" class="btn btn-ghost" data-act="tblClearSel" data-key="${key}">${esc(tr('Cancelar'))}</button>
        ${temObs ? `<button type="button" class="btn btn-ghost" data-act="openBulkObs" data-key="${key}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>${esc(tr('Atualizar observações'))}</button>` : ''}
        <button type="button" class="btn btn-red" data-act="tblBulkDelete" data-key="${key}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>${esc(tr('Excluir selecionados'))}</button>
      </div>
    </div>`;
  }
  let h = '<div class="tbl-toolbar"><span class="tbl-toolbar-info"></span><div class="tbl-toolbar-actions">';
  if (pillCol) {
    const active = st.filterVal;
    h += `<div class="dd"><button type="button" class="btn btn-ghost dd-btn${active ? ' has-value' : ''}" data-act="tblToggleDd">${I.filter}${esc(tr('Filtros'))}${I.chevDown}</button><div class="dd-panel">
      <button type="button" class="dd-opt${!active ? ' sel' : ''}" data-act="tblSetFilter" data-key="${key}" data-col="${pillCol.k}" data-val="">${esc(tr('Todos'))}</button>
      ${pillOpts.map((v) => `<button type="button" class="dd-opt${active === v ? ' sel' : ''}" data-act="tblSetFilter" data-key="${key}" data-col="${pillCol.k}" data-val="${esc(v)}">${esc(tr(v))}</button>`).join('')}
    </div></div>`;
  }
  h += `<div class="dd"><button type="button" class="btn btn-ghost dd-btn${st.hidden.size ? ' has-value' : ''}" data-act="tblToggleDd">${I.columns}${esc(tr('Colunas'))}${I.chevDown}</button><div class="dd-panel">
    ${cols.map((f) => `<label class="dd-chk"><input type="checkbox" data-act="tblToggleCol" data-key="${key}" data-col="${f.k}" ${st.hidden.has(f.k) ? '' : 'checked'}> ${esc(tr(f.l))}</label>`).join('')}
  </div></div>`;
  h += `<div class="dd"><button type="button" class="btn btn-ghost dd-btn${st.sortCol ? ' has-value' : ''}" data-act="tblToggleDd">${I.sort}${esc(tr('Ordenar'))}${I.chevDown}</button><div class="dd-panel">
    <button type="button" class="dd-opt${!st.sortCol ? ' sel' : ''}" data-act="tblSetSort" data-key="${key}" data-col="">${esc(tr('Padrão'))}</button>
    ${cols.map((f) => `<button type="button" class="dd-opt${st.sortCol === f.k ? ' sel' : ''}" data-act="tblSetSort" data-key="${key}" data-col="${f.k}">${esc(tr(f.l))}${st.sortCol === f.k ? (st.sortDir === 'asc' ? ' ↑' : ' ↓') : ''}</button>`).join('')}
  </div></div>`;
  if (podeEscrever && total > 0 && podeExcluirTodos(key)) {
    h += `<button type="button" class="btn btn-red" data-act="tblExcluirTodos" data-key="${key}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>${esc(tr('Excluir todos'))}</button>`;
  }
  h += '</div></div>';
  return h;
}

/** Rodape "Mostrando X-Y de Z" com os botoes de pagina. */
function tblPaginationHtml(key, total, page, pages) {
  const start = total === 0 ? 0 : (page - 1) * PAGE_SIZE + 1;
  const end = Math.min(page * PAGE_SIZE, total);
  let btns = `<button type="button" class="pg-btn" data-act="tblGoPage" data-key="${key}" data-pg="${page - 1}" ${page <= 1 ? 'disabled' : ''}>${I.chevLeft}</button>`;
  const maxBtns = 5;
  let lo = Math.max(1, page - 2);
  let hi = Math.min(pages, lo + maxBtns - 1);
  lo = Math.max(1, hi - maxBtns + 1);
  for (let p = lo; p <= hi; p++) {
    btns += `<button type="button" class="pg-btn${p === page ? ' active' : ''}" data-act="tblGoPage" data-key="${key}" data-pg="${p}">${p}</button>`;
  }
  btns += `<button type="button" class="pg-btn" data-act="tblGoPage" data-key="${key}" data-pg="${page + 1}" ${page >= pages ? 'disabled' : ''}>${I.chevRight}</button>`;
  return `<div class="tbl-pagination">
    <span class="tbl-pg-info">${esc(tr('Mostrando'))} ${start}–${end} ${esc(tr('de'))} ${total}</span>
    <div class="tbl-pg-btns">${btns}</div>
  </div>`;
}

function tableHtml(key, cols, rows, sing, searching) {
  const podeEscrever = canWrite(key);
  if (rows.length === 0) {
    return `<div class="card"><div class="empty">${I.db}<p>${searching ? tr('Nenhum registro encontrado') : trNenhum(sing)}</p><span>${searching ? tr('Tente outro termo de busca') : (podeEscrever ? tr('Clique em Adicionar para criar o primeiro.') : tr('Somente leitura neste artefato.'))}</span></div></div>`;
  }
  const st = tblStateFor(key);
  const pillCol = cols.find((f) => f.pill);
  const pillOpts = pillCol ? [...new Set(rows.map((r) => r[pillCol.k]).filter(Boolean))] : [];
  const applied = tblApply(key, cols, rows);
  const pages = Math.max(1, Math.ceil(applied.length / PAGE_SIZE));
  if (st.page > pages) st.page = pages;
  if (st.page < 1) st.page = 1;
  const pageRows = applied.slice((st.page - 1) * PAGE_SIZE, st.page * PAGE_SIZE);
  const allSel = pageRows.length > 0 && pageRows.every((r) => st.sel.has(String(r.id)));

  let h = `<div class="card" id="tblCard-${key}">`;
  h += tblToolbarHtml(key, cols, pillCol, pillOpts, podeEscrever, applied.length);
  h += '<div class="tbl-wrap"><table><thead><tr>';
  if (podeEscrever) h += `<th class="th-check"><input type="checkbox" data-act="tblToggleAll" data-key="${key}" data-ids='${JSON.stringify(pageRows.map((r) => r.id))}' ${allSel ? 'checked' : ''}></th>`;
  cols.forEach((f) => { h += `<th data-col="${f.k}" style="${st.hidden.has(f.k) ? 'display:none' : ''}">${esc(tr(f.l))}</th>`; });
  h += (podeEscrever ? `<th style="text-align:right">${esc(tr('Ações'))}</th>` : '') + '</tr></thead><tbody>';
  if (!pageRows.length) h += `<tr><td colspan="${cols.length + (podeEscrever ? 2 : 1)}" style="text-align:center;color:var(--muted);padding:26px">${esc(tr('Nenhum registro encontrado'))}</td></tr>`;
  pageRows.forEach((r) => {
    h += `<tr class="${st.sel.has(String(r.id)) ? 'row-sel' : ''}">`;
    if (podeEscrever) h += `<td class="td-check"><input type="checkbox" data-act="tblToggleRow" data-key="${key}" data-id="${r.id}" ${st.sel.has(String(r.id)) ? 'checked' : ''}></td>`;
    cols.forEach((f) => {
      const cls = (f.mono ? 'mono ' : '') + (f.trunc ? 'trunc' : '');
      const _tRaw = f.t === 'objetos' && Array.isArray(r[f.k])
        ? r[f.k].map((o) => o.nome + (o.tipo ? ` (${o.tipo})` : '')).join(', ')
        : String(r[f.k] ?? '');
      const title = f.trunc ? ` title="${esc(_tRaw)}"` : '';
      h += `<td class="${cls.trim()}" data-col="${f.k}" style="${st.hidden.has(f.k) ? 'display:none' : ''}"${title}>${cellVal(f, r)}</td>`;
    });
    if (podeEscrever) {
      h += `<td><div class="row-act" style="justify-content:flex-end">
        <button class="icon-btn" data-act="openEdit" data-key="${key}" data-id="${r.id}" title="Editar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg></button>
        <button class="icon-btn del" data-act="delRow" data-key="${key}" data-id="${r.id}" title="Excluir"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
      </div></td>`;
    }
    h += '</tr>';
  });
  h += '</tbody></table></div>';
  h += tblPaginationHtml(key, applied.length, st.page, pages);
  h += '</div>';
  return h;
}

// ---------------------------------------------------------------------------
// Painel de descoberta de integracoes -- consultas prontas para achar
// conexoes entre bancos, jobs/ETL, replicacao e aplicacoes conectadas.
// So mostra o conjunto que bate com o motor de banco configurado neste
// portal (MySQL / PostgreSQL / SQL Server / SQLite), descoberto via
// GET /dashboard/info (rota publica, ja usada na tela de login).
// ---------------------------------------------------------------------------
const DISCOVERY = {
  'MySQL': [
    { tit: 'Conexões entre bancos / servidores vinculados', sql: "SELECT Server_name, Host, Db, Username, Port\nFROM mysql.servers;" },
    { tit: 'Jobs agendados / ETL', sql: "SELECT EVENT_NAME, EVENT_DEFINITION, INTERVAL_VALUE, INTERVAL_FIELD, STATUS\nFROM information_schema.EVENTS;" },
    { tit: 'Replicação', sql: "SHOW REPLICAS;\n-- versoes mais antigas: SHOW SLAVE HOSTS;\nSELECT * FROM performance_schema.replication_connection_status;" },
    { tit: 'Aplicações conectadas', sql: "SELECT DISTINCT user, host, db, command\nFROM information_schema.processlist\nORDER BY user;" },
  ],
  'PostgreSQL': [
    { tit: 'Conexões entre bancos / servidores vinculados', sql: "SELECT srvname, fdwname, srvoptions\nFROM pg_foreign_server fs\nJOIN pg_foreign_data_wrapper fdw ON fs.srvfdw = fdw.oid;" },
    { tit: 'Jobs agendados / ETL', sql: "-- requer a extensao pg_cron\nSELECT jobid, schedule, command, nodename\nFROM cron.job;" },
    { tit: 'Replicação', sql: "SELECT client_addr, usename, application_name, state, sync_state\nFROM pg_stat_replication;" },
    { tit: 'Aplicações conectadas', sql: "SELECT DISTINCT usename, application_name, client_addr\nFROM pg_stat_activity\nWHERE application_name <> ''\nORDER BY application_name;" },
  ],
  'SQL Server': [
    { tit: 'Conexões entre bancos / servidores vinculados', sql: "SELECT name, product, provider, data_source, modify_date\nFROM sys.servers\nWHERE is_linked = 1;" },
    { tit: 'Jobs agendados / ETL', sql: "SELECT j.name AS job_name, j.enabled,\n       SUBSTRING(st.command, 1, 200) AS comando\nFROM msdb.dbo.sysjobs j\nJOIN msdb.dbo.sysjobsteps st ON st.job_id = j.job_id\nORDER BY j.name, st.step_id;" },
    { tit: 'Replicação', sql: "SELECT name, is_published, is_subscribed, is_distributor\nFROM sys.databases\nWHERE is_published = 1 OR is_subscribed = 1 OR is_distributor = 1;" },
    { tit: 'Aplicações conectadas', sql: "SELECT DISTINCT login_name, program_name, host_name\nFROM sys.dm_exec_sessions\nWHERE is_user_process = 1\nORDER BY program_name;" },
  ],
  'SQLite': [
    { tit: 'Conexões entre bancos / servidores vinculados', sql: "-- SQLite nao tem servidor vinculado; bancos extras entram via ATTACH\nPRAGMA database_list;" },
    { tit: 'Jobs agendados / ETL', sql: "-- SQLite nao tem agendador interno.\n-- Procure no cron / Agendador de Tarefas do SO por scripts que abrem este arquivo .db." },
    { tit: 'Replicação', sql: "-- SQLite nao tem replicacao nativa.\n-- Se houver, costuma ser por ferramenta externa (ex.: Litestream) -- confira a configuracao dela." },
    { tit: 'Aplicações conectadas', sql: "-- SQLite nao mantem sessoes; o arquivo e acessado direto pelo processo.\n-- No SO: lsof | grep nome_do_banco.db  (Linux/Mac)" },
  ],
};

let motorBancoCache = null;
async function getMotorBanco() {
  if (motorBancoCache) return motorBancoCache;
  try {
    const info = await api.get('/dashboard/info');
    motorBancoCache = info.motor_banco;
  } catch (e) {
    motorBancoCache = null;
  }
  return motorBancoCache;
}

function discoveryHtml(motorAtual) {
  const motores = Object.keys(DISCOVERY);
  if (!motores.length) return '';
  const secoes = motores.map((m) => {
    const blocos = DISCOVERY[m];
    const ativo = m === motorAtual;
    return `<div class="disco-motor-sec">
      <div class="disco-motor-head">
        <span class="mono">${esc(m)}</span>
        ${ativo ? `<span class="disco-motor-badge">${esc(tr('Conectado'))}</span>` : ''}
      </div>
      <div class="disco-grid">
        ${blocos.map((b, i) => `<div class="disco-block">
          <h4>${esc(tr(b.tit))}</h4>
          <pre class="disco-pre">${esc(b.sql)}</pre>
          <button type="button" class="btn btn-ghost btn-sm" data-act="copiarConsultaDiscovery" data-motor="${esc(m)}" data-idx="${i}">${I.copy}${esc(tr('Copiar'))}</button>
        </div>`).join('')}
      </div>
    </div>`;
  }).join('');
  return `<details class="disco-panel">
    <summary>${I.exchange}<span class="disco-sum-txt">${esc(tr('Descobrir integrações no banco'))}</span>${I.chevDown}</summary>
    <div class="disco-body">
      <p class="disco-desc">${esc(tr('Consultas prontas para encontrar conexões, jobs, replicação e aplicações conectadas na base de dados.'))}</p>
      ${secoes}
    </div>
  </details>`;
}

function copiarConsultaDiscovery(el) {
  const motor = el.dataset.motor || '';
  const idx = Number(el.dataset.idx);
  const sql = ((DISCOVERY[motor] || [])[idx] || {}).sql || '';
  navigator.clipboard.writeText(sql)
    .then(() => toast('Consulta copiada'))
    .catch(() => toast('Não foi possível copiar', true));
}

async function renderTable(key) {
  const sch = SCHEMA[key];
  const cols = sch.fields.filter((f) => f.table);
  const st = tblStateFor(key);
  st.page = 1; st.sel.clear();
  let rows;
  try { rows = await fetchList(key, query); } catch (e) { $('content').innerHTML = `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`; return; }
  let h = tableHtml(key, cols, rows, sch.singular, !!query);
  let foot = '';
  if (key === 'dicionario' && canWrite(key)) {
    foot += `<button type="button" class="btn btn-ghost icon-only tt-wrap" data-act="dicTemplateCsv" data-tt="${esc(tr('Baixe o modelo antes de importar'))}" aria-label="${esc(tr('Baixar modelo de importação (CSV)'))}">${I.help}</button>`;
    foot += `<button type="button" class="btn btn-ghost" data-act="dicImportarClick">${I.upload}${esc(tr('Importar'))}</button>`;
  }
  const icoExport = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>`;
  if (rows.length && key === 'mudancas') foot += `<button type="button" class="btn btn-ghost" data-act="exportMudancasCsv">${icoExport}${esc(tr('Exportar CSV'))}</button>`;
  if (rows.length && key !== 'mudancas') foot += `<button type="button" class="btn btn-ghost" data-act="exportCsv" data-key="${key}">${icoExport}${esc(tr('Exportar CSV'))}</button>`;
  if (foot) h += `<div style="margin-top:14px;text-align:right;display:flex;gap:8px;justify-content:flex-end">${foot}</div>`;
  if (key === 'integracoes') {
    const motor = await getMotorBanco();
    h += discoveryHtml(motor);
  }
  $('content').innerHTML = h;
}

async function renderBackup() {
  const polCols = SCHEMA.backup.fields.filter((f) => f.table);
  const rtCols = SCHEMA.restore.fields.filter((f) => f.table);
  tblStateFor('backup').page = 1; tblStateFor('backup').sel.clear();
  tblStateFor('restore').page = 1; tblStateFor('restore').sel.clear();
  let pol, rts;
  try {
    [pol, rts] = await Promise.all([fetchList('backup', query), fetchList('restore', query)]);
  } catch (e) { $('content').innerHTML = `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`; return; }

  let h = `<div class="sec-h" style="margin-top:0">${esc(tr('Política por banco'))}</div>` + tableHtml('backup', polCols, pol, 'política', !!query);
  if (pol.length) h += `<div style="margin-top:12px;text-align:right"><button class="btn btn-ghost" data-act="exportCsv" data-key="backup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>${esc(tr('Exportar CSV'))}</button></div>`;
  h += `<div class="sec-h">${esc(tr('Restore'))} ${canWrite('restore') ? `<button class="btn btn-green" style="margin-left:auto;padding:5px 11px" data-act="openNew" data-key="restore">${esc(tr('Adicionar'))}</button>` : ''}</div>` + tableHtml('restore', rtCols, rts, 'teste', !!query);
  $('content').innerHTML = h;
}

/** Reconstroi so o card de uma tabela (sem nova chamada a API) -- usado por
 * filtro/coluna/ordenar/selecao/paginacao, que operam sobre o cache[key]. */
function rerenderTableCard(key) {
  if (key === 'usuarios') { rerenderUsuariosCard(); return; }
  const sch = SCHEMA[key];
  const cols = sch.fields.filter((f) => f.table);
  const card = $('tblCard-' + key);
  if (!card) return;
  card.outerHTML = tableHtml(key, cols, cache[key] || [], sch.singular, !!query);
}

function tblToggleDd(el) {
  const wrap = el.closest('.dd');
  const wasOpen = wrap.classList.contains('open');
  document.querySelectorAll('.dd.open').forEach((w) => { if (w !== wrap) w.classList.remove('open'); });
  wrap.classList.toggle('open', !wasOpen);
}

function tblSetFilter(el) {
  const key = el.dataset.key;
  const st = tblStateFor(key);
  st.filterCol = el.dataset.col;
  st.filterVal = el.dataset.val || null;
  st.page = 1;
  rerenderTableCard(key);
}

function tblToggleCol(el) {
  const key = el.dataset.key, col = el.dataset.col;
  const st = tblStateFor(key);
  if (el.checked) st.hidden.delete(col); else st.hidden.add(col);
  saveHiddenCols(key, st);
  document.querySelectorAll('#tblCard-' + key + ' [data-col="' + col + '"]').forEach((c) => { c.style.display = el.checked ? '' : 'none'; });
}

function tblSetSort(el) {
  const key = el.dataset.key, col = el.dataset.col;
  const st = tblStateFor(key);
  if (!col) st.sortCol = null;
  else if (st.sortCol === col) st.sortDir = st.sortDir === 'asc' ? 'desc' : 'asc';
  else { st.sortCol = col; st.sortDir = 'asc'; }
  rerenderTableCard(key);
}

function tblClearSel(el) {
  const key = el.dataset.key;
  tblStateFor(key).sel.clear();
  rerenderTableCard(key);
}

function tblToggleRow(el) {
  const key = el.dataset.key, id = String(el.dataset.id);
  const st = tblStateFor(key);
  if (el.checked) st.sel.add(id); else st.sel.delete(id);
  rerenderTableCard(key);
}

function tblToggleAll(el) {
  const key = el.dataset.key;
  const st = tblStateFor(key);
  const ids = JSON.parse(el.dataset.ids || '[]');
  if (el.checked) ids.forEach((id) => st.sel.add(String(id))); else ids.forEach((id) => st.sel.delete(String(id)));
  rerenderTableCard(key);
}

function tblGoPage(el) {
  const key = el.dataset.key;
  const pg = Number(el.dataset.pg);
  if (!pg || pg < 1) return;
  tblStateFor(key).page = pg;
  rerenderTableCard(key);
}

async function tblBulkDelete(el) {
  const key = el.dataset.key;
  const st = tblStateFor(key);
  const ids = [...st.sel];
  if (!ids.length) return;
  if (!confirm(tr('Excluir os registros selecionados? Esta ação não pode ser desfeita.'))) return;
  const base = key === 'usuarios' ? '/usuarios' : ENDPOINT[key];
  try {
    await Promise.all(ids.map((id) => api.del(base + '/' + id)));
    st.sel.clear();
    st.page = 1;
    toast(tr('Registros excluídos'));
    if (key === 'usuarios') { await renderUsuarios(); }
    else { cache[key] = (cache[key] || []).filter((r) => !ids.includes(String(r.id))); rerenderTableCard(key); }
  } catch (e) { toast(e.message, true); }
}

let bulkObsKey = null;

// Atualiza o campo "obs" de todos os registros selecionados de uma vez --
// usa o mesmo endpoint de PUT por id (crudAtualizar() no backend já só
// grava as colunas presentes no corpo, então mandar só {obs} não toca em
// mais nenhum campo das linhas).
function openBulkObs(key) {
  bulkObsKey = key;
  const st = tblStateFor(key);
  $('bulkObsInfo').textContent = st.sel.size + ' ' + tr('registro(s) selecionado(s). O texto abaixo substituirá as observações de todos eles.');
  $('bulkObsTexto').value = '';
  $('bulkObsOverlay').classList.add('show');
}
function closeBulkObs() { $('bulkObsOverlay').classList.remove('show'); bulkObsKey = null; }
$('bulkObsClose').addEventListener('click', closeBulkObs);
$('bulkObsCancel').addEventListener('click', closeBulkObs);
$('bulkObsOverlay').addEventListener('click', (e) => { if (e.target === $('bulkObsOverlay')) closeBulkObs(); });
$('bulkObsSave').addEventListener('click', async () => {
  if (!bulkObsKey) return;
  const key = bulkObsKey;
  const st = tblStateFor(key);
  const ids = [...st.sel];
  if (!ids.length) { closeBulkObs(); return; }
  const texto = $('bulkObsTexto').value;
  const btn = $('bulkObsSave');
  btn.disabled = true;
  try {
    // Usa o item devolvido pelo PUT (fonte da verdade do servidor) em vez de
    // assumir que { obs: texto } foi gravado -- assim, se o backend não
    // persistir por algum motivo, a tela já mostra o valor real, sem
    // precisar de F5 pra perceber.
    const atualizados = await Promise.all(ids.map((id) => api.put(ENDPOINT[key] + '/' + id, { obs: texto })));
    const porId = new Map(atualizados.filter(Boolean).map((r) => [String(r.id), r]));
    cache[key] = (cache[key] || []).map((r) => (porId.has(String(r.id)) ? porId.get(String(r.id)) : r));
    st.sel.clear();
    toast(tr('Observações atualizadas'));
    closeBulkObs();
    rerenderTableCard(key);
  } catch (e) {
    toast(e.message, true);
  } finally {
    btn.disabled = false;
  }
});

// Tamanho de cada lote no backend -- bate com o "DELETE TOP (5000) / BREAK
// quando vier menos que isso" que a rota DELETE /{modulo}/lote usa (ver
// crudExcluirLote() em backend/crud.php). O mesmo número fatia a lista de
// ids quando há uma busca ativa (modo "por ids" abaixo).
const EXCLUIR_LOTE_TAMANHO = 5000;

function textoExcluirProgress(atual, total) {
  return lang === 'en' ? `Deleting ${atual} of ${total} records...` : `Excluindo ${atual} de ${total} registros...`;
}
function abrirExcluirProgress(total) {
  $('excluirProgressFill').style.width = '0%';
  $('excluirProgressTxt').textContent = textoExcluirProgress(0, total);
  $('excluirOverlay').classList.add('show');
}
function atualizarExcluirProgress(atual, total) {
  const pct = total ? Math.round((atual / total) * 100) : 100;
  $('excluirProgressFill').style.width = pct + '%';
  $('excluirProgressTxt').textContent = textoExcluirProgress(atual, total);
}
function fecharExcluirProgress() { $('excluirOverlay').classList.remove('show'); }

// Exclui de uma vez todos os registros visíveis no momento (respeita a busca
// global se houver uma ativa, já que cache[key] vem de fetchList(key, query)
// -- não passa pelo checkbox de seleção, então cobre todas as páginas, não
// só a atual). Não é exposto pra "usuarios": apagar todas as contas de uma
// vez é arriscado demais pra um botão só (e o backend bloqueia excluir o
// próprio usuário logado, então sempre sobraria pelo menos uma conta órfã
// do processo).
//
// Antes disso disparava 1 requisição DELETE por registro, todas em
// paralelo (Promise.all) -- com tabelas grandes (ex.: dicionário importado
// com milhares de linhas) isso sobrecarregava o servidor e dava erro
// interno. Agora vai em lotes, uma chamada por vez: sem busca ativa, apaga
// a tabela inteira em lotes nativos do banco (ver crudExcluirLote() no
// backend); com busca ativa, manda os ids filtrados em lotes. Cada chamada
// é uma "volta do loop" -- dá pra atualizar a barra de progresso de
// verdade entre uma e outra, em vez de só um spinner indeterminado.
async function tblExcluirTodos(el) {
  const key = el.dataset.key;
  const linhas = cache[key] || [];
  if (!linhas.length) return;
  const total = linhas.length;
  const msg = tr('Excluir todos os') + ' ' + total + ' ' + tr('registros? Esta ação não pode ser desfeita.');
  if (!confirm(msg)) return;

  abrirExcluirProgress(total);
  let apagados = 0;
  try {
    if (query) {
      // Há busca ativa -- "todos" aqui é "todos os filtrados", não a
      // tabela inteira: manda os ids em lotes (em vez de 1 requisição por
      // registro em paralelo).
      const idsRestantes = linhas.map((r) => r.id);
      while (idsRestantes.length) {
        const lote = idsRestantes.splice(0, EXCLUIR_LOTE_TAMANHO);
        await api.del(ENDPOINT[key] + '/lote', { ids: lote });
        apagados += lote.length;
        atualizarExcluirProgress(apagados, total);
      }
    } else {
      // Sem filtro -- apaga a tabela inteira em lotes nativos do banco,
      // repetindo a chamada até o lote voltar menor que o pedido (mesma
      // lógica do "WHILE 1=1 ... IF @@ROWCOUNT < 5000 BREAK").
      let restante = true;
      while (restante) {
        const r = await api.del(ENDPOINT[key] + '/lote', { tamanho: EXCLUIR_LOTE_TAMANHO });
        apagados += r.apagados;
        atualizarExcluirProgress(Math.min(apagados, total), total);
        restante = r.apagados >= EXCLUIR_LOTE_TAMANHO;
      }
    }
    const st = tblStateFor(key);
    st.sel.clear();
    st.page = 1;
    cache[key] = [];
    toast(tr('Registros excluídos'));
    rerenderTableCard(key);
  } catch (e) {
    toast(e.message, true);
  } finally {
    fecharExcluirProgress();
  }
}

function buildForm(key, data) {
  const sch = SCHEMA[key];
  let h = '';
  sch.fields.forEach((f) => {
    const today = new Date().toISOString().slice(0, 10);
    const v = data ? esc(data[f.k]) : (f.t === 'date' ? today : '');
    h += `<div class="fld ${f.full ? 'full' : ''}"><label>${esc(tr(f.l))}</label>`;
    if (f.t === 'select') {
      // value explícito (esc(o)): sem isso o <option> usa o próprio texto
      // como valor, e o texto muda de idioma (f.o) -- gravaria "Yes"/"No" no
      // banco em vez de "Sim"/"Não". O valor salvo continua sempre em
      // português (canônico); só o texto exibido troca com tr().
      h += `<select data-k="${f.k}"><option value="">—</option>` + optionsFor(f).map((o) => `<option value="${esc(o)}" ${data && data[f.k] === o ? 'selected' : ''}>${esc(f.o ? tr(o) : o)}</option>`).join('') + '</select>';
    } else if (f.t === 'textarea') {
      h += `<textarea data-k="${f.k}">${v}</textarea>`;
    } else if (f.t === 'objetos') {
      // Retrocompatibilidade: migra dados do campo legado 'script' se objetos vier vazio
      let objItems = (data && Array.isArray(data[f.k])) ? data[f.k] : [];
      if (!objItems.length && data && data.script) {
        objItems = String(data.script).split(',').map((s) => s.trim()).filter(Boolean).map((n) => ({ nome: n, tipo: '' }));
      }
      if (!objItems.length) objItems = [{ nome: '', tipo: '' }];
      h += '<div class="obj-list">' + objItems.map((o) => objetoRowHtml(o.nome || '', o.tipo || '')).join('') + '</div>';
      h += '<button type="button" class="btn btn-ghost" style="margin-top:8px" data-act="addObjetoRow">+ ' + esc(tr('Adicionar objeto')) + '</button>';
      h += '<input type="hidden" data-k="' + f.k + '" value="' + esc(JSON.stringify(objItems.filter((o) => o.nome))) + '">';
    } else if (f.t === 'multitext') {
      const items = (data && data[f.k] ? String(data[f.k]).split(',').map((s) => s.trim()).filter(Boolean) : []);
      if (!items.length) items.push('');
      h += `<div class="multitext">${items.map((it) => multitextRowHtml(it)).join('')}</div>`;
      h += `<button type="button" class="btn btn-ghost" style="margin-top:8px" data-act="addMultitextRow">+ ${esc(tr('Adicionar objeto'))}</button>`;
      h += `<input type="hidden" data-k="${f.k}" value="${v}">`;
    } else if (f.t === 'tagselect') {
      // Campo de busca com "chips": pensado pra listas que podem crescer
      // muito (ex.: níveis de acesso cadastrados em Cadastro) -- uma lista
      // de checkbox ficaria gigante com 50+ itens. O usuário digita pra
      // filtrar e clica numa opção do dropdown pra adicionar; cada escolha
      // vira um chip removível. O valor salvo continua uma string só com os
      // itens separados por vírgula -- mesma ideia do multicheck/multitext,
      // só a forma de escolher que muda.
      const selecionados = (data && data[f.k] ? String(data[f.k]).split(',').map((s) => s.trim()).filter(Boolean) : []);
      const opcoes = optionsFor(f);
      h += `<div class="tagselect" data-opcoes="${esc(JSON.stringify(opcoes))}">` +
        `<div class="tagselect-chips">${selecionados.map((s) => tagChipHtml(s)).join('')}</div>` +
        `<input type="text" class="tagselect-input" placeholder="${esc(tr('Buscar...'))}" data-act="openTagDropdown" data-oninput="filterTagOptions">` +
        `<div class="tagselect-dropdown"></div>` +
        '</div>';
      h += `<input type="hidden" data-k="${f.k}" value="${esc(selecionados.join(', '))}">`;
    } else {
      h += `<input type="${f.t}" data-k="${f.k}" value="${v}">`;
    }
    h += '</div>';
  });
  return h;
}

// ---------------------------------------------------------------------------
// Campo 'objetos' no módulo Mudanças: cada objeto tem nome e tipo distintos,
// salvos em mudancas_objetos (uma linha por objeto no banco).
// ---------------------------------------------------------------------------
function objetoRowHtml(nome, tipo) {
  nome = nome || ''; tipo = tipo || '';
  const opts = OPT.tipoObjeto.map(
    (t) => '<option value="' + esc(t) + '"' + (tipo === t ? ' selected' : '') + '>' + esc(t) + '</option>'
  ).join('');
  return '<div class="obj-row">'
    + '<input type="text" class="obj-nome" value="' + esc(nome) + '" placeholder="' + esc(tr('Nome do objeto')) + '" data-oninput="syncObjetos">'
    + '<select class="obj-tipo" data-oninput="syncObjetos"><option value="">— ' + esc(tr('Tipo')) + ' —</option>' + opts + '</select>'
    + '<button type="button" class="icon-btn del" data-act="removeObjetoRow" title="' + esc(tr('Remover')) + '">&times;</button>'
    + '</div>';
}

function syncObjetos(el) {
  const fld    = el ? el.closest('.fld') : null;
  if (!fld) return;
  const hidden = fld.querySelector('input[type="hidden"][data-k="objetos"]');
  if (!hidden) return;
  const rows = [...fld.querySelectorAll('.obj-row')].map((row) => ({
    nome: (row.querySelector('.obj-nome') ? row.querySelector('.obj-nome').value : '').trim(),
    tipo: (row.querySelector('.obj-tipo') ? row.querySelector('.obj-tipo').value : '').trim(),
  })).filter((o) => o.nome);
  hidden.value = JSON.stringify(rows);
}

function addObjetoRow(btn) {
  const fld  = btn.closest('.fld');
  const list = fld.querySelector('.obj-list');
  list.insertAdjacentHTML('beforeend', objetoRowHtml('', ''));
  syncObjetos(btn);
}

function removeObjetoRow(btn) {
  const fld  = btn.closest('.fld');
  btn.closest('.obj-row').remove();
  const first = fld.querySelector('.obj-nome');
  if (first) syncObjetos(first);
}


function multitextRowHtml(val) {
  return `<div class="multitext-row"><input type="text" value="${esc(val)}" placeholder="${esc(tr('Nome do objeto'))}" data-oninput="syncMultitext"><button type="button" class="icon-btn del" data-act="removeMultitextRow" title="${esc(tr('Remover'))}">&times;</button></div>`;
}

function syncMultitext(el) {
  const fld = el.closest('.fld');
  const hidden = fld.querySelector('input[type="hidden"][data-k]');
  const vals = [...fld.querySelectorAll('.multitext-row input[type="text"]')].map((i) => i.value.trim()).filter(Boolean);
  hidden.value = vals.join(', ');
}

/** HTML de um chip escolhido no tagselect (ex.: "db_owner" com botão de remover). */
function tagChipHtml(val) {
  return `<span class="tag-chip" data-v="${esc(val)}">${esc(val)}<button type="button" class="tag-chip-x" data-act="removeTag" title="${esc(tr('Remover'))}">&times;</button></span>`;
}

/** Opções do tagselect ainda não escolhidas, filtradas pelo texto digitado. */
function tagOpcoesFiltradas(wrap, termo) {
  const todas = JSON.parse(wrap.dataset.opcoes || '[]');
  const escolhidos = [...wrap.querySelectorAll('.tag-chip')].map((c) => c.dataset.v);
  const livres = todas.filter((o) => !escolhidos.includes(o));
  const t = (termo || '').trim().toLowerCase();
  return t ? livres.filter((o) => o.toLowerCase().includes(t)) : livres;
}

/** Redesenha o dropdown do tagselect com as opções (já filtradas) disponíveis. */
function refreshTagDropdown(wrap, termo) {
  const drop = wrap.querySelector('.tagselect-dropdown');
  const opcoes = tagOpcoesFiltradas(wrap, termo);
  drop.innerHTML = opcoes.length
    ? opcoes.map((o) => `<div class="tagselect-opt" data-act="addTagOption" data-v="${esc(o)}">${esc(o)}</div>`).join('')
    : `<div class="tagselect-empty">${esc(tr('Nenhuma opção encontrada'))}</div>`;
}

/** Tagselect (ex.: Nível de acesso) -> guarda os chips escolhidos no hidden como string separada por vírgula. */
function syncTagHidden(wrap) {
  const fld = wrap.closest('.fld');
  const hidden = fld.querySelector('input[type="hidden"][data-k]');
  const vals = [...wrap.querySelectorAll('.tag-chip')].map((c) => c.dataset.v);
  hidden.value = vals.join(', ');
}

function openTagDropdown(input) {
  const wrap = input.closest('.tagselect');
  document.querySelectorAll('.tagselect.open').forEach((w) => { if (w !== wrap) w.classList.remove('open'); });
  wrap.classList.add('open');
  refreshTagDropdown(wrap, input.value);
}

function filterTagOptions(input) {
  refreshTagDropdown(input.closest('.tagselect'), input.value);
}

function addTagOption(opt) {
  const wrap = opt.closest('.tagselect');
  wrap.querySelector('.tagselect-chips').insertAdjacentHTML('beforeend', tagChipHtml(opt.dataset.v));
  syncTagHidden(wrap);
  const input = wrap.querySelector('.tagselect-input');
  input.value = '';
  refreshTagDropdown(wrap, '');
  input.focus();
}

function removeTag(btn) {
  const wrap = btn.closest('.tagselect');
  btn.closest('.tag-chip').remove();
  syncTagHidden(wrap);
  if (wrap.classList.contains('open')) refreshTagDropdown(wrap, wrap.querySelector('.tagselect-input').value);
}

function addMultitextRow(btn) {
  const fld = btn.closest('.fld');
  const wrap = fld.querySelector('.multitext');
  wrap.insertAdjacentHTML('beforeend', multitextRowHtml(''));
  syncMultitext(wrap.querySelector('.multitext-row:last-child input[type="text"]'));
}

function removeMultitextRow(btn) {
  const fld = btn.closest('.fld');
  const wrap = fld.querySelector('.multitext');
  btn.closest('.multitext-row').remove();
  if (!wrap.children.length) wrap.insertAdjacentHTML('beforeend', multitextRowHtml(''));
  syncMultitext(wrap.querySelector('.multitext-row input[type="text"]'));
}

async function openNew(key) {
  if (key === 'mudancas' || key === 'backup' || key === 'restore') await fetchTipos();
  editId = null; editKey = key;
  $('modalTitle').textContent = tr('Novo registro') + ' · ' + tr(SCHEMA[key].title);
  $('modalBody').innerHTML = buildForm(key, null);
  if (key === 'mudancas') {
    const inp = $('modalBody').querySelector('[data-k="codigo"]');
    if (inp && !inp.value) inp.value = 'CHG-' + String(cache.mudancas.length + 1).padStart(3, '0');
  }
  $('overlay').classList.add('show');
}

async function openEdit(key, id) {
  if (key === 'mudancas' || key === 'backup' || key === 'restore') await fetchTipos();
  editKey = key; editId = id;
  const rec = cache[key].find((r) => String(r.id) === String(id));
  $('modalTitle').textContent = tr('Editar') + ' · ' + tr(SCHEMA[key].title);
  $('modalBody').innerHTML = buildForm(key, rec);
  $('overlay').classList.add('show');
}
function closeModal() { $('overlay').classList.remove('show'); editId = null; }

async function saveModal() {
  // Flush defensivo: sincroniza multitext e obj-list antes de coletar
  $('modalBody').querySelectorAll('.multitext').forEach((wrap) => {
    const hidden = wrap.closest('.fld').querySelector('input[type="hidden"][data-k]');
    if (!hidden) return;
    const vals = [...wrap.querySelectorAll('.multitext-row input[type="text"]')].map((i) => i.value.trim()).filter(Boolean);
    hidden.value = vals.join(', ');
  });
  $('modalBody').querySelectorAll('.obj-list').forEach((list) => {
    const first = list.querySelector('.obj-nome');
    if (first) syncObjetos(first);
  });
  const rec = {};
  $('modalBody').querySelectorAll('[data-k]').forEach((el) => { rec[el.dataset.k] = el.value.trim() === '' ? null : el.value.trim(); });
  // Campo objetos: converter JSON string → array para a API
  if (typeof rec.objetos === 'string') {
    try { rec.objetos = JSON.parse(rec.objetos || '[]'); } catch (e) { rec.objetos = []; }
  }
  const saveBtn = $('modalSave');
  saveBtn.disabled = true;
  try {
    if (editId) {
      await api.put(`${ENDPOINT[editKey]}/${editId}`, rec);
      toast('Registro atualizado');
    } else {
      await api.post(ENDPOINT[editKey], rec);
      toast('Registro adicionado');
    }
    closeModal();
    await navTo(view);
  } catch (e) {
    toast(e.message, true);
  } finally {
    saveBtn.disabled = false;
  }
}

async function delRow(key, id) {
  if (!confirm(tr('Excluir este registro? Esta ação não pode ser desfeita.'))) return;
  try {
    await api.del(`${ENDPOINT[key]}/${id}`);
    toast('Registro excluído');
    await navTo(view);
  } catch (e) { toast(e.message, true); }
}

const ROLE_DESC = {
  leitura: 'Visualiza os artefatos liberados, sem criar, editar ou excluir registros.',
  escrita: 'Cria, edita e exclui registros nos artefatos liberados para o usuário.',
  admin: 'Acesso total: todos os artefatos, usuários, cadastro, e-mail e configurações do projeto.',
  master: 'Tudo que o Administrador pode, além de ser o único perfil com acesso à trilha de auditoria.',
};

function roleInfoBoxHtml() {
  const rows = ['leitura', 'escrita', 'admin', 'master'].map((r) => `<div class="role-info-row"><span class="pill ${ROLE_PILL[r]}">${esc(tr(ROLE_LABEL[r]))}</span><span class="role-info-desc">${esc(tr(ROLE_DESC[r]))}</span></div>`).join('');
  return `<div class="card role-info-box"><div class="role-info-h">${esc(tr('O que cada papel pode fazer'))}</div>${rows}</div>`;
}

async function renderUsuarios() {
  let users;
  try { users = await api.get('/usuarios'); cache.usuarios = users; } catch (e) { $('content').innerHTML = `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`; return; }
  tblStateFor('usuarios').page = 1; tblStateFor('usuarios').sel.clear();
  $('content').innerHTML = roleInfoBoxHtml() + usuariosTableHtml(users);
}

const USUARIOS_COLS = [
  { k: 'username', l: 'Login' },
  { k: 'email', l: 'E-mail' },
  { k: 'nome_completo', l: 'Nome' },
  { k: 'role', l: 'Papel' },
  { k: 'criado_em', l: 'Criado em' },
];

function usuariosTableHtml(users) {
  if (!users.length) return `<div class="card"><div class="empty">${I.users}<p>${esc(tr('Nenhum usuário cadastrado'))}</p></div></div>`;
  const st = tblStateFor('usuarios');
  const cols = USUARIOS_COLS;
  const roleOpts = [...new Set(users.map((u) => u.role).filter(Boolean))];
  const applied = tblApply('usuarios', cols, users);
  const pages = Math.max(1, Math.ceil(applied.length / PAGE_SIZE));
  if (st.page > pages) st.page = pages;
  if (st.page < 1) st.page = 1;
  const pageRows = applied.slice((st.page - 1) * PAGE_SIZE, st.page * PAGE_SIZE);
  const allSel = pageRows.length > 0 && pageRows.every((u) => st.sel.has(String(u.id)));

  let h = '<div class="card" id="tblCard-usuarios">';
  if (st.sel.size > 0) {
    h += `<div class="tbl-toolbar tbl-toolbar-sel">
      <span class="tbl-sel-info">${st.sel.size} ${esc(tr('selecionado(s)'))}</span>
      <div class="tbl-toolbar-actions">
        <button type="button" class="btn btn-ghost" data-act="tblClearSel" data-key="usuarios">${esc(tr('Cancelar'))}</button>
        <button type="button" class="btn btn-red" data-act="tblBulkDelete" data-key="usuarios"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>${esc(tr('Excluir selecionados'))}</button>
      </div>
    </div>`;
  } else {
    h += '<div class="tbl-toolbar"><span class="tbl-toolbar-info"></span><div class="tbl-toolbar-actions">';
    h += `<div class="dd"><button type="button" class="btn btn-ghost dd-btn${st.filterVal ? ' has-value' : ''}" data-act="tblToggleDd">${I.filter}${esc(tr('Filtros'))}${I.chevDown}</button><div class="dd-panel">
      <button type="button" class="dd-opt${!st.filterVal ? ' sel' : ''}" data-act="tblSetFilter" data-key="usuarios" data-col="role" data-val="">${esc(tr('Todos'))}</button>
      ${roleOpts.map((v) => `<button type="button" class="dd-opt${st.filterVal === v ? ' sel' : ''}" data-act="tblSetFilter" data-key="usuarios" data-col="role" data-val="${esc(v)}">${esc(tr(ROLE_LABEL[v] || v))}</button>`).join('')}
    </div></div>`;
    h += `<div class="dd"><button type="button" class="btn btn-ghost dd-btn${st.hidden.size ? ' has-value' : ''}" data-act="tblToggleDd">${I.columns}${esc(tr('Colunas'))}${I.chevDown}</button><div class="dd-panel">
      ${cols.map((f) => `<label class="dd-chk"><input type="checkbox" data-act="tblToggleCol" data-key="usuarios" data-col="${f.k}" ${st.hidden.has(f.k) ? '' : 'checked'}> ${esc(tr(f.l))}</label>`).join('')}
    </div></div>`;
    h += `<div class="dd"><button type="button" class="btn btn-ghost dd-btn${st.sortCol ? ' has-value' : ''}" data-act="tblToggleDd">${I.sort}${esc(tr('Ordenar'))}${I.chevDown}</button><div class="dd-panel">
      <button type="button" class="dd-opt${!st.sortCol ? ' sel' : ''}" data-act="tblSetSort" data-key="usuarios" data-col="">${esc(tr('Padrão'))}</button>
      ${cols.map((f) => `<button type="button" class="dd-opt${st.sortCol === f.k ? ' sel' : ''}" data-act="tblSetSort" data-key="usuarios" data-col="${f.k}">${esc(tr(f.l))}${st.sortCol === f.k ? (st.sortDir === 'asc' ? ' ↑' : ' ↓') : ''}</button>`).join('')}
    </div></div>`;
    h += '</div></div>';
  }
  h += '<div class="tbl-wrap"><table><thead><tr>';
  h += `<th class="th-check"><input type="checkbox" data-act="tblToggleAll" data-key="usuarios" data-ids='${JSON.stringify(pageRows.map((u) => u.id))}' ${allSel ? 'checked' : ''}></th>`;
  cols.forEach((f) => { h += `<th data-col="${f.k}" style="${st.hidden.has(f.k) ? 'display:none' : ''}">${esc(tr(f.l))}</th>`; });
  h += `<th style="text-align:right">${esc(tr('Ações'))}</th></tr></thead><tbody>`;
  if (!pageRows.length) h += `<tr><td colspan="${cols.length + 2}" style="text-align:center;color:var(--muted);padding:26px">${esc(tr('Nenhum registro encontrado'))}</td></tr>`;
  pageRows.forEach((u) => {
    h += `<tr class="${st.sel.has(String(u.id)) ? 'row-sel' : ''}">`;
    h += `<td class="td-check"><input type="checkbox" data-act="tblToggleRow" data-key="usuarios" data-id="${u.id}" ${st.sel.has(String(u.id)) ? 'checked' : ''}></td>`;
    h += `<td class="mono" data-col="username" style="${st.hidden.has('username') ? 'display:none' : ''}">${esc(u.username)}</td>`;
    h += `<td data-col="email" style="${st.hidden.has('email') ? 'display:none' : ''}">${esc(u.email || '—')}</td>`;
    h += `<td data-col="nome_completo" style="${st.hidden.has('nome_completo') ? 'display:none' : ''}">${avatarCellHtml(u.nome_completo || u.username)}</td>`;
    h += `<td data-col="role" style="${st.hidden.has('role') ? 'display:none' : ''}"><span class="pill ${ROLE_PILL[u.role] || 'p-gray'}">${esc(tr(ROLE_LABEL[u.role] || 'Leitura'))}</span></td>`;
    h += `<td class="mono" data-col="criado_em" style="${st.hidden.has('criado_em') ? 'display:none' : ''}">${u.criado_em ? fmtDate(u.criado_em.slice(0, 10)) : '—'}</td>`;
    h += `<td><div class="row-act" style="justify-content:flex-end"><button class="icon-btn del" data-act="delUsuario" data-id="${u.id}" title="${esc(tr('Remover'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button></div></td></tr>`;
  });
  h += '</tbody></table></div>';
  h += tblPaginationHtml('usuarios', applied.length, st.page, pages);
  h += '</div>';
  return h;
}

function rerenderUsuariosCard() {
  const card = $('tblCard-usuarios');
  if (!card) { renderUsuarios(); return; }
  card.outerHTML = usuariosTableHtml(cache.usuarios || []);
}

async function renderRoles() {
  let users;
  try { users = await api.get('/usuarios'); cache.usuarios = users; } catch (e) { $('content').innerHTML = `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`; return; }
  if (!users.length) { $('content').innerHTML = `<div class="card"><div class="empty">${I.shield}<p>${esc(tr('Nenhum usuário cadastrado'))}</p></div></div>`; return; }
  let h = `<div class="card"><div class="tbl-wrap"><table><thead><tr><th>${esc(tr('Login'))}</th><th>${esc(tr('Nome'))}</th><th>${esc(tr('Papel'))}</th><th>${esc(tr('Artefatos permitidos'))}</th><th style="text-align:right">${esc(tr('Ações'))}</th></tr></thead><tbody>`;
  users.forEach((u) => {
    const mods = String(u.modulos_permitidos || '').split(',').map((s) => s.trim()).filter(Boolean);
    const modsTxt = u.role === 'admin' ? tr('Todos') : (mods.length ? mods.map((m) => tr(MODULO_LABELS[m] || m)).join(', ') : '—');
    h += `<tr><td class="mono">${esc(u.username)}</td><td>${esc(u.nome_completo || '—')}</td><td><span class="pill ${ROLE_PILL[u.role] || 'p-gray'}">${esc(tr(ROLE_LABEL[u.role] || 'Leitura'))}</span></td><td class="trunc" title="${esc(modsTxt)}">${esc(modsTxt)}</td>
      <td><div class="row-act" style="justify-content:flex-end"><button class="icon-btn" data-act="openRolesModal" data-id="${u.id}" title="${esc(tr('Editar permissões'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg></button></div></td></tr>`;
  });
  h += '</tbody></table></div></div>';
  $('content').innerHTML = h;
}

async function delUsuario(id) {
  if (!confirm(tr('Remover este usuário do portal?'))) return;
  try { await api.del('/usuarios/' + id); toast('Usuário removido'); await navTo('usuarios'); } catch (e) { toast(e.message, true); }
}

function buildModulosChecklist(containerId, selecionados) {
  const sel = selecionados || [];
  $(containerId).innerHTML = MODULOS_KEYS.map((k) => `<label class="chk-item"><input type="checkbox" value="${k}" ${sel.includes(k) ? 'checked' : ''}> ${esc(tr(MODULO_LABELS[k] || k))}</label>`).join('');
}

function readModulosChecklist(containerId) {
  return Array.from($(containerId).querySelectorAll('input[type="checkbox"]:checked')).map((c) => c.value);
}

function toggleModulosWrap(wrapId, role) {
  $(wrapId).style.display = role === 'admin' ? 'none' : '';
}

function openUserModal() {
  buildModulosChecklist('userModulosChk', []);
  toggleModulosWrap('userModulosWrap', $('userRoleSel').value);
  $('userOverlay').classList.add('show');
}
function closeUserModal() { $('userOverlay').classList.remove('show'); $('userLogin').value = ''; $('userNome').value = ''; $('userEmail').value = ''; $('userSenha').value = ''; $('userRoleSel').value = 'leitura'; }
$('userClose').addEventListener('click', closeUserModal);
$('userCancel').addEventListener('click', closeUserModal);
$('userRoleSel').addEventListener('change', (e) => toggleModulosWrap('userModulosWrap', e.target.value));
$('userSave').addEventListener('click', async () => {
  try {
    await api.post('/usuarios', {
      username: $('userLogin').value.trim(),
      nome_completo: $('userNome').value.trim(),
      email: $('userEmail').value.trim(),
      password: $('userSenha').value,
      role: $('userRoleSel').value,
      modulos: readModulosChecklist('userModulosChk'),
    });
    toast('Usuário criado');
    closeUserModal();
    await navTo('usuarios');
  } catch (e) { toast(e.message, true); }
});

let rolesEditId = null;

function openRolesModal(id) {
  const u = cache.usuarios.find((x) => String(x.id) === String(id));
  if (!u) return;
  rolesEditId = id;
  $('rolesUserLabel').value = u.nome_completo ? `${u.nome_completo} (${u.username})` : u.username;
  $('rolesRoleSel').value = u.role || 'leitura';
  const mods = String(u.modulos_permitidos || '').split(',').map((s) => s.trim()).filter(Boolean);
  buildModulosChecklist('rolesModulosChk', mods);
  toggleModulosWrap('rolesModulosWrap', $('rolesRoleSel').value);
  $('rolesOverlay').classList.add('show');
}
function closeRolesModal() { $('rolesOverlay').classList.remove('show'); rolesEditId = null; }
$('rolesClose').addEventListener('click', closeRolesModal);
$('rolesCancel').addEventListener('click', closeRolesModal);
$('rolesRoleSel').addEventListener('change', (e) => toggleModulosWrap('rolesModulosWrap', e.target.value));
$('rolesSave').addEventListener('click', async () => {
  if (!rolesEditId) return;
  try {
    await api.put(`/usuarios/${rolesEditId}/permissoes`, {
      role: $('rolesRoleSel').value,
      modulos: readModulosChecklist('rolesModulosChk'),
    });
    toast('Permissões atualizadas');
    closeRolesModal();
    await navTo('roles');
  } catch (e) { toast(e.message, true); }
});

// ---------------------------------------------------------------------------
// Cadastro: lista vertical de categorias; clicar abre a gaveta lateral
// (.drawer) com os itens daquela categoria, cada um com editar/excluir.
// ---------------------------------------------------------------------------
let cadastroDrawerCat = null; // categoria aberta na gaveta agora (null = fechada)
let cadastroEditingId = null; // id do item em edição inline dentro da gaveta
let cadastroSearchTerm = ''; // termo de busca digitado na gaveta (limpo ao abrir/fechar)
let cadastroSelIds = new Set(); // ids marcados para exclusão em massa na gaveta

async function renderCadastro() {
  try { await fetchTipos(); } catch (e) { $('content').innerHTML = `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`; return; }
  const grupos = {};
  CATEGORIAS_CADASTRO.forEach((c) => { (grupos[c.grupo] = grupos[c.grupo] || []).push(c); });
  let h = '';
  Object.entries(grupos).forEach(([grupo, cats]) => {
    h += `<div class="sec-h" style="margin-top:${h ? '24px' : '0'}">${esc(tr(grupo))}</div><div class="cadastro-list">`;
    cats.forEach((c) => {
      const n = (tiposPorCategoria[c.cat] || []).length;
      h += `<button type="button" class="cadastro-list-row" data-act="openCadastroDrawer" data-cat="${c.cat}">
        <div class="cadastro-ico">${I[c.ico] || I.db}</div>
        <div class="cadastro-list-label">${esc(tr(c.label))}</div>
        <div class="cnt">${n}</div>
        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
      </button>`;
    });
    h += '</div>';
  });
  $('content').innerHTML = h;
}

function cadastroDrawerListHtml(cat) {
  const itensAll = tiposPorCategoria[cat] || [];
  const termo = (cadastroSearchTerm || '').trim().toLowerCase();
  const itens = termo ? itensAll.filter((t) => t.nome.toLowerCase().includes(termo)) : itensAll;
  let h = '';
  if (cadastroSelIds.size > 0) {
    h += `<div class="tbl-toolbar tbl-toolbar-sel" style="border:1px solid var(--border);border-radius:var(--r-sm);margin-bottom:10px">
      <span class="tbl-sel-info">${cadastroSelIds.size} ${esc(tr('selecionado(s)'))}</span>
      <div class="tbl-toolbar-actions">
        <button type="button" class="btn btn-ghost" data-act="cancelarSelCadastro">${esc(tr('Cancelar'))}</button>
        <button type="button" class="btn btn-red" data-act="bulkDelTipo" data-cat="${cat}">${esc(tr('Excluir selecionados'))}</button>
      </div>
    </div>`;
  }
  const lista = itens.length
    ? itens.map((t) => {
        if (cadastroEditingId === t.id) {
          return `<li class="tipo-row tipo-row-editing">
            <input type="text" class="tipo-edit-inp" id="tipoEditInp" value="${esc(t.nome)}">
            <div class="row-act">
              <button class="icon-btn" data-act="salvarEdicaoTipo" data-cat="${cat}" data-id="${t.id}" title="${esc(tr('Salvar'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></button>
              <button class="icon-btn" data-act="cancelarEdicaoTipo" title="${esc(tr('Cancelar'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
          </li>`;
        }
        return `<li class="tipo-row">
          <label style="display:flex;align-items:center;gap:9px;flex:1;min-width:0;cursor:pointer;overflow:hidden">
            <input type="checkbox" data-act="toggleCadastroSel" data-id="${t.id}" ${cadastroSelIds.has(String(t.id)) ? 'checked' : ''} style="accent-color:var(--accent);cursor:pointer;flex-shrink:0">
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(t.nome)}</span>
          </label>
          <div class="row-act">
            <button class="icon-btn" data-act="iniciarEdicaoTipo" data-id="${t.id}" title="${esc(tr('Editar'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg></button>
            <button class="icon-btn del" data-act="delTipo" data-cat="${cat}" data-id="${t.id}" title="${esc(tr('Excluir'))}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>
          </div>
        </li>`;
      }).join('')
    : `<li class="tipo-row tipo-empty">${esc(tr(termo ? 'Nenhuma opção encontrada' : 'Nenhum valor cadastrado'))}</li>`;
  return h + `<ul class="tipo-list">${lista}</ul>`;
}

function cadastroDrawerBodyHtml(cat) {
  return `<div class="tipo-add" style="border-top:none;padding-top:0">
      <input type="text" id="cadastroSearchInp" placeholder="${esc(tr('Buscar...'))}" value="${esc(cadastroSearchTerm)}" data-oninput="filterCadastroDrawer">
    </div>
    <div id="cadastroListWrap">${cadastroDrawerListHtml(cat)}</div>
    <form class="tipo-add" data-act-submit="addTipo" data-cat="${cat}">
      <input type="text" placeholder="${esc(tr('Novo valor'))}" data-novo-tipo="${cat}">
      <button class="btn btn-green" type="submit">${esc(tr('Adicionar'))}</button>
    </form>`;
}

function openCadastroDrawer(cat) {
  cadastroDrawerCat = cat;
  cadastroEditingId = null;
  cadastroSearchTerm = '';
  cadastroSelIds.clear();
  const meta = CATEGORIAS_CADASTRO.find((c) => c.cat === cat);
  $('cadastroDrawerIco').innerHTML = I[meta && meta.ico] || I.db;
  $('cadastroDrawerTitle').textContent = tr(meta ? meta.label : cat);
  $('cadastroDrawerBody').innerHTML = cadastroDrawerBodyHtml(cat);
  $('cadastroDrawerOverlay').classList.add('show');
}

function closeCadastroDrawer() {
  $('cadastroDrawerOverlay').classList.remove('show');
  cadastroDrawerCat = null;
  cadastroEditingId = null;
  cadastroSearchTerm = '';
  cadastroSelIds.clear();
}

function refreshCadastroDrawer() {
  if (!cadastroDrawerCat) return;
  $('cadastroDrawerBody').innerHTML = cadastroDrawerBodyHtml(cadastroDrawerCat);
}

function filterCadastroDrawer(input) {
  cadastroSearchTerm = input.value;
  $('cadastroListWrap').innerHTML = cadastroDrawerListHtml(cadastroDrawerCat);
}

function toggleCadastroSel(el) {
  const id = String(el.dataset.id);
  if (el.checked) cadastroSelIds.add(id); else cadastroSelIds.delete(id);
  $('cadastroListWrap').innerHTML = cadastroDrawerListHtml(cadastroDrawerCat);
}

function cancelarSelCadastro() {
  cadastroSelIds.clear();
  $('cadastroListWrap').innerHTML = cadastroDrawerListHtml(cadastroDrawerCat);
}

async function bulkDelTipo(el) {
  const cat = el.dataset.cat;
  const ids = [...cadastroSelIds];
  if (!ids.length) return;
  if (!confirm(tr('Excluir os valores selecionados?'))) return;
  try {
    await Promise.all(ids.map((id) => api.del('/tipos/' + id)));
    cadastroSelIds.clear();
    toast(tr('Valores excluídos'));
    await fetchTipos();
    refreshCadastroDrawer();
    await renderCadastro();
  } catch (e) { toast(e.message, true); }
}

function iniciarEdicaoTipo(id) {
  cadastroEditingId = id;
  refreshCadastroDrawer();
  const inp = $('tipoEditInp');
  if (inp) { inp.focus(); inp.select(); }
}

function cancelarEdicaoTipo() {
  cadastroEditingId = null;
  refreshCadastroDrawer();
}

async function salvarEdicaoTipo(cat, id) {
  const inp = $('tipoEditInp');
  const nome = inp ? inp.value.trim() : '';
  if (!nome) { toast('Informe um valor', true); return; }
  try {
    await api.put('/tipos/' + id, { nome });
    cadastroEditingId = null;
    toast('Valor atualizado');
    await fetchTipos();
    refreshCadastroDrawer();
    await renderCadastro();
  } catch (e) { toast(e.message, true); }
}

async function addTipo(ev, cat) {
  ev.preventDefault();
  const input = document.querySelector(`[data-novo-tipo="${cat}"]`);
  const nome = input.value.trim();
  if (!nome) return;
  try {
    await api.post('/tipos', { categoria: cat, nome });
    toast('Valor adicionado');
    await fetchTipos();
    refreshCadastroDrawer();
    await renderCadastro();
  } catch (e) { toast(e.message, true); }
}

async function delTipo(cat, id) {
  if (!confirm(tr('Excluir este valor da lista?'))) return;
  try {
    await api.del('/tipos/' + id);
    toast('Valor excluído');
    await fetchTipos();
    refreshCadastroDrawer();
    await renderCadastro();
  } catch (e) { toast(e.message, true); }
}

$('cadastroDrawerClose').addEventListener('click', closeCadastroDrawer);
$('cadastroDrawerOverlay').addEventListener('click', (e) => { if (e.target === $('cadastroDrawerOverlay')) closeCadastroDrawer(); });

async function renderEmailConfig() {
  let cfg;
  try { cfg = await api.get('/config/email'); } catch (e) { $('content').innerHTML = `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`; return; }
  $('content').innerHTML = `<div class="card" style="padding:22px;max-width:640px">
    <div class="sec-h" style="margin-top:0">${esc(tr('Servidor SMTP'))}</div>
    <div class="email-cfg-grid">
      <div class="fld full"><label>${esc(tr('Servidor (host)'))}</label><input type="text" id="cfgHost" value="${esc(cfg.host)}" placeholder="smtp.seuprovedor.com"></div>
      <div class="fld"><label>${esc(tr('Porta'))}</label><input type="number" id="cfgPorta" value="${esc(cfg.porta || 587)}"></div>
      <div class="fld"><label>${esc(tr('Segurança'))}</label><select id="cfgSeguranca">
        <option value="tls" ${cfg.seguranca === 'tls' ? 'selected' : ''}>TLS (STARTTLS, porta 587)</option>
        <option value="ssl" ${cfg.seguranca === 'ssl' ? 'selected' : ''}>SSL (porta 465)</option>
        <option value="none" ${cfg.seguranca === 'none' ? 'selected' : ''}>${esc(tr('Nenhuma'))}</option>
      </select></div>
      <div class="fld"><label>${esc(tr('Usuário'))}</label><input type="text" id="cfgUsuario" value="${esc(cfg.usuario)}"></div>
      <div class="fld"><label>${esc(tr('Senha'))} ${cfg.senha_configurada ? `<span class="hint-inline">(${esc(tr('já configurada — deixe em branco para manter'))})</span>` : ''}</label><input type="password" id="cfgSenha" placeholder="${cfg.senha_configurada ? '••••••••' : ''}"></div>
      <div class="fld"><label>${esc(tr('Nome do remetente'))}</label><input type="text" id="cfgRemetenteNome" value="${esc(cfg.remetente_nome)}"></div>
      <div class="fld"><label>${esc(tr('E-mail do remetente'))}</label><input type="email" id="cfgRemetenteEmail" value="${esc(cfg.remetente_email)}"></div>
    </div>
    <div class="email-cfg-actions">
      <button class="btn btn-primary" data-act="saveEmailConfig">${esc(tr('Salvar'))}</button>
      <input type="email" id="cfgTesteEmail" placeholder="${esc(tr('e-mail para teste'))}">
      <button class="btn btn-ghost" data-act="testarEmailConfig">${esc(tr('Enviar teste'))}</button>
    </div>
  </div>`;
}

async function saveEmailConfig() {
  try {
    await api.put('/config/email', {
      host: $('cfgHost').value.trim(),
      porta: Number($('cfgPorta').value) || 587,
      seguranca: $('cfgSeguranca').value,
      usuario: $('cfgUsuario').value.trim(),
      senha: $('cfgSenha').value,
      remetente_nome: $('cfgRemetenteNome').value.trim(),
      remetente_email: $('cfgRemetenteEmail').value.trim(),
    });
    toast('Configuração de e-mail salva');
    await renderEmailConfig();
  } catch (e) { toast(e.message, true); }
}

async function testarEmailConfig() {
  const para = $('cfgTesteEmail').value.trim();
  if (!para) { toast('Informe um e-mail para receber o teste', true); return; }
  try {
    await api.post('/config/email/testar', { para });
    toast('E-mail de teste enviado');
  } catch (e) { toast(e.message, true); }
}

// ---------------------------------------------------------------------------
// Configurações do projeto: nome + logo (exibidos no login e no menu lateral).
// O nome/logo são renderizados pelo PHP no carregamento da página, então
// depois de salvar/remover recarregamos a página pra refletir na hora.
// ---------------------------------------------------------------------------
let cfgNovaLogoData = null; // data URI (base64) da nova logo escolhida, antes de salvar

async function renderConfigProjeto() {
  let cfg;
  try { cfg = await api.get('/config/projeto'); } catch (e) { $('content').innerHTML = `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`; return; }
  cfgNovaLogoData = null;
  const temLogo = !!cfg.logo_data;
  $('content').innerHTML = `<div class="card" style="padding:22px;max-width:640px">
    <div class="sec-h" style="margin-top:0">${esc(tr('Identidade do projeto'))}</div>
    <div class="email-cfg-grid">
      <div class="fld full"><label>${esc(tr('Nome do projeto'))}</label><input type="text" id="cfgNomeProjeto" value="${esc(cfg.nome_projeto)}" placeholder="${esc(tr('Gestão de Dados'))}"></div>
    </div>
    <div class="sec-h">${esc(tr('Logo do projeto'))}</div>
    <div class="logo-cfg-row">
      <div class="logo-cfg-preview" id="logoCfgPreview">${temLogo ? `<img src="${esc(cfg.logo_data)}" alt="">` : `<span>${esc(tr('Logo padrão'))}</span>`}</div>
      <div class="logo-cfg-actions">
        <input type="file" id="logoCfgInput" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="display:none">
        <button class="btn btn-ghost" data-act="clickLogoInput">${esc(tr('Enviar nova logo'))}</button>
        ${temLogo ? `<button class="btn btn-ghost" data-act="removerLogoProjeto">${esc(tr('Remover logo customizada'))}</button>` : ''}
        <div class="hint-inline">${esc(tr('PNG, JPG, SVG ou WEBP — no máximo ~2MB'))}</div>
      </div>
    </div>
    <div class="sec-h">${esc(tr('Sessão'))}</div>
    <div class="email-cfg-grid">
      <div class="fld"><label>${esc(tr('Timeout de inatividade (minutos)'))}</label><input type="number" id="cfgTimeoutInatividade" min="5" max="480" value="${esc(String(cfg.timeout_inatividade_min ?? 30))}"></div>
    </div>
    <div class="hint-inline">${esc(tr('Encerra a sessão automaticamente após este tempo sem uso (5 a 480 minutos).'))}</div>
    <div class="email-cfg-actions">
      <button class="btn btn-primary" data-act="salvarConfigProjeto">${esc(tr('Salvar'))}</button>
    </div>
  </div>`;

  $('logoCfgInput').addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    if (file.size > 2_800_000) { toast('Imagem muito grande. Envie no máximo ~2MB.', true); return; }
    const reader = new FileReader();
    reader.onload = () => {
      cfgNovaLogoData = reader.result;
      $('logoCfgPreview').innerHTML = `<img src="${esc(cfgNovaLogoData)}" alt="">`;
    };
    reader.readAsDataURL(file);
  });
}

async function salvarConfigProjeto() {
  const body = { nome_projeto: $('cfgNomeProjeto').value.trim() };
  if (cfgNovaLogoData) body.logo_data = cfgNovaLogoData;
  const elTimeout = $('cfgTimeoutInatividade');
  if (elTimeout) body.timeout_inatividade_min = Number(elTimeout.value) || 30;
  try {
    await api.put('/config/projeto', body);
    toast('Configurações do projeto salvas. Recarregando...');
    setTimeout(() => location.reload(), 900);
  } catch (e) { toast(e.message, true); }
}

async function removerLogoProjeto() {
  if (!confirm(tr('Remover a logo customizada e voltar pra logo padrão?'))) return;
  try {
    await api.put('/config/projeto', { remover_logo: true });
    toast('Logo removida. Recarregando...');
    setTimeout(() => location.reload(), 900);
  } catch (e) { toast(e.message, true); }
}

function dl(blob, name) { const u = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = u; a.download = name; a.click(); URL.revokeObjectURL(u); }

// Monta "inicio=...&fim=..." a partir do filtro de periodo dos Relatorios.
// Reaproveitado tanto no CSV (querystring na URL) quanto no e-mail (corpo do POST).
function relatoriosQueryString(filtros) {
  const partes = [];
  if (filtros && filtros.inicio) partes.push('inicio=' + encodeURIComponent(filtros.inicio));
  if (filtros && filtros.fim) partes.push('fim=' + encodeURIComponent(filtros.fim));
  return partes.join('&');
}

async function exportCsv(key, filtros) {
  const sch = SCHEMA[key];
  const cols = sch.fields;
  const qs = relatoriosQueryString(filtros);
  let rows;
  try { rows = await api.get(ENDPOINT[key] + (qs ? '?' + qs : '')); } catch (e) { toast(e.message, true); return; }
  const head = cols.map((f) => tr(f.l)).join(';');
  const lines = rows.map((r) => cols.map((f) => {
    let v = r[f.k];
    if (Array.isArray(v)) {
      // campo objetos: [{nome, tipo}, ...] → "NOME (tipo), ..."
      v = v.map((o) => (o && o.nome) ? (o.tipo ? o.nome + ' (' + o.tipo + ')' : o.nome) : '').filter(Boolean).join(', ');
    } else {
      v = v || '';
    }
    v = String(v).replace(/"/g, '""');
    return /[;"\n]/.test(v) ? `"${v}"` : v;
  }).join(';'));
  const csv = '﻿' + [head, ...lines].join('\n');
  dl(new Blob([csv], { type: 'text/csv;charset=utf-8' }), key + '.csv');
  toast('CSV exportado');
}

function exportCsvRelatorio(key) { if (key === 'mudancas') return exportarMudancasCsv(); return exportCsv(key, relatoriosFiltros); }

// ---------------------------------------------------------------------------
// Importacao em lote do Dicionario de dados -- mesmo formato do "Exportar
// CSV" (";" como separador, cabecalho com os labels dos campos), so que de
// volta: baixa um modelo vazio (so cabecalho + 1 linha de exemplo) e, ao
// receber o CSV preenchido, faz POST /dicionario linha a linha reaproveitando
// a validacao que ja existe no backend (crudCriar). Sem dependencia nenhuma
// nem parser de xlsx binario -- o Excel abre CSV normalmente.
// ---------------------------------------------------------------------------
function normalizarHead(s) {
  return String(s || '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

function dicTemplateCsv() {
  const cols = SCHEMA.dicionario.fields;
  const exemplo = {
    servidor: 'srv-prod-01', banco: 'meubanco', schema_nome: 'public', tabela: 'clientes',
    coluna: 'cpf', tipo_dado: 'varchar(11)', permite_nulo: OPT.nulo[1],
    descricao: 'Número do CPF do cliente', classificacao: OPT.classif[3], origem: 'Cadastro web',
  };
  const head = cols.map((f) => tr(f.l)).join(';');
  const linha = cols.map((f) => tr(exemplo[f.k] || '')).join(';');
  const csv = '﻿' + [head, linha].join('\n');
  dl(new Blob([csv], { type: 'text/csv;charset=utf-8' }), 'modelo_dicionario_dados.csv');
  toast('Modelo CSV baixado');
}

function dicImportarClick() {
  let inp = $('dicImportInput');
  if (!inp) {
    inp = document.createElement('input');
    inp.type = 'file'; inp.id = 'dicImportInput'; inp.accept = '.csv'; inp.hidden = true;
    document.body.appendChild(inp);
    inp.addEventListener('change', () => { if (inp.files[0]) dicImportarArquivo(inp.files[0]); inp.value = ''; });
  }
  inp.click();
}

/** Parser simples de CSV ";" com suporte a aspas -- mesmo formato gerado
 * por exportCsv()/dicTemplateCsv(). */
function parseCsvSimples(texto) {
  const linhas = texto.replace(/^﻿/, '').split(/\r?\n/).filter((l) => l.trim() !== '');
  return linhas.map((linha) => {
    const campos = []; let atual = ''; let dentroAspas = false;
    for (let i = 0; i < linha.length; i++) {
      const c = linha[i];
      if (dentroAspas) {
        if (c === '"' && linha[i + 1] === '"') { atual += '"'; i++; }
        else if (c === '"') dentroAspas = false;
        else atual += c;
      } else if (c === '"') dentroAspas = true;
      else if (c === ';') { campos.push(atual); atual = ''; }
      else atual += c;
    }
    campos.push(atual);
    return campos.map((c) => c.trim());
  });
}

function abrirImportProgress(total) {
  $('importProgressFill').style.width = '0%';
  $('importProgressTxt').textContent = textoImportProgress(0, total);
  $('importOverlay').classList.add('show');
}
function atualizarImportProgress(atual, total) {
  const pct = total ? Math.round((atual / total) * 100) : 100;
  $('importProgressFill').style.width = pct + '%';
  $('importProgressTxt').textContent = textoImportProgress(atual, total);
}
function fecharImportProgress() { $('importOverlay').classList.remove('show'); }

function abrirImportResult(ok, erros) {
  $('importResultOkNum').textContent = ok;
  $('importResultErrNum').textContent = erros.length;
  const box = $('importResultErrs');
  if (erros.length) {
    box.hidden = false;
    box.innerHTML = '<ul>' + erros.map((e) => `<li>${esc(e)}</li>`).join('') + '</ul>';
  } else {
    box.hidden = true;
    box.innerHTML = '';
  }
  $('importResultOverlay').classList.add('show');
}
function fecharImportResult() { $('importResultOverlay').classList.remove('show'); }

async function dicImportarArquivo(file) {
  const texto = await file.text();
  const linhas = parseCsvSimples(texto);
  if (linhas.length < 2) { toast('Arquivo vazio ou sem linhas de dados', true); return; }

  const cols = SCHEMA.dicionario.fields;
  const head = linhas[0].map(normalizarHead);
  const idx = {};
  cols.forEach((f) => {
    let i = head.indexOf(normalizarHead(f.k));
    if (i === -1) i = head.indexOf(normalizarHead(f.l));
    if (i === -1) i = head.indexOf(normalizarHead(tr(f.l)));
    if (i !== -1) idx[f.k] = i;
  });
  if (idx.tabela === undefined || idx.coluna === undefined) {
    toast('CSV precisa ter as colunas "Tabela" e "Coluna" (veja o modelo)', true);
    return;
  }

  const total = linhas.length - 1;
  abrirImportProgress(total);

  let ok = 0; const erros = [];
  for (let li = 1; li < linhas.length; li++) {
    const campos = linhas[li];
    if (campos.every((c) => c === '')) { atualizarImportProgress(li, total); continue; }
    const rec = {};
    cols.forEach((f) => { rec[f.k] = idx[f.k] !== undefined ? (campos[idx[f.k]] || null) : null; });

    if (!rec.tabela || !rec.coluna) { erros.push(`Linha ${li + 1}: "Tabela" e "Coluna" são obrigatórias`); atualizarImportProgress(li, total); continue; }
    if (rec.permite_nulo && !OPT.nulo.includes(rec.permite_nulo)) { erros.push(`Linha ${li + 1}: "Permite nulo?" deve ser ${OPT.nulo.join(' ou ')}`); atualizarImportProgress(li, total); continue; }
    if (rec.classificacao && !OPT.classif.includes(rec.classificacao)) { erros.push(`Linha ${li + 1}: "Classificação" inválida (use: ${OPT.classif.join(', ')})`); atualizarImportProgress(li, total); continue; }

    try { await api.post(ENDPOINT.dicionario, rec); ok++; } catch (e) { erros.push(`Linha ${li + 1}: ${e.message}`); }
    atualizarImportProgress(li, total);
  }

  fecharImportProgress();

  if (ok) await navTo(view);
  abrirImportResult(ok, erros);
}

// Converte uma cor "#RRGGBB" (como as usadas nas variáveis --accent do
// style.css) num array [r,g,b] que o jsPDF aceita em setFillColor/setTextColor.
// Se não der pra interpretar, devolve um dourado neutro como reserva.
function hexToRgb(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!m) return [169, 128, 46];
  const n = parseInt(m[1], 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

// Rasteriza qualquer src (URL same-origin ou data-URI) para PNG data-URI via
// canvas -- necessário porque jsPDF não aceita SVG diretamente em addImage.
function rasterizarParaPng(src, px) {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      try {
        const c = document.createElement('canvas');
        c.width = px; c.height = px;
        c.getContext('2d').drawImage(img, 0, 0, px, px);
        resolve(c.toDataURL('image/png'));
      } catch (e) { resolve(null); }
    };
    img.onerror = () => resolve(null);
    img.src = src;
  });
}

// Retorna PNG data-URI da logo do projeto (custom ou padrão), pronto pra
// addImage. Resolve null se não houver logo ou se a rasterização falhar.
async function obterLogoPdf() {
  const src = window.LOGO_PROJETO;
  if (!src) return null;
  if (/^data:image\/(png|jpe?g|webp|gif)/i.test(src)) return src;
  return rasterizarParaPng(src, 96);
}

// Exportação em PDF usando jsPDF + autoTable, carregados localmente em
// js/vendor/ (ver index.php). Tudo roda no navegador, sem servidor envolvido.
// O layout usa a cor de destaque (--accent) do tema ativo no momento da
// exportação, então o PDF acompanha a identidade visual escolhida pelo
// usuário em Meu Perfil, com um cabeçalho/rodapé fixos pra ficar com cara
// de relatório de verdade (e não uma tabela crua jogada na página).
async function exportPdf(key, filtros) {
  const sch = SCHEMA[key];
  const cols = sch.fields;
  const qs = relatoriosQueryString(filtros);
  let rows;
  try { rows = await api.get(ENDPOINT[key] + (qs ? '?' + qs : '')); } catch (e) { toast(e.message, true); return; }
  const { jsPDF } = window.jspdf || {};
  if (!jsPDF) { toast('Biblioteca de PDF não carregada.', true); return; }

  const corAccent = hexToRgb(getComputedStyle(document.documentElement).getPropertyValue('--accent'));
  const corFaixa = [28, 32, 42];
  const nomeProjeto = window.NOME_PROJETO || 'Portal de Dados';
  const titulo = tr(sch.title);
  const agora = new Date();
  const geradoEm = fmtDate(agora.toISOString().slice(0, 10)) + ' ' + agora.toTimeString().slice(0, 5);
  let periodo = '';
  if (filtros && (filtros.inicio || filtros.fim)) {
    periodo = tr('Período') + ': ' + (filtros.inicio ? fmtDate(filtros.inicio) : '—') + ' ' + tr('até') + ' ' + (filtros.fim ? fmtDate(filtros.fim) : '—');
  }

  const logoPng = await obterLogoPdf();

  const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
  const pageW = doc.internal.pageSize.getWidth();
  const pageH = doc.internal.pageSize.getHeight();
  const txtX = logoPng ? 20 : 12;

  function desenharCabecalho() {
    doc.setFillColor(corFaixa[0], corFaixa[1], corFaixa[2]);
    doc.rect(0, 0, pageW, 20, 'F');
    doc.setFillColor(corAccent[0], corAccent[1], corAccent[2]);
    doc.rect(0, 20, pageW, 1.4, 'F');
    if (logoPng) {
      try { doc.addImage(logoPng, 'PNG', 4, 4, 12, 12); } catch (e) { /* logo inválida */ }
    }
    doc.setTextColor(255, 255, 255);
    doc.setFont(undefined, 'bold');
    doc.setFontSize(12.5);
    doc.text(nomeProjeto, txtX, 8.5);
    doc.setFont(undefined, 'normal');
    doc.setFontSize(10);
    doc.text(titulo, txtX, 15.5);
    doc.setTextColor(200, 204, 214);
    doc.setFontSize(8);
    const linhasDireita = [tr('Gerado em') + ': ' + geradoEm];
    if (periodo) linhasDireita.push(periodo);
    linhasDireita.forEach((linha, i) => doc.text(linha, pageW - 12, 8.5 + i * 5, { align: 'right' }));
  }

  function desenharRodape(data) {
    doc.setFontSize(7.5);
    doc.setTextColor(150, 150, 150);
    doc.text(nomeProjeto, 12, pageH - 7);
    doc.text(String(data.pageNumber), pageW - 12, pageH - 7, { align: 'right' });
  }

  const head = [cols.map((f) => tr(f.l))];
  const body = rows.map((r) => cols.map((f) => {
    const v = r[f.k];
    if (Array.isArray(v)) {
      return v.map((o) => (o && o.nome) ? (o.tipo ? o.nome + ' (' + o.tipo + ')' : o.nome) : '').filter(Boolean).join(', ');
    }
    return String(v ?? '');
  }));

  doc.autoTable({
    head, body,
    startY: 26,
    margin: { top: 26, left: 12, right: 12, bottom: 14 },
    styles: { fontSize: 8.5, cellPadding: 3, valign: 'middle', lineColor: [228, 228, 230], lineWidth: 0.1 },
    headStyles: { fillColor: corAccent, textColor: 255, fontStyle: 'bold' },
    alternateRowStyles: { fillColor: [246, 246, 248] },
    didDrawPage: (data) => { desenharCabecalho(); desenharRodape(data); },
  });

  doc.save(key + '_' + agora.toISOString().slice(0, 10) + '.pdf');
  toast(tr('PDF exportado'));
}

function exportPdfRelatorio(key) { return exportPdf(key, relatoriosFiltros); }

async function exportJsonAll() {
  try {
    const data = await api.get('/dashboard/export');
    dl(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }), 'relatorio_completo.json');
    toast('Relatório completo exportado');
  } catch (e) { toast(e.message, true); }
}

const RELATORIOS_MODULOS = MODULOS_KEYS;
let relatoriosFiltros = { inicio: '', fim: '' };

function relatoriosFiltroBarHtml() {
  const ativo = relatoriosFiltros.inicio || relatoriosFiltros.fim;
  const periodoTxt = ativo
    ? tr('Período') + ': ' + (relatoriosFiltros.inicio ? fmtDate(relatoriosFiltros.inicio) : '—') + ' ' + tr('até') + ' ' + (relatoriosFiltros.fim ? fmtDate(relatoriosFiltros.fim) : '—')
    : tr('Nenhum período definido — as exportações trarão todos os registros');
  return `<div class="card" style="padding:16px 18px;margin-bottom:14px">
    <div class="rel-filtros">
      <div class="fld"><label>${esc(tr('De'))}</label><input type="date" id="relInicio" value="${esc(relatoriosFiltros.inicio)}"></div>
      <div class="fld"><label>${esc(tr('Até'))}</label><input type="date" id="relFim" value="${esc(relatoriosFiltros.fim)}"></div>
      <div class="audit-filtros-actions">
        <button class="btn btn-primary" data-act="aplicarFiltrosRelatorios">${esc(tr('Filtrar'))}</button>
        <button class="btn btn-ghost" data-act="limparFiltrosRelatorios">${esc(tr('Limpar'))}</button>
      </div>
    </div>
    <div class="rel-filtros-status${ativo ? ' active' : ''}">${esc(periodoTxt)}</div>
  </div>`;
}

function aplicarFiltrosRelatorios() {
  relatoriosFiltros = { inicio: $('relInicio').value, fim: $('relFim').value };
  renderRelatorios();
  toast(relatoriosFiltros.inicio || relatoriosFiltros.fim ? tr('Período aplicado às exportações') : tr('Nenhum período definido — as exportações trarão todos os registros'));
}

function limparFiltrosRelatorios() {
  relatoriosFiltros = { inicio: '', fim: '' };
  renderRelatorios();
  toast(tr('Filtro de período removido'));
}

function renderRelatorios() {
  let h = relatoriosFiltroBarHtml();
  h += '<div class="card" style="padding:18px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">';
  h += `<div><div style="font-size:13.5px;font-weight:600">${esc(tr('Relatório completo'))}</div><div style="font-size:12px;color:var(--muted);margin-top:2px">${esc(tr('Todos os artefatos reunidos em um único arquivo JSON'))}</div></div>`;
  h += `<button class="btn btn-primary" data-act="exportJsonAll"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>${esc(tr('Exportar tudo (JSON)'))}</button>`;
  h += '</div>';

  h += '<div class="tiles tiles-wide">';
  RELATORIOS_MODULOS.filter(canRead).forEach((key) => {
    const sch = SCHEMA[key];
    h += `<div class="tile report">
      <div class="tile-ico">${I.db}</div>
      <div class="lab">${esc(tr(sch.title))}</div>
      <div class="hint">${esc(tr(sch.sub))}</div>
      <div class="report-actions">
        <button class="btn btn-ghost" data-act="exportCsvRelatorio" data-key="${key}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>${esc(tr('CSV'))}</button>
        <button class="btn btn-ghost" data-act="exportPdfRelatorio" data-key="${key}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>${esc(tr('PDF'))}</button>
        <button class="btn btn-ghost" data-act="openEmailReport" data-key="${key}">${I.mail}${esc(tr('E-mail'))}</button>
      </div>
    </div>`;
  });
  h += '</div>';

  $('content').innerHTML = h;
}

let emailReportKey = null;

function openEmailReport(key) {
  emailReportKey = key;
  $('emailPara').value = '';
  $('emailOverlay').classList.add('show');
}
function closeEmailReport() { $('emailOverlay').classList.remove('show'); emailReportKey = null; }
$('emailClose').addEventListener('click', closeEmailReport);
$('emailCancel').addEventListener('click', closeEmailReport);
$('emailSend').addEventListener('click', async () => {
  if (!emailReportKey) return;
  const para = $('emailPara').value.trim();
  if (!para) { toast('Informe um e-mail de destino', true); return; }
  const btn = $('emailSend');
  btn.disabled = true;
  try {
    await api.post(`/relatorios/${emailReportKey}/email`, { para, inicio: relatoriosFiltros.inicio, fim: relatoriosFiltros.fim });
    toast('Relatório enviado por e-mail');
    closeEmailReport();
  } catch (e) {
    toast(e.message, true);
  } finally {
    btn.disabled = false;
  }
});

function renderPerfil() {
  const u = currentUser || {};
  const nome = u.nome_completo || u.username || '';
  const mfaAtivo = u.mfa_ativo == 1;
  const corAtual = u.cor_tema || 'padrao';
  const estiloAtual = u.estilo_side || 'claro';
  $('content').innerHTML = `<div class="perfil-wrap"><div class="card perfil-card">
    <div class="perfil-avatar">${esc(nome.slice(0, 1).toUpperCase())}</div>
    <div class="perfil-nome">${esc(nome)}</div>
    <div class="perfil-login">@${esc(u.username)}</div>
    <div class="perfil-papel"><span class="pill ${ROLE_PILL[u.role] || 'p-gray'}">${esc(tr(ROLE_LABEL[u.role] || 'Leitura'))}</span></div>
    <div class="perfil-fields">
      <div class="fld"><label>${esc(tr('Nome completo'))}</label><input type="text" id="perfilNome" value="${esc(u.nome_completo)}"></div>
      <div class="fld"><label>E-mail</label><input type="email" id="perfilEmail" value="${esc(u.email)}"></div>
    </div>
    <div class="perfil-actions">
      <button class="btn btn-primary" data-act="salvarPerfil">${esc(tr('Salvar'))}</button>
      <button class="btn btn-ghost" id="perfilTrocarSenha" type="button">${esc(tr('Trocar senha'))}</button>
    </div>
  </div>
  <div class="card perfil-mfa">
    <div class="perfil-mfa-h">
      <div class="perfil-mfa-ico">${I.shield}</div>
      <div>
        <div class="perfil-mfa-title">${esc(tr('Verificação em duas etapas'))}</div>
        <div class="perfil-mfa-sub">${esc(tr('Exige um código enviado por e-mail a cada login.'))}</div>
      </div>
      <span class="pill ${mfaAtivo ? 'p-green' : 'p-gray'}">${esc(mfaAtivo ? tr('Ativada') : tr('Desativada'))}</span>
    </div>
    <div class="perfil-mfa-actions">
      <button class="btn ${mfaAtivo ? 'btn-ghost' : 'btn-primary'}" data-act="toggleMfaPerfil" data-ativo="${mfaAtivo ? '1' : '0'}">${esc(mfaAtivo ? tr('Desativar') : tr('Ativar'))}</button>
    </div>
  </div>
  <div class="card perfil-tema">
    <div class="perfil-mfa-h">
      <div class="perfil-mfa-ico">${I.palette}</div>
      <div>
        <div class="perfil-mfa-title">${esc(tr('Tema de cores'))}</div>
        <div class="perfil-mfa-sub">${esc(tr('Escolha a paleta de cores do portal.'))}</div>
      </div>
    </div>
    <div class="tema-swatches">
      ${TEMA_CORES.map((t) => `<button type="button" class="tema-swatch${corAtual === t.id ? ' active' : ''}" data-act="setTemaCor" data-cor="${t.id}" title="${esc(tr(t.label))}"><span class="tema-swatch-dot" style="background:${t.cor}"></span><span class="tema-swatch-label">${esc(tr(t.label))}</span></button>`).join('')}
    </div>
  </div>
  <div class="card perfil-tema">
    <div class="perfil-mfa-h">
      <div class="perfil-mfa-ico">${I.palette}</div>
      <div>
        <div class="perfil-mfa-title">${esc(tr('Estilo da barra lateral'))}</div>
        <div class="perfil-mfa-sub">${esc(tr('Escolha o visual do menu lateral.'))}</div>
      </div>
    </div>
    <div class="tema-swatches">
      ${SIDE_ESTILOS.map((s) => `<button type="button" class="tema-swatch${estiloAtual === s.id ? ' active' : ''}" data-act="setEstiloSide" data-estilo="${s.id}" title="${esc(tr(s.label))}"><span class="tema-swatch-dot" style="background:${s.cor};box-shadow:0 0 0 2px var(--border-2)"></span><span class="tema-swatch-label">${esc(tr(s.label))}</span></button>`).join('')}
    </div>
  </div></div>`;
  $('perfilTrocarSenha').addEventListener('click', () => $('pwOverlay').classList.add('show'));
}

async function salvarPerfil() {
  try {
    currentUser = await api.put('/auth/me', {
      nome_completo: $('perfilNome').value.trim(),
      email: $('perfilEmail').value.trim(),
    });
    $('userName').textContent = currentUser.nome_completo || currentUser.username;
    $('userAvatar').textContent = (currentUser.nome_completo || currentUser.username).slice(0, 1).toUpperCase();
    toast('Perfil atualizado');
  } catch (e) { toast(e.message, true); }
}

async function setTemaCor(el) {
  const cor = el.dataset.cor;
  aplicarTemaCor(cor);
  el.closest('.tema-swatches').querySelectorAll('.tema-swatch').forEach((b) => b.classList.toggle('active', b === el));
  try {
    currentUser = await api.put('/auth/me', {
      nome_completo: currentUser.nome_completo || '',
      email: currentUser.email || '',
      cor_tema: cor,
    });
    toast('Tema atualizado');
  } catch (e) { toast(e.message, true); }
}

async function setEstiloSide(el) {
  const estilo = el.dataset.estilo;
  aplicarEstiloSide(estilo);
  el.closest('.tema-swatches').querySelectorAll('.tema-swatch').forEach((b) => b.classList.toggle('active', b === el));
  try {
    currentUser = await api.put('/auth/me', {
      nome_completo: currentUser.nome_completo || '',
      email: currentUser.email || '',
      estilo_side: estilo,
    });
    toast('Estilo atualizado');
  } catch (e) { toast(e.message, true); }
}

// ============ AUDITORIA (trilha de quem fez o quê) ============
const TABELA_LABELS = {
  acessos: 'Acessos', mudancas: 'Mudanças', backup: 'Backup', restore: 'Restore',
  dicionario: 'Dicionário', usuarios: 'Usuários', tipos: 'Cadastro', auth: 'Autenticação',
};
const ACAO_LABELS = {
  criar: 'Criação', atualizar: 'Atualização', excluir: 'Exclusão',
  login_sucesso: 'Login (sucesso)', login_falha: 'Login (falha)', logout: 'Logout',
  trocar_senha: 'Troca de senha', alterar_permissoes: 'Alteração de permissões',
  login_mfa_pendente: 'Login (código enviado)', login_mfa_falha: 'Código MFA inválido',
  mfa_envio_falhou: 'Falha ao enviar código MFA', mfa_sem_email: 'MFA sem e-mail cadastrado',
  mfa_ativado: 'MFA ativado', mfa_desativado: 'MFA desativado',
};
const ACAO_PILL = {
  criar: 'p-green', atualizar: 'p-amber', excluir: 'p-red',
  login_sucesso: 'p-green', login_falha: 'p-red', logout: 'p-gray',
  trocar_senha: 'p-teal', alterar_permissoes: 'p-amber',
  login_mfa_pendente: 'p-amber', login_mfa_falha: 'p-red',
  mfa_envio_falhou: 'p-red', mfa_sem_email: 'p-amber',
  mfa_ativado: 'p-green', mfa_desativado: 'p-gray',
};
let auditoriaFiltros = { usuario: '', tabela: '', acao: '', inicio: '', fim: '' };
let auditoriaPagina = 1;
let auditoriaDetalheAberto = null;
let auditoriaDataAtual = null;

function fmtDateTime(dt) {
  if (!dt) return '';
  const [d, t] = String(dt).split(' ');
  return fmtDate(d) + (t ? ' ' + t.slice(0, 5) : '');
}

function auditoriaQueryString(pagina) {
  const p = new URLSearchParams();
  if (auditoriaFiltros.usuario) p.set('usuario', auditoriaFiltros.usuario);
  if (auditoriaFiltros.tabela) p.set('tabela', auditoriaFiltros.tabela);
  if (auditoriaFiltros.acao) p.set('acao', auditoriaFiltros.acao);
  if (auditoriaFiltros.inicio) p.set('inicio', auditoriaFiltros.inicio);
  if (auditoriaFiltros.fim) p.set('fim', auditoriaFiltros.fim);
  p.set('pagina', String(pagina));
  p.set('por_pagina', '50');
  return p.toString();
}

function auditoriaFiltroBarHtml() {
  const tabelaOpts = Object.keys(TABELA_LABELS).map((k) => `<option value="${esc(k)}" ${auditoriaFiltros.tabela === k ? 'selected' : ''}>${esc(tr(TABELA_LABELS[k]))}</option>`).join('');
  const acaoOpts = Object.keys(ACAO_LABELS).map((k) => `<option value="${esc(k)}" ${auditoriaFiltros.acao === k ? 'selected' : ''}>${esc(tr(ACAO_LABELS[k]))}</option>`).join('');
  return `<div class="card" style="padding:16px 18px;margin-bottom:14px">
    <div class="audit-filtros">
      <div class="fld"><label>${esc(tr('Usuário'))}</label><input type="text" id="audUsuario" value="${esc(auditoriaFiltros.usuario)}" placeholder="${esc(tr('login do usuário'))}"></div>
      <div class="fld"><label>${esc(tr('Tabela'))}</label><select id="audTabela"><option value="">${esc(tr('Todas'))}</option>${tabelaOpts}</select></div>
      <div class="fld"><label>${esc(tr('Ação'))}</label><select id="audAcao"><option value="">${esc(tr('Todas'))}</option>${acaoOpts}</select></div>
      <div class="fld"><label>${esc(tr('De'))}</label><input type="date" id="audInicio" value="${esc(auditoriaFiltros.inicio)}"></div>
      <div class="fld"><label>${esc(tr('Até'))}</label><input type="date" id="audFim" value="${esc(auditoriaFiltros.fim)}"></div>
      <div class="audit-filtros-actions">
        <button class="btn btn-primary" data-act="aplicarFiltrosAuditoria">${esc(tr('Filtrar'))}</button>
        <button class="btn btn-ghost" data-act="limparFiltrosAuditoria">${esc(tr('Limpar'))}</button>
      </div>
    </div>
  </div>`;
}

function aplicarFiltrosAuditoria() {
  auditoriaFiltros = {
    usuario: $('audUsuario').value.trim(),
    tabela: $('audTabela').value,
    acao: $('audAcao').value,
    inicio: $('audInicio').value,
    fim: $('audFim').value,
  };
  auditoriaPagina = 1;
  renderAuditoria();
}

function limparFiltrosAuditoria() {
  auditoriaFiltros = { usuario: '', tabela: '', acao: '', inicio: '', fim: '' };
  auditoriaPagina = 1;
  renderAuditoria();
}

function auditoriaJsonHtml(label, dadosJson) {
  if (!dadosJson) return '';
  let dados;
  try { dados = JSON.parse(dadosJson); } catch (e) { return ''; }
  const linhas = Object.entries(dados).filter(([k]) => k !== 'password_hash' && k !== 'senha_hash')
    .map(([k, v]) => `<div class="audit-json-row"><div class="audit-json-k">${esc(k)}</div><div class="audit-json-v">${esc(v === null ? '—' : (typeof v === 'object' ? JSON.stringify(v) : v))}</div></div>`).join('');
  if (!linhas) return '';
  return `<div class="audit-json-col"><div class="audit-json-h">${esc(tr(label))}</div>${linhas}</div>`;
}

function toggleAuditoriaDetalhe(id) {
  auditoriaDetalheAberto = auditoriaDetalheAberto === id ? null : id;
  renderAuditoriaTabela();
}

function renderAuditoriaTabela() {
  const d = auditoriaDataAtual;
  const wrap = $('audTableWrap');
  if (!wrap) return;
  if (!d || !d.itens.length) {
    wrap.innerHTML = `<div class="empty">${I.shield}<p>${esc(tr('Nenhum evento encontrado'))}</p><span>${esc(tr('Ajuste os filtros para ampliar a busca.'))}</span></div>`;
    return;
  }
  let h = '<div class="tbl-wrap"><table><thead><tr>' +
    `<th>${esc(tr('Quando'))}</th><th>${esc(tr('Usuário'))}</th><th>${esc(tr('Ação'))}</th><th>${esc(tr('Tabela'))}</th><th>${esc(tr('Registro'))}</th><th>IP</th>` +
    '</tr></thead><tbody>';
  d.itens.forEach((it) => {
    const aberto = auditoriaDetalheAberto === it.id;
    const temDetalhe = !!(it.dados_antes || it.dados_depois);
    h += `<tr style="${temDetalhe ? 'cursor:pointer' : ''}" ${temDetalhe ? `data-act="toggleAuditoriaDetalhe" data-id="${it.id}"` : ''}>
      <td class="mono">${esc(fmtDateTime(it.criado_em))}</td>
      <td class="mono">${esc(it.usuario || '—')}</td>
      <td><span class="pill ${ACAO_PILL[it.acao] || 'p-gray'}">${esc(tr(ACAO_LABELS[it.acao] || it.acao))}</span></td>
      <td>${esc(tr(TABELA_LABELS[it.tabela] || it.tabela))}</td>
      <td class="mono">${it.registro_id ?? '—'}</td>
      <td class="mono">${esc(it.ip || '—')}</td>
    </tr>`;
    if (aberto) {
      const cols = [auditoriaJsonHtml('Antes', it.dados_antes), auditoriaJsonHtml('Depois', it.dados_depois)].filter(Boolean);
      h += `<tr class="audit-detail-row"><td colspan="6">${cols.length ? `<div class="audit-json">${cols.join('')}</div>` : `<span style="color:var(--faint);font-size:12.5px">${esc(tr('Sem detalhes adicionais para este evento.'))}</span>`}</td></tr>`;
    }
  });
  h += '</tbody></table></div>';
  h += `<div class="audit-pager">
    <span>${esc(tr('Página'))} ${d.pagina} ${esc(tr('de'))} ${Math.max(1, d.total_paginas)} · ${d.total} ${esc(tr('registro(s)'))}</span>
    <div class="audit-pager-btns">
      <button class="btn btn-ghost" ${d.pagina <= 1 ? 'disabled' : ''} data-act="irParaPaginaAuditoria" data-pagina="${d.pagina - 1}">${esc(tr('Anterior'))}</button>
      <button class="btn btn-ghost" ${d.pagina >= d.total_paginas ? 'disabled' : ''} data-act="irParaPaginaAuditoria" data-pagina="${d.pagina + 1}">${esc(tr('Próxima'))}</button>
    </div>
  </div>`;
  wrap.innerHTML = h;
}

function irParaPaginaAuditoria(pagina) {
  if (pagina < 1) return;
  auditoriaPagina = pagina;
  auditoriaDetalheAberto = null;
  renderAuditoria();
}

async function renderAuditoria() {
  let dados;
  try {
    dados = await api.get('/auditoria?' + auditoriaQueryString(auditoriaPagina));
  } catch (e) {
    $('content').innerHTML = auditoriaFiltroBarHtml() + `<div class="card"><div class="empty"><p>${esc(e.message)}</p></div></div>`;
    return;
  }
  auditoriaDataAtual = dados;
  $('content').innerHTML = auditoriaFiltroBarHtml() + '<div class="card" id="audTableWrap"></div>';
  renderAuditoriaTabela();
}

document.querySelectorAll('.nav-item[data-view]').forEach((b) => b.addEventListener('click', () => navTo(b.dataset.view)));
$('userChip').addEventListener('click', () => navTo('perfil'));
$('addBtn').addEventListener('click', () => { if (view === 'usuarios') openUserModal(); else openNew(view); });
$('modalClose').addEventListener('click', closeModal);
$('modalCancel').addEventListener('click', closeModal);
$('importResultClose').addEventListener('click', fecharImportResult);
$('importResultOkBtn').addEventListener('click', fecharImportResult);
$('modalSave').addEventListener('click', saveModal);
$('overlay').addEventListener('click', (e) => { if (e.target === $('overlay')) closeModal(); });

let searchTimer = null;
$('search').addEventListener('input', (e) => {
  query = e.target.value;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { if (view === 'backup') renderBackup(); else renderTable(view); }, 300);
});

$('menuToggle').addEventListener('click', () => $('side').classList.toggle('open'));

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  closeModal();
  closeUserModal();
  closeRolesModal();
  closeCadastroDrawer();
  closeEmailReport();
  closePw();
  closeMfaToggle();
  closeBulkObs();
});

// ---- Documentação do portal (download direto do PDF) --------------------
// Exibir o PDF num iframe (mesmo via blob) depende do navegador nao bloquear
// frame-ancestors herdado do documento que criou o blob -- em alguns ambientes
// isso trava com "conteudo bloqueado". Mais simples e confiavel: baixar.
// Busca via fetch autenticado (Bearer token) porque a rota exige login, entao
// um <a href> direto pra API nao funcionaria (nao manda o token).
async function abrirDocumentacao() {
  toast('Baixando documentação...');
  try {
    const res = await fetch(API_BASE + '/documentacao', { headers: { Authorization: 'Bearer ' + getToken() } });
    if (!res.ok) throw new Error(tr('Erro ao baixar documentação') + ' (' + res.status + ')');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'manual_uso_portal.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  } catch (e) {
    toast(e.message, true);
  }
}

// Mesmo padrao de download autenticado do manual, agora pro PDF com o
// diagrama + dicionario de dados do banco interno do portal (gerado em
// docs/diagrama_dicionario_banco.pdf, servido por GET /documentacao/diagrama).
async function baixarDiagramaBanco() {
  toast('Baixando diagrama do banco...');
  try {
    const res = await fetch(API_BASE + '/documentacao/diagrama', { headers: { Authorization: 'Bearer ' + getToken() } });
    if (!res.ok) throw new Error(tr('Erro ao baixar diagrama') + ' (' + res.status + ')');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'diagrama_dicionario_banco.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  } catch (e) {
    toast(e.message, true);
  }
}

// Mesmo padrao de download autenticado, agora pro manual da API REST
// (gerado em docs/manual_api_portal.pdf, servido por GET /documentacao/api).
async function baixarManualApi() {
  toast('Baixando manual da API...');
  try {
    const res = await fetch(API_BASE + '/documentacao/api', { headers: { Authorization: 'Bearer ' + getToken() } });
    if (!res.ok) throw new Error(tr('Erro ao baixar manual da API') + ' (' + res.status + ')');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'manual_api_portal.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  } catch (e) {
    toast(e.message, true);
  }
}

// ---- English documentation downloads ------------------------------------
async function abrirDocumentacaoEn() {
  toast('Downloading user manual (EN)...');
  try {
    const res = await fetch(API_BASE + '/documentacao/manual-en', { headers: { Authorization: 'Bearer ' + getToken() } });
    if (!res.ok) throw new Error('Error downloading manual (' + res.status + ')');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'manual_uso_portal_en.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  } catch (e) {
    toast(e.message, true);
  }
}

async function baixarDiagramaBancoEn() {
  toast('Downloading data dictionary (EN)...');
  try {
    const res = await fetch(API_BASE + '/documentacao/diagrama-en', { headers: { Authorization: 'Bearer ' + getToken() } });
    if (!res.ok) throw new Error('Error downloading data dictionary (' + res.status + ')');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'diagrama_dicionario_banco_en.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  } catch (e) {
    toast(e.message, true);
  }
}

async function baixarManualApiEn() {
  toast('Downloading API reference (EN)...');
  try {
    const res = await fetch(API_BASE + '/documentacao/api-en', { headers: { Authorization: 'Bearer ' + getToken() } });
    if (!res.ok) throw new Error('Error downloading API reference (' + res.status + ')');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'manual_api_portal_en.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  } catch (e) {
    toast(e.message, true);
  }
}

const DL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>';



// ---------------------------------------------------------------------------
// Segurança — view inline (Administração > Segurança, admin+master)
// ---------------------------------------------------------------------------
let segDias = 30;

// Gráfico de barras horizontal simples em SVG puro, sem biblioteca externa.
// data: array de { [labelKey]: string, total: number }
function renderBarChart(containerId, data, labelKey) {
  const el = $(containerId);
  if (!el) return;
  if (!data || data.length === 0) {
    el.innerHTML = '<p style="font-size:12px;color:var(--faint);margin:4px 0">Nenhuma ocorrência no período.</p>';
    return;
  }
  const maxVal = Math.max(...data.map((d) => Number(d.total)));
  const barH = 22, gap = 7, lblW = 128, barZone = 170, numW = 40;
  const W = lblW + barZone + numW;
  const H = data.length * (barH + gap) - gap;
  let s = `<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block;overflow:visible">`;
  data.forEach(({ [labelKey]: lbl, total }, i) => {
    const y  = i * (barH + gap);
    const bw = maxVal > 0 ? Math.max(6, Math.round((Number(total) / maxVal) * barZone)) : 0;
    const sl = String(lbl || '—');
    const shortLbl = sl.length > 18 ? sl.slice(0, 16) + '…' : sl;
    s += `<rect x="${lblW}" y="${y}" width="${barZone}" height="${barH}" rx="5" style="fill:var(--surface-2)"/>`;
    if (bw > 0) s += `<rect x="${lblW}" y="${y}" width="${bw}" height="${barH}" rx="5" style="fill:var(--red-soft)"/>`;
    s += `<text x="${lblW - 8}" y="${y + barH / 2 + 4}" text-anchor="end" style="font-size:11.5px;fill:var(--ink);font-family:inherit">${esc(shortLbl)}</text>`;
    s += `<text x="${lblW + barZone + 7}" y="${y + barH / 2 + 4}" style="font-size:11.5px;fill:var(--red);font-family:inherit;font-weight:600">${Number(total)}</text>`;
  });
  s += '</svg>';
  el.innerHTML = s;
}

// Renderiza a lista de usuários com contagem de tentativas e botão Desativar/Reativar.
// Diferente do gráfico de IPs (SVG puro), aqui precisamos de botões interativos.
function renderUserList(containerId, data) {
  const el = $(containerId);
  if (!el) return;
  if (!data || data.length === 0) {
    el.innerHTML = '<p style="font-size:12px;color:var(--faint);margin:4px 0">Nenhuma ocorrência no período.</p>';
    return;
  }
  const rows = data.map(({ usuario, total, usuario_id, ativo }) => {
    const desativado = Number(ativo) === 0;
    const podeBotao  = usuario_id != null;
    const badge = `<span style="background:var(--red-soft);color:var(--red);font-size:12px;font-weight:700;padding:2px 9px;border-radius:20px;white-space:nowrap">${Number(total)} tent.</span>`;
    const statusTag = desativado
      ? `<span style="font-size:11px;background:var(--surface-2);color:var(--muted);padding:2px 8px;border-radius:20px">${esc(tr('Desativado'))}</span>`
      : '';
    const btn = podeBotao
      ? `<button class="btn ${desativado ? 'btn-ghost' : 'btn-red'}" style="font-size:11.5px;padding:3px 11px;min-width:82px"
           data-act="${desativado ? 'reativarUsuario' : 'desativarUsuario'}"
           data-id="${usuario_id}"
           data-name="${esc(String(usuario || ''))}"
           data-ativo="${desativado ? 0 : 1}">
           ${esc(tr(desativado ? 'Reativar usuário' : 'Desativar usuário'))}
         </button>`
      : '';
    return `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <div style="flex:1;font-size:13px;font-weight:600;color:var(--ink);word-break:break-all">${esc(String(usuario || '—'))}</div>
      ${statusTag}
      ${badge}
      ${btn}
    </div>`;
  }).join('');
  el.innerHTML = `<div style="border-top:1px solid var(--border)">${rows}</div>`;
}

async function renderSeguranca() {
  let stats = { por_usuario: [], por_ip: [], total: 0, dias: segDias };
  try { stats = await api.get('/seguranca/stats?dias=' + segDias); } catch (e) { toast(e.message, true); }

  const diasOpts = [7, 30, 90].map((d) =>
    `<button class="btn ${segDias === d ? 'btn-primary' : 'btn-ghost'}" style="font-size:11.5px;padding:4px 12px" data-act="setSegDias" data-dias="${d}">${d}d</button>`
  ).join('');

  const totalHtml = stats.total > 0
    ? `<span style="font-size:28px;font-weight:700;color:var(--red);line-height:1">${stats.total}</span>`
      + `<span style="font-size:13px;color:var(--muted);margin-left:10px">tentativas de login recusadas nos últimos ${segDias} dias</span>`
    : `<span style="font-size:13px;color:var(--green)">✓ Nenhuma tentativa de login recusada nos últimos ${segDias} dias.</span>`;

  $('content').innerHTML = `<div style="max-width:960px;display:flex;flex-direction:column;gap:20px;padding-bottom:32px">

    <div class="card" style="padding:22px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <div class="sec-h" style="margin:0">${esc(tr('Tentativas de login recusadas'))}</div>
        <div style="display:flex;gap:6px">${diasOpts}</div>
      </div>
      <div style="margin-bottom:22px;display:flex;align-items:baseline;gap:0">${totalHtml}</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px">
        <div>
          <div style="font-size:10.5px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">${esc(tr('Por usuário'))}</div>
          <div id="segChartUsr"></div>
        </div>
        <div>
          <div style="font-size:10.5px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">${esc(tr('Por IP de origem'))}</div>
          <div id="segChartIp"></div>
        </div>
      </div>
    </div>

    <div class="card" style="padding:22px">
      <div class="sec-h" style="margin-top:0">${esc(tr('Checklist de Segurança'))}</div>
      <div id="segBody"></div>
    </div>

  </div>`;

  renderUserList('segChartUsr', stats.por_usuario);
  renderBarChart('segChartIp', stats.por_ip, 'ip');
  renderSegBody();
}

async function exportarMudancasCsv() {
  try {
    const token = getToken();
    const res = await fetch(API_BASE + '/mudancas/exportar', {
      headers: token ? { Authorization: 'Bearer ' + token } : {},
    });
    if (!res.ok) { showToast(tr('Erro ao exportar'), 'erro'); return; }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'mudancas_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    showToast(e instanceof ApiError ? e.message : tr('Erro ao exportar'), 'erro');
  }
}

async function desativarUsuario(id, nome) {
  if (!confirm(`Desativar a conta de "${nome}"? O usuário não conseguirá mais fazer login.`)) return;
  try {
    await api.patch('/usuarios/' + id + '/ativo', { ativo: false });
    toast(tr('Conta desativada com sucesso'));
    await renderSeguranca();
  } catch (e) { toast(e.message, true); }
}

async function reativarUsuario(id, nome) {
  try {
    await api.patch('/usuarios/' + id + '/ativo', { ativo: true });
    toast(tr('Conta reativada com sucesso'));
    await renderSeguranca();
  } catch (e) { toast(e.message, true); }
}


function setSegDias(dias) {
  segDias = Number(dias);
  renderSeguranca();
}

// ---------------------------------------------------------------------------
// Guia de Segurança — checklist periódico (Documentação > Guia de Segurança)
// O progresso de cada item é salvo em localStorage (prefixo 'seg_').
// ---------------------------------------------------------------------------
const SEG_PFX = 'seg_';

const SEG = [
  { cat: 'Autenticação e Acesso', items: [
    { id:'s01', txt:'Rotacionar o secret_key do JWT (conf/config.php)',
      det:'Gere uma nova chave aleatória de pelo menos 64 caracteres e atualize <code>secret_key</code> em conf/config.php. Isso invalida todas as sessões ativas — os usuários precisarão fazer login novamente.',
      freq:'90 dias' },
    { id:'s02', txt:'Verificar adoção de MFA pelos administradores',
      det:'Em Usuários, confirme que todos com perfil Admin e Master têm autenticação de dois fatores ativada. MFA é a segunda linha de defesa mais importante após a senha forte.',
      freq:'30 dias' },
    { id:'s03', txt:'Desativar ou revisar usuários inativos',
      det:'Verifique no log de Auditoria quem não acessa há mais de 60 dias. Usuários inativos são vetores de acesso não monitorados — desative-os se não forem mais necessários.',
      freq:'60 dias' },
    { id:'s04', txt:'Revisar perfis de acesso (princípio do menor privilégio)',
      det:'Confirme que cada usuário tem apenas o nível mínimo para exercer sua função: Leitura &lt; Escrita &lt; Admin &lt; Master. Nenhum usuário deve ter mais permissão do que precisa.',
      freq:'30 dias' },
  ]},
  { cat: 'Logs e Auditoria', items: [
    { id:'s05', txt:'Revisar tentativas de login com falha',
      det:'No módulo Auditoria, filtre por tipo <code>login_falha</code>. Volume alto em curto período pode indicar ataque de força bruta — considere bloqueio de IP no servidor.',
      freq:'7 dias' },
    { id:'s06', txt:'Verificar acessos em horários atípicos',
      det:'Filtre acessos entre 00h–06h ou em fins de semana se o ambiente for corporativo. Acessos fora do padrão habitual merecem investigação.',
      freq:'30 dias' },
    { id:'s07', txt:'Arquivar e limpar logs antigos',
      det:'Exporte os registros de Auditoria com mais de 90 dias (CSV) e arquive-os externamente. A tabela enxuta mantém o desempenho das consultas.',
      freq:'90 dias' },
  ]},
  { cat: 'Banco de Dados', items: [
    { id:'s08', txt:'Verificar se backups recentes foram gerados com sucesso',
      det:'No módulo Backup, confirme que há pelo menos um backup recente (últimas 24–48h) com tamanho compatível com os dados do ambiente. Backup vazio ou muito pequeno indica falha silenciosa.',
      freq:'7 dias' },
    { id:'s09', txt:'Testar restauração de backup em ambiente isolado',
      det:'Mensalmente, restaure o último backup num ambiente de teste e valide que os dados estão íntegros e as tabelas acessíveis. <strong>Backup não testado não é backup.</strong>',
      freq:'30 dias' },
    { id:'s10', txt:'Revisar permissões do usuário do banco de dados',
      det:'O usuário configurado em conf/config.php deve ter apenas SELECT, INSERT, UPDATE e DELETE nas tabelas do portal — sem GRANT, CREATE DATABASE, DROP ou FILE. Verifique com: <code>SHOW GRANTS FOR \'usuario\'@\'host\';</code>',
      freq:'90 dias' },
  ]},
  { cat: 'Infraestrutura', items: [
    { id:'s11', txt:'Confirmar que /setup/ está inacessível (403)',
      det:'Tente acessar /setup/ no navegador. Deve retornar 403 Forbidden. O .htaccess protege o diretório. Se retornar 200, remova ou restrinja a pasta imediatamente.',
      freq:'30 dias' },
    { id:'s12', txt:'Confirmar que conf/config.php não é público',
      det:'Tente acessar /conf/config.php no navegador. Deve retornar 403 Forbidden. O .htaccess na pasta conf/ protege o arquivo de configuração com credenciais do banco.',
      freq:'30 dias' },
    { id:'s13', txt:'Validar origens CORS permitidas nas configurações',
      det:'Em Administração → Configurações, revise a lista de origens CORS. Remova origens obsoletas ou genéricas demais. Nunca use <code>*</code> em produção.',
      freq:'30 dias' },
    { id:'s14', txt:'Verificar validade do certificado HTTPS/SSL',
      det:'Confirme que o certificado SSL está válido e que requisições HTTP redirecionam automaticamente para HTTPS. Use SSL Labs (ssllabs.com/ssltest) para uma auditoria externa.',
      freq:'30 dias' },
    { id:'s15', txt:'Manter PHP em versão LTS com suporte ativo',
      det:'Acesse php.net/supported-versions.php e confirme que a versão em uso ainda recebe atualizações de segurança. PHP fora do ciclo de suporte não recebe patches de vulnerabilidades.',
      freq:'90 dias' },
  ]},
  { cat: 'Integrações', items: [
    { id:'s16', txt:'Rotacionar tokens e credenciais das integrações',
      det:'No módulo Integrações, renove tokens de acesso e senhas com mais de 90 dias. Credenciais antigas são alvo frequente de comprometimento gradual.',
      freq:'90 dias' },
    { id:'s17', txt:'Confirmar que IPs e hosts cadastrados são válidos',
      det:'Verifique que os IPs de origem e destino nas integrações correspondem a sistemas legítimos e ativos. IPs desatualizados podem apontar para endereços reutilizados por terceiros.',
      freq:'30 dias' },
    { id:'s18', txt:'Remover ou rever integrações inativas',
      det:'Integração com status inativo há mais de 30 dias deve ser revisada. Se não houver previsão de reativação, remova-a — isso reduz a superfície de ataque e mantém o ambiente limpo.',
      freq:'30 dias' },
  ]},
];

function segDataTag(id) {
  const d = localStorage.getItem(SEG_PFX + id);
  if (!d) return '<span class="seg-data pendente">Pendente</span>';
  const [y, m, day] = d.split('-');
  return `<span class="seg-data ok">✓ ${day}/${m}/${y}</span>`;
}

function renderSegBody() {
  const total = SEG.reduce((a, c) => a + c.items.length, 0);
  const done  = SEG.reduce((a, c) => a + c.items.filter(i => !!localStorage.getItem(SEG_PFX + i.id)).length, 0);
  const pct   = total ? Math.round(done / total * 100) : 0;

  let h = `<div class="seg-prog">
    <span class="seg-prog-txt">${done} de ${total} verificações concluídas</span>
    <div class="seg-prog-bar-wrap"><div class="seg-prog-bar" style="width:${pct}%"></div></div>
    ${done > 0 ? '<button class="btn btn-ghost" style="font-size:11.5px;padding:4px 10px" data-act="limparSeguranca">Limpar progresso</button>' : ''}
  </div>`;

  for (const { cat, items } of SEG) {
    h += `<div class="seg-cat-hd">${esc(cat)}</div>`;
    for (const { id, txt, det, freq } of items) {
      const isDone = !!localStorage.getItem(SEG_PFX + id);
      h += `<div class="seg-item${isDone ? ' done' : ''}" data-act="marcarItemSeg" data-id="${id}">
        <div class="seg-chk">${isDone ? '✓' : ''}</div>
        <div class="seg-info">
          <div class="seg-txt">${esc(txt)}</div>
          <div class="seg-det">${det}</div>
          <div class="seg-meta">
            <span class="seg-freq">A cada ${esc(freq)}</span>
            ${segDataTag(id)}
          </div>
        </div>
      </div>`;
    }
  }
  $('segBody').innerHTML = h;
}



function marcarItemSeg(id) {
  if (localStorage.getItem(SEG_PFX + id)) {
    localStorage.removeItem(SEG_PFX + id);
  } else {
    localStorage.setItem(SEG_PFX + id, new Date().toISOString().slice(0, 10));
  }
  renderSegBody();
}

function limparSeguranca() {
  if (!confirm('Limpar todo o progresso do checklist de segurança?')) return;
  SEG.forEach(({ items }) => items.forEach(({ id }) => localStorage.removeItem(SEG_PFX + id)));
  renderSegBody();
}

function renderDocumentacao() {
  let h = '<div class="tiles tiles-wide">';

  h += `<div class="tile report">
    <div class="tile-ico">${I.db}</div>
    <div class="lab">${esc(tr('Manual de uso do portal'))}</div>
    <div class="hint">${esc(tr('Guia em PDF de como usar cada módulo do portal'))}</div>
    <div class="report-actions">
      <button class="btn btn-ghost" data-act="abrirDocumentacao">${DL_ICON} 🇧🇷 PT</button>
      <button class="btn btn-ghost" data-act="abrirDocumentacaoEn">${DL_ICON} 🇬🇧 EN</button>
    </div>
  </div>`;

  h += `<div class="tile report">
    <div class="tile-ico">${I.db}</div>
    <div class="lab">${esc(tr('Diagrama e dicionário de dados'))}</div>
    <div class="hint">${esc(tr('Estrutura do banco de dados interno do portal, com a descrição de cada coluna'))}</div>
    <div class="report-actions">
      <button class="btn btn-ghost" data-act="baixarDiagramaBanco">${DL_ICON} 🇧🇷 PT</button>
      <button class="btn btn-ghost" data-act="baixarDiagramaBancoEn">${DL_ICON} 🇬🇧 EN</button>
    </div>
  </div>`;

  h += `<div class="tile report">
    <div class="tile-ico">${I.db}</div>
    <div class="lab">${esc(tr('API'))}</div>
    <div class="hint">${esc(tr('Manual de uso da API do portal, com exemplo de requisição e resposta de cada rota'))}</div>
    <div class="report-actions">
      <button class="btn btn-ghost" data-act="baixarManualApi">${DL_ICON} 🇧🇷 PT</button>
      <button class="btn btn-ghost" data-act="baixarManualApiEn">${DL_ICON} 🇬🇧 EN</button>
    </div>
  </div>`;

  h += '</div>';
  $('content').innerHTML = h;
}

$('themeToggle').addEventListener('click', () => {
  const novo = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', novo);
  localStorage.setItem('theme', novo);
});

$('langToggle').addEventListener('click', () => {
  lang = lang === 'en' ? 'pt' : 'en';
  localStorage.setItem('lang', lang);
  applyStaticI18n();
  navTo(view);
});

if (localStorage.getItem('sideCollapsed') === '1') $('side').classList.add('collapsed');
$('sideCollapseBtn').addEventListener('click', () => {
  $('side').classList.toggle('collapsed');
  localStorage.setItem('sideCollapsed', $('side').classList.contains('collapsed') ? '1' : '0');
});

// ---------------------------------------------------------------------------
// Dispatcher central de cliques (CSP sem 'unsafe-inline' -- tudo via
// data-act). Cada entrada recebe (el, e); a maioria das funcoes ja le os
// dados que precisa de el.dataset.*, entao so e' encapsulada em arrow
// function quando a funcao original espera os valores ja "desembrulhados".
// ---------------------------------------------------------------------------
const ACTIONS = {
  // tabela moderna (filtros, colunas, ordenacao, paginacao, selecao em massa)
  tblToggleDd,
  tblSetFilter,
  tblToggleCol,
  tblSetSort,
  tblClearSel,
  tblToggleRow,
  tblToggleAll,
  tblGoPage,
  tblBulkDelete,
  tblExcluirTodos,
  openBulkObs: (el) => openBulkObs(el.dataset.key),
  // CRUD generico das tabelas (Acessos, Mudancas, Backup, Restore, Dicionario, Usuarios)
  openNew: (el) => openNew(el.dataset.key),
  openEdit: (el) => openEdit(el.dataset.key, el.dataset.id),
  delRow: (el) => delRow(el.dataset.key, el.dataset.id),
  exportMudancasCsv: () => exportarMudancasCsv(),
  exportCsv: (el) => exportCsv(el.dataset.key),
  // Dicionario de dados -- importacao em lote (CSV)
  dicTemplateCsv,
  dicImportarClick,
  // tagselect (campo "Nivel de acesso" e similares)
  openTagDropdown,
  addTagOption,
removeTag,
  // multitext (campo de objetos/lista livre)
  addMultitextRow,
  removeMultitextRow,
  addObjetoRow,
  removeObjetoRow,
  // Usuarios
  delUsuario: (el) => delUsuario(el.dataset.id),
  openRolesModal: (el) => openRolesModal(el.dataset.id),
  // Cadastro (gaveta de tipos)
  openCadastroDrawer: (el) => openCadastroDrawer(el.dataset.cat),
  cancelarSelCadastro,
  bulkDelTipo,
  toggleCadastroSel,
  iniciarEdicaoTipo: (el) => iniciarEdicaoTipo(el.dataset.id),
  cancelarEdicaoTipo,
  salvarEdicaoTipo: (el) => salvarEdicaoTipo(el.dataset.cat, el.dataset.id),
  delTipo: (el) => delTipo(el.dataset.cat, el.dataset.id),
  // E-mail (config + envio de relatorio)
  saveEmailConfig,
  testarEmailConfig,
  openEmailReport: (el) => openEmailReport(el.dataset.key),
  // Configuracoes do projeto
  clickLogoInput: () => $('logoCfgInput').click(),
  removerLogoProjeto,
  salvarConfigProjeto,
  // Relatorios
  aplicarFiltrosRelatorios,
  limparFiltrosRelatorios,
  exportJsonAll,
  exportCsvRelatorio: (el) => exportCsvRelatorio(el.dataset.key),
  exportPdfRelatorio: (el) => exportPdfRelatorio(el.dataset.key),
  // Perfil
  salvarPerfil,
  toggleMfaPerfil: (el) => abrirMfaToggle(el.dataset.ativo !== '1'),
  irMfaBannerPerfil: async () => { await navTo('perfil'); abrirMfaToggle(true); },
  setTemaCor,
  setEstiloSide,
  // Auditoria
  aplicarFiltrosAuditoria,
  limparFiltrosAuditoria,
  toggleAuditoriaDetalhe: (el) => toggleAuditoriaDetalhe(el.dataset.id),
  irParaPaginaAuditoria: (el) => irParaPaginaAuditoria(Number(el.dataset.pagina)),
  // Documentacao do portal (PT + EN)
  setSegDias: (el) => setSegDias(el.dataset.dias),
  desativarUsuario: (el) => desativarUsuario(el.dataset.id, el.dataset.name),
  reativarUsuario:  (el) => reativarUsuario(el.dataset.id, el.dataset.name),
  marcarItemSeg: (el) => marcarItemSeg(el.dataset.id),
  limparSeguranca,
  abrirDocumentacao,
  baixarDiagramaBanco,
  baixarManualApi,
  abrirDocumentacaoEn,
  baixarDiagramaBancoEn,
  baixarManualApiEn,
  // Painel de descoberta de integracoes
  copiarConsultaDiscovery,
};

const SUBMIT_ACTIONS = {
  addTipo: (e, form) => addTipo(e, form.dataset.cat),
};

const INPUT_ACTIONS = {
  filterCadastroDrawer,
  filterTagOptions,
  syncMultitext,
  syncObjetos,
};

document.addEventListener('click', (e) => {
  if (!e.target.closest('.dd')) document.querySelectorAll('.dd.open').forEach((w) => w.classList.remove('open'));
  if (!e.target.closest('.tagselect')) document.querySelectorAll('.tagselect.open').forEach((w) => w.classList.remove('open'));
  const el = e.target.closest('[data-act]');
  if (!el) return;
  const fn = ACTIONS[el.dataset.act];
  if (fn) fn(el, e);
});
document.addEventListener('submit', (e) => {
  const form = e.target.closest('[data-act-submit]');
  if (!form) return;
  const fn = SUBMIT_ACTIONS[form.dataset.actSubmit];
  if (fn) fn(e, form);
});

document.addEventListener('input', (e) => {
  const fn = INPUT_ACTIONS[e.target.dataset.oninput];
  if (fn) fn(e.target);
});

// ---------------------------------------------------------------------------
// Info do banco de dados — exibida no rodapé da sidebar
// ---------------------------------------------------------------------------
const DB_DRIVER_LABEL = { sqlite: 'SQLite', mysql: 'MySQL', pgsql: 'PostgreSQL', sqlsrv: 'SQL Server' };

function formatBytes(b) {
  b = Number(b);
  if (!b || isNaN(b)) return null;
  if (b < 1024) return b + ' B';
  if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
  if (b < 1024 * 1024 * 1024) return (b / 1024 / 1024).toFixed(1) + ' MB';
  return (b / 1024 / 1024 / 1024).toFixed(2) + ' GB';
}

async function carregarDbInfo() {
  try {
    const info = await api.get('/sistema/info');
    const el   = $('dbInfo');
    if (!el) return;
    const partes = [
      DB_DRIVER_LABEL[info.driver] || info.driver,
      info.versao,
      formatBytes(info.tamanho_bytes),
    ].filter(Boolean);
    const txt = partes.join(' · ');
    el.textContent = txt;
    el.title       = txt;
    el.style.display = '';
  } catch (e) { /* silencioso — nao interrompe o login */ }
}


applyStaticI18n();
tryAutoLogin();
