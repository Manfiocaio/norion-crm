<?php
// ============================================================
// ARQUIVO: leads.php
// O QUE FAZ: Lista leads em tabela OU kanban — mesma página
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
require_once 'config/log.php';
requer_permissao('ver_leads');
$pagina_atual = 'leads';
$pode_editar_leads = tem_permissao('editar_leads');

// ── Ação AJAX do kanban (atualizar status via drag) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_status'])) {
    $lead_id     = (int)$_POST['lead_id'];
    $novo_status = mysqli_real_escape_string($conexao, $_POST['novo_status']);
    $valor_venda = str_replace(',', '.', trim($_POST['valor_venda'] ?? '0'));
    $valor_venda = (is_numeric($valor_venda) && $valor_venda > 0) ? (float)$valor_venda : 0;

    $status_validos = ['novo','em_contato','proposta_enviada','negociacao','fechado','perdido'];
    if (!in_array($novo_status, $status_validos)) {
        http_response_code(400); echo json_encode(['erro'=>'inválido']); exit();
    }

    // Pega status anterior para o log
    $ant_r       = mysqli_query($conexao, "SELECT status FROM leads WHERE id=$lead_id");
    $status_ant  = mysqli_fetch_assoc($ant_r)['status'] ?? '';

    mysqli_query($conexao, "UPDATE leads SET status='$novo_status' WHERE id=$lead_id");

    if ($novo_status === 'fechado' && $valor_venda > 0) {
        mysqli_query($conexao, "UPDATE leads SET valor=$valor_venda WHERE id=$lead_id");
        $v = mysqli_query($conexao, "SELECT id FROM vendas WHERE lead_id=$lead_id");
        if (mysqli_num_rows($v) === 0) {
            $lr   = mysqli_query($conexao, "SELECT nome, tipo_proposta FROM leads WHERE id=$lead_id");
            $lead = mysqli_fetch_assoc($lr);
            $desc = mysqli_real_escape_string($conexao,
                ($lead['tipo_proposta'] ? $lead['tipo_proposta'].' — ' : 'Venda — ').$lead['nome']
            );
            $hoje = date('Y-m-d');
            mysqli_query($conexao,
                "INSERT INTO vendas (lead_id,valor,descricao,data_venda) VALUES ($lead_id,$valor_venda,'$desc','$hoje')"
            );
        } else {
            mysqli_query($conexao, "UPDATE vendas SET valor=$valor_venda WHERE lead_id=$lead_id");
        }
        registrar_log($conexao, $lead_id, 'fechou',
            "Negócio fechado por R$ " . number_format($valor_venda, 2, ',', '.'));
    } elseif ($novo_status !== 'fechado') {
        mysqli_query($conexao, "DELETE FROM vendas WHERE lead_id=$lead_id");
        mysqli_query($conexao, "UPDATE leads SET valor=0 WHERE lead_id=$lead_id");
        // Log de mudança de status via kanban
        $labels = ['novo'=>'Novo','em_contato'=>'Em contato','proposta_enviada'=>'Proposta enviada',
                   'negociacao'=>'Negociação','fechado'=>'Fechado','perdido'=>'Perdido'];
        $de   = $labels[$status_ant]  ?? $status_ant;
        $para = $labels[$novo_status] ?? $novo_status;
        registrar_log($conexao, $lead_id, 'status_alterado', "\"$de\" → \"$para\" (kanban)");
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit();
}

// ── Visualização ativa (tabela ou kanban) ──
// Lemos da URL: ?view=kanban ou ?view=tabela
// Salvamos na sessão para lembrar a preferência do usuário
if (isset($_GET['view'])) {
    $_SESSION['leads_view'] = $_GET['view'];
}
$view_ativa = $_SESSION['leads_view'] ?? 'tabela';
// Se nunca escolheu, começa na tabela

// ── Filtros ──
$busca  = trim($_GET['busca']  ?? '');
$status = trim($_GET['status'] ?? '');
$origem = trim($_GET['origem'] ?? '');
$etiq   = trim($_GET['etiqueta'] ?? '');

// ── Ordenação ──
// Colunas permitidas — whitelist para evitar SQL injection
$colunas_permitidas = ['nome', 'status', 'criado_em', 'valor', 'possivel_ganho'];
$ordem    = in_array($_GET['ordem'] ?? '', $colunas_permitidas) ? $_GET['ordem'] : 'criado_em';
$direcao  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
// Inverso para o link do cabeçalho — ao clicar inverte a direção
$dir_inv  = $direcao === 'asc' ? 'desc' : 'asc';

$where = "WHERE 1=1";
if (!empty($busca))  { $b = mysqli_real_escape_string($conexao,$busca);  $where .= " AND nome LIKE '%$b%'"; }
if (!empty($status)) { $s = mysqli_real_escape_string($conexao,$status); $where .= " AND status = '$s'"; }
if (!empty($origem)) { $o = mysqli_real_escape_string($conexao,$origem); $where .= " AND origem = '$o'"; }
if (!empty($etiq))   { $e = mysqli_real_escape_string($conexao,$etiq);   $where .= " AND etiqueta = '$e'"; }

// ── Filtro de responsável ──
// Colaboradores veem APENAS leads atribuídos a eles
// Admin pode filtrar por responsável se quiser
$meu_colab_id = eh_admin() ? 0 : (int)($_SESSION['colab_id'] ?? 0);

// Verifica se a coluna atribuido_para existe
$col_atrib = mysqli_query($conexao, "SHOW COLUMNS FROM leads LIKE 'atribuido_para'");
$tem_atribuicao = $col_atrib && mysqli_num_rows($col_atrib) > 0;

if ($tem_atribuicao) {
    if (!eh_admin() && $meu_colab_id > 0) {
        // Colaborador: só vê leads atribuídos a ele
        $where .= " AND atribuido_para = $meu_colab_id";
    } elseif (eh_admin() && !empty($_GET['responsavel'])) {
        // Admin filtrando por responsável
        $resp = (int)$_GET['responsavel'];
        if ($resp > 0)  $where .= " AND atribuido_para = $resp";
        if ($resp === -1) $where .= " AND (atribuido_para IS NULL OR atribuido_para = 0)";
    }
}

// Lista de colaboradores ativos para o filtro do admin
$lista_colabs = [];
if (eh_admin() && $tem_atribuicao) {
    $rc = mysqli_query($conexao, "SELECT id, nome_completo, colaborador_id FROM colaboradores WHERE status='ativo' ORDER BY nome_completo");
    while ($c = mysqli_fetch_assoc($rc)) $lista_colabs[] = $c;
}

// ── Paginação ──
$por_pagina     = 25;
$pagina_atual_n = max(1, (int)($_GET['pagina'] ?? 1));
// Conta total filtrado sem LIMIT
$count_r        = mysqli_query($conexao, "SELECT COUNT(*) as t FROM leads $where");
$total_filtrado = (int)(mysqli_fetch_assoc($count_r)['t'] ?? 0);
$total_paginas  = max(1, (int)ceil($total_filtrado / $por_pagina));
$pagina_atual_n = min($pagina_atual_n, $total_paginas); // não deixa passar do máximo
$offset         = ($pagina_atual_n - 1) * $por_pagina;

$resultado   = mysqli_query($conexao,
    "SELECT l.*" . ($tem_atribuicao ? ", c.nome_completo as resp_nome, c.colaborador_id as resp_cid" : "") . "
     FROM leads l" . ($tem_atribuicao ? " LEFT JOIN colaboradores c ON c.id = l.atribuido_para" : "") . "
     $where ORDER BY $ordem $direcao LIMIT $por_pagina OFFSET $offset"
);
$total_geral_r  = mysqli_query($conexao, "SELECT COUNT(*) as t FROM leads");
$total_geral    = (int)(mysqli_fetch_assoc($total_geral_r)['t'] ?? 0);
$tem_filtro     = !empty($busca) || !empty($status) || !empty($origem) || !empty($etiq);

// Monta URL base preservando todos os parâmetros menos a página
$params_paginacao = array_filter([
    'view'     => $view_ativa,
    'busca'    => $busca,
    'status'   => $status,
    'origem'   => $origem,
    'etiqueta' => $etiq,
    'ordem'    => $ordem !== 'criado_em' ? $ordem : '',
    'dir'      => $direcao !== 'desc'    ? $direcao : '',
]);
$url_pag_base = 'leads.php?' . http_build_query(array_filter($params_paginacao));

// ── Para o kanban: buscar todos os leads agrupados ──
// (independente dos filtros — no kanban mostramos tudo)
$colunas_kanban = [
    'novo'             => ['titulo'=>'Novo',             'cor'=>'#008CFF', 'leads'=>[]],
    'em_contato'       => ['titulo'=>'Em contato',       'cor'=>'#F59E0B', 'leads'=>[]],
    'proposta_enviada' => ['titulo'=>'Proposta enviada', 'cor'=>'#7C3AED', 'leads'=>[]],
    'negociacao'       => ['titulo'=>'Negociação',       'cor'=>'#F97316', 'leads'=>[]],
    'fechado'          => ['titulo'=>'Fechado',           'cor'=>'#10B981', 'leads'=>[]],
    'perdido'          => ['titulo'=>'Perdido',           'cor'=>'#EF4444', 'leads'=>[]],
];
$r_kanban = mysqli_query($conexao,
    "SELECT id, nome, telefone, email, origem, etiqueta, valor,
            tipo_proposta, possivel_ganho, status, criado_em
     FROM leads ORDER BY criado_em DESC"
);
while ($l = mysqli_fetch_assoc($r_kanban)) {
    $s = $l['status'] ?? 'novo';
    if (isset($colunas_kanban[$s])) $colunas_kanban[$s]['leads'][] = $l;
}

// Flash message
$flash = ''; $flash_tipo = '';
if (!empty($_SESSION['flash_msg'])) {
    $flash      = $_SESSION['flash_msg'];
    $flash_tipo = $_SESSION['flash_tipo'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_tipo']);
}

$etiquetas_disponiveis = ['VIP','Urgente','Retornar','Proposta enviada','Aguardando','Frio'];

// ── Função para gerar link de cabeçalho ordenável ──
function th_link($col, $label, $ordem_atual, $direcao_atual, $params_get) {
    $ativo   = $ordem_atual === $col;
    $dir_new = ($ativo && $direcao_atual === 'asc') ? 'desc' : 'asc';
    $params  = array_merge($params_get, ['ordem' => $col, 'dir' => $dir_new]);
    $url     = 'leads.php?' . http_build_query($params);

    // Seta ativa mostra direção atual, inativa mostra ícone neutro
    if ($ativo) {
        $seta = $direcao_atual === 'asc'
            ? '<span class="th-seta">↑</span>'
            : '<span class="th-seta">↓</span>';
    } else {
        $seta = '<span class="th-seta" style="opacity:0.25;">↕</span>';
    }

    $cls = $ativo ? 'th-link th-ativo' : 'th-link';
    return "<a href=\"$url\" class=\"$cls\">$label $seta</a>";
}

// Parâmetros GET atuais para manter nos links
$get_atual = array_filter([
    'busca'    => $busca,
    'status'   => $status,
    'origem'   => $origem,
    'etiqueta' => $etiq,
    'view'     => $view_ativa, // sempre preserva a view ativa
]);
?>
<!DOCTYPE html>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Leads</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Alternador de visualização ── */
        .view-toggle {
            display: flex;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2px;
            gap: 2px;
        }
        .view-btn {
            display: flex; align-items: center; gap: 5px;
            padding: 5px 12px;
            border-radius: 7px;
            font-size: 12px; font-weight: 600;
            color: var(--text-3);
            text-decoration: none;
            border: none; background: none; cursor: pointer;
            font-family: 'Manrope', sans-serif;
            transition: all 0.15s;
        }
        .view-btn svg { width: 13px; height: 13px; stroke: currentColor; }
        .view-btn:hover { color: var(--text-1); }
        .view-btn.ativa {
            background: var(--surface);
            color: var(--azul);
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        /* ── Filtros ── */
        .filtros-wrap { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:14px 16px; border-bottom:1px solid var(--border); background:var(--surface-2); }
        .filtro-input { height:36px; border:1px solid var(--border-2); border-radius:var(--radius); padding:0 12px 0 34px; font-size:13px; font-family:'Manrope',sans-serif; color:var(--text-1); background:var(--surface); outline:none; width:200px; transition:border-color 0.15s; }
        .filtro-input:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(0,140,255,0.12); }
        .input-icone-wrap { position:relative; }
        .input-icone-wrap svg { position:absolute; left:10px; top:50%; transform:translateY(-50%); width:14px; height:14px; stroke:var(--text-3); pointer-events:none; }
        .filtro-select { height:36px; border:1px solid var(--border-2); border-radius:var(--radius); padding:0 10px; font-size:13px; font-family:'Manrope',sans-serif; color:var(--text-1); background:var(--surface); outline:none; cursor:pointer; }
        .filtro-select.ativo { border-color:var(--azul); background:var(--azul-light); color:var(--azul); font-weight:600; }
        .filtros-contagem { margin-left:auto; font-size:12px; color:var(--text-3); white-space:nowrap; }
        .filtros-contagem strong { color:var(--text-1); }

        /* ── Paginação ── */
        .paginacao {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-top: 1px solid var(--border);
            background: var(--surface-2);
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
        }
        .pag-info { font-size: 12px; color: var(--text-3); }
        .pag-info strong { color: var(--text-1); }
        .pag-botoes { display: flex; align-items: center; gap: 4px; }
        .pag-btn {
            min-width: 32px; height: 32px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: var(--radius); font-size: 12px; font-weight: 600;
            text-decoration: none; transition: all 0.15s;
            border: 1px solid var(--border-2);
            background: var(--surface); color: var(--text-2);
            padding: 0 8px;
        }
        .pag-btn:hover { background: var(--surface-2); color: var(--text-1); border-color: var(--border-2); }
        .pag-btn.ativo { background: var(--azul); color: white; border-color: var(--azul); }
        .pag-btn.desabilitado { opacity: 0.35; pointer-events: none; }
        .pag-reticencias { font-size: 12px; color: var(--text-3); padding: 0 4px; }

        /* ── Cabeçalhos ordenáveis ── */
        th { cursor: default; }
        th:has(.th-link) { padding: 0; }
        .th-link {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 11px 16px;
            color: var(--text-3);
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            white-space: nowrap;
            cursor: pointer;
            transition: color 0.15s, background 0.15s;
            user-select: none;
        }
        .th-link:hover {
            color: var(--text-1);
            background: var(--border);
        }
        .th-link.th-ativo {
            color: var(--azul);
        }
        .th-link.th-ativo:hover {
            color: var(--azul-hover);
            background: var(--azul-light);
        }
        .th-seta {
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
        }

        /* ── Barra de ações em massa ── */
        .barra-massa { display:none; align-items:center; gap:10px; padding:10px 16px; background:#EFF8FF; border-bottom:1px solid var(--azul-mid); }
        .barra-massa.visivel { display:flex; }
        .barra-massa-info { font-size:13px; font-weight:600; color:#0055B3; }
        .barra-massa-acoes { display:flex; gap:8px; margin-left:auto; }
        .cb-lead { width:16px; height:16px; cursor:pointer; accent-color:var(--azul); }
        tr.selecionada td { background:var(--azul-light) !important; }

        /* ── Etiquetas ── */
        .etiqueta-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; letter-spacing:0.3px; text-transform:uppercase; }
        .etq-VIP{background:#FEF3C7;color:#92400E} .etq-Urgente{background:#FEE2E2;color:#991B1B}
        .etq-Retornar{background:#EDE9FE;color:#4C1D95} .etq-Proposta-enviada{background:#D1FAE5;color:#065F46}
        .etq-Aguardando{background:#F3F4F6;color:#374151} .etq-Frio{background:#E0F2FE;color:#0C4A6E}

        /* ── Nome clicável ── */
        .lead-nome-link { color:var(--text-1); font-weight:700; cursor:pointer; text-decoration:none; transition:color 0.15s; }
        .lead-nome-link:hover { color:var(--azul); }

        /* ── Kanban ── */
        .kanban-section { padding: 16px 20px; }

        .kanban-wrap {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        /* Sem nth-child especial — 3 colunas por linha automaticamente */
        .kanban-coluna { display:flex; flex-direction:column; gap:8px; }
        .coluna-header { display:flex; align-items:center; gap:6px; padding:8px 10px; background:var(--surface); border-radius:var(--radius-lg); border:1px solid var(--border); }
        .coluna-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .coluna-titulo { font-size:12px; font-weight:700; color:var(--text-1); flex:1; }
        .coluna-count { font-size:10px; font-weight:600; color:var(--text-3); background:var(--surface-2); border:1px solid var(--border); border-radius:20px; padding:1px 6px; min-width:20px; text-align:center; flex-shrink:0; }
        .coluna-corpo { min-height:100px; display:flex; flex-direction:column; gap:6px; padding:4px 0; border-radius:var(--radius-lg); transition:background 0.15s; }
        .coluna-corpo.drag-over { background:var(--azul-light); outline:2px dashed var(--azul-mid); outline-offset:2px; }

        /* Coluna inteira fica visualmente receptiva durante o drag */
        .kanban-coluna.drop-alvo { opacity: 0.85; }
        .kanban-coluna.drop-alvo-ativo .coluna-header {
            background: var(--azul-light);
            border-color: var(--azul-mid);
        }
        .kanban-coluna.drop-alvo-ativo .coluna-corpo {
            background: var(--azul-light);
            outline: 2px dashed var(--azul-mid);
            outline-offset: 2px;
            min-height: 80px;
        }

        .kanban-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:10px 12px; cursor:grab; transition:box-shadow 0.15s,opacity 0.15s; user-select:none; }
        .kanban-card:hover { border-color:var(--border-2); box-shadow:0 2px 6px rgba(0,0,0,0.06); }
        .kanban-card.dragging { opacity:0.4; }
        .kanban-card-linha { height:2px; border-radius:2px; margin-bottom:8px; }
        .card-nome-k { font-size:12px; font-weight:700; color:var(--text-1); margin-bottom:4px; line-height:1.3; }
        .proposta-tag { display:inline-flex; align-items:center; padding:2px 6px; border-radius:5px; font-size:10px; font-weight:700; margin-bottom:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-2); }
        .prop-Site{background:#EFF8FF;border-color:#B8DCFF;color:#0055B3}
        .prop-Software-sob-medida{background:#EDE9FE;border-color:#C4B5FD;color:#4C1D95}
        .prop-Fluxo-de-IA{background:#D1FAE5;border-color:#6EE7B7;color:#065F46}
        .prop-Agente-de-IA{background:#FEF3C7;border-color:#FCD34D;color:#92400E}
        .prop-Landing-Page{background:#FEE2E2;border-color:#FCA5A5;color:#991B1B}
        .card-info-k { display:flex; flex-direction:column; gap:2px; margin-bottom:6px; }
        .card-info-linha { display:flex; align-items:center; gap:4px; font-size:10px; color:var(--text-3); }
        .card-info-linha svg { width:10px; height:10px; stroke:var(--text-3); flex-shrink:0; }
        .card-footer-k { display:flex; align-items:center; justify-content:space-between; margin-top:6px; padding-top:6px; border-top:1px solid var(--border); }
        .coluna-vazia { text-align:center; padding:16px 8px; font-size:11px; color:var(--text-3); border:1px dashed var(--border); border-radius:var(--radius); }

        /* Responsivo */
        @media (max-width: 900px) {
            .kanban-wrap { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 560px) {
            .kanban-wrap { grid-template-columns: 1fr; }
        }

        /* ── Drawer lateral ── */
        .drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.35); z-index:150; opacity:0; pointer-events:none; transition:opacity 0.25s ease; }
        .drawer-overlay.aberto { opacity:1; pointer-events:all; }
        .drawer { position:fixed; top:0; right:0; bottom:0; width:460px; background:var(--surface); z-index:151; display:flex; flex-direction:column; transform:translateX(100%); transition:transform 0.3s cubic-bezier(0.32,0.72,0,1); box-shadow:-8px 0 32px rgba(0,0,0,0.12); }
        .drawer.aberto { transform:translateX(0); }
        .drawer-header { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:flex-start; gap:12px; flex-shrink:0; }
        .drawer-header-info { flex:1; min-width:0; }
        .drawer-nome { font-size:17px; font-weight:800; color:var(--text-1); letter-spacing:-0.3px; margin-bottom:4px; }
        .drawer-badges { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .drawer-fechar { width:30px; height:30px; border-radius:8px; border:none; background:none; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background 0.15s; }
        .drawer-fechar:hover { background:var(--surface-2); }
        .drawer-fechar svg { width:16px; height:16px; stroke:var(--text-3); }
        .drawer-corpo { flex:1; overflow-y:auto; }
        .drawer-secao { padding:16px 20px; border-bottom:1px solid var(--border); }
        .drawer-secao:last-child { border-bottom:none; }
        .drawer-secao-titulo { font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:12px; }
        .dados-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .dado-label { font-size:11px; color:var(--text-3); font-weight:500; margin-bottom:2px; }
        .dado-valor { font-size:13px; color:var(--text-1); font-weight:500; }
        .obs-texto { font-size:13px; color:var(--text-2); line-height:1.6; white-space:pre-wrap; }
        .mini-timeline { position:relative; padding-left:20px; }
        .mini-timeline::before { content:''; position:absolute; left:5px; top:6px; bottom:6px; width:1px; background:var(--border); }
        .mini-item { position:relative; margin-bottom:12px; }
        .mini-item:last-child { margin-bottom:0; }
        .mini-dot { position:absolute; left:-16px; top:12px; width:8px; height:8px; border-radius:50%; border:2px solid var(--surface); }
        .dot-ligacao{background:var(--azul)} .dot-whatsapp{background:#25D366} .dot-email{background:var(--amarelo)} .dot-reuniao{background:#7C3AED} .dot-outro{background:var(--text-3)}
        .mini-card { background:var(--surface-2); border:1px solid var(--border); border-radius:var(--radius); padding:10px 12px; }
        .mini-header { display:flex; align-items:center; gap:6px; margin-bottom:4px; }
        .mini-tipo { font-size:11px; font-weight:700; color:var(--text-2); }
        .mini-data { font-size:10px; color:var(--text-3); margin-left:auto; }
        .mini-anotacao { font-size:12px; color:var(--text-2); line-height:1.4; }
        .mini-proximo { font-size:11px; color:var(--azul); margin-top:4px; font-weight:500; }
        .mini-proximo::before { content:'→ '; }
        .mini-doc { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border); }
        .mini-doc:last-child { border-bottom:none; }
        .mini-doc-icone { width:30px; height:30px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:9px; font-weight:800; flex-shrink:0; }
        .icone-pdf{background:#FEE2E2;color:#991B1B} .icone-word{background:#DBEAFE;color:#1E40AF} .icone-img{background:#D1FAE5;color:#065F46} .icone-outro{background:var(--cinza-light);color:var(--cinza-text)}
        .mini-doc-nome { font-size:12px; font-weight:600; color:var(--text-1); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .mini-doc-tam { font-size:11px; color:var(--text-3); }
        .drawer-rodape { padding:14px 20px; border-top:1px solid var(--border); flex-shrink:0; }
        .drawer-loading { display:flex; align-items:center; justify-content:center; padding:40px; color:var(--text-3); font-size:13px; gap:8px; }
        .spinner { width:16px; height:16px; border:2px solid var(--border); border-top-color:var(--azul); border-radius:50%; animation:spin 0.6s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* Prévia de doc no drawer */
        .doc-previa-wrap { margin-top:8px; border-radius:var(--radius); overflow:hidden; border:1px solid var(--border); background:var(--surface-2); }
        .doc-previa-toggle { width:100%; padding:6px 10px; background:none; border:none; font-size:11px; font-weight:600; color:var(--azul); cursor:pointer; text-align:left; font-family:'Manrope',sans-serif; display:flex; align-items:center; gap:4px; }
        .doc-previa-toggle:hover { background:var(--azul-light); }
        .doc-previa-area { display:none; }
        .doc-previa-area.aberta { display:block; }
        .doc-previa-img { width:100%; max-height:300px; object-fit:contain; background:#000; display:block; }
        .doc-previa-pdf { width:100%; height:380px; border:none; display:block; }

        /* ── Modal de fechamento kanban ── */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:200; display:none; align-items:center; justify-content:center; }
        .modal-overlay.aberto { display:flex; }
        .modal-box { background:var(--surface); border-radius:var(--radius-lg); padding:28px; width:100%; max-width:380px; border:1px solid var(--border); }
        .modal-titulo { font-size:16px; font-weight:800; color:var(--text-1); margin-bottom:4px; }
        .modal-sub { font-size:13px; color:var(--text-3); margin-bottom:20px; }
        .modal-acoes { display:flex; gap:10px; margin-top:20px; }
        .modal-acoes .btn { flex:1; justify-content:center; }

        /* ── Modais de massa ── */
        .etiquetas-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:20px; }
        .etq-opcao { padding:8px 12px; border-radius:var(--radius); border:1px solid var(--border-2); background:var(--surface); font-size:13px; font-weight:500; cursor:pointer; transition:all 0.15s; text-align:left; }
        .etq-opcao:hover { border-color:var(--azul); background:var(--azul-light); color:var(--azul); }
        .etq-opcao.marcada { border-color:var(--azul); background:var(--azul); color:white; }

        /* ── Toast ── */
        .toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px); background:var(--text-1); color:var(--surface); padding:10px 20px; border-radius:var(--radius-lg); font-size:13px; font-weight:600; z-index:300; transition:transform 0.3s cubic-bezier(0.32,0.72,0,1); white-space:nowrap; }
        .toast.visivel { transform:translateX(-50%) translateY(0); }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">
            Leads
            <span style="font-size:12px;font-weight:500;color:var(--text-3);margin-left:6px;"><?php echo $total_geral; ?> cadastrados</span>
        </span>
        <div class="topbar-acoes">
            <!-- Alternador tabela / kanban -->
            <div class="view-toggle">
                <a href="?view=tabela<?php echo !empty($busca)?'&busca='.urlencode($busca):''; ?>"
                   class="view-btn <?php echo $view_ativa==='tabela'?'ativa':''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
                    Tabela
                </a>
                <a href="?view=kanban"
                   class="view-btn <?php echo $view_ativa==='kanban'?'ativa':''; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="5" height="8" rx="1"/></svg>
                    Kanban
                </a>
            </div>
            <?php if ($view_ativa === 'tabela' && $pode_editar_leads): ?>
            <button class="btn btn-secondary btn-sm" id="btn-selecionar" onclick="toggleSelecao()">Selecionar</button>
            <?php endif; ?>
            <?php if ($pode_editar_leads): ?>
            <a href="leads_importar.php" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:5px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Importar CSV
            </a>
            <a href="leads_novo.php" class="btn btn-primary btn-sm">+ Novo lead</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
    <div style="padding:12px 20px 0;">
        <div class="alert alert-<?php echo $flash_tipo==='error'?'error':'success'; ?>">
            <?php echo htmlspecialchars($flash); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         VIEW: TABELA
         ============================================================ -->
    <?php if ($view_ativa === 'tabela'): ?>

    <div style="padding:20px;">
        <div class="table-container">

            <!-- Barra de ações em massa -->
            <div class="barra-massa" id="barra-massa">
                <span class="barra-massa-info" id="info-selecionados">0 selecionados</span>
                <div class="barra-massa-acoes">
                    <button class="btn btn-secondary btn-sm" onclick="exportarCSV()">↓ Exportar CSV</button>
                    <button class="btn btn-secondary btn-sm" onclick="abrirModalEtiqueta()">🏷 Etiqueta</button>
                    <button class="btn btn-danger btn-sm" onclick="abrirModalExcluir()">Excluir</button>
                </div>
            </div>

            <!-- Filtros -->
            <form action="leads.php" method="get" id="form-filtros">
                <input type="hidden" name="view" value="tabela">
                <?php if ($ordem !== 'criado_em'): ?>
                <input type="hidden" name="ordem" value="<?php echo htmlspecialchars($ordem); ?>">
                <?php endif; ?>
                <?php if ($direcao !== 'desc'): ?>
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($direcao); ?>">
                <?php endif; ?>
                <!-- pagina não é incluído — ao filtrar sempre volta à pág 1 -->
                <div class="filtros-wrap">
                    <div class="input-icone-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input class="filtro-input" type="text" name="busca" placeholder="Buscar por nome..."
                            value="<?php echo htmlspecialchars($busca); ?>" autocomplete="off">
                    </div>
                    <select class="filtro-select <?php echo !empty($status)?'ativo':''; ?>" name="status" onchange="this.form.submit()">
                        <option value="">Todos os status</option>
                        <?php foreach(['novo'=>'Novo','em_contato'=>'Em contato','proposta_enviada'=>'Proposta enviada','negociacao'=>'Negociação','fechado'=>'Fechado','perdido'=>'Perdido'] as $v=>$r): $s=($status===$v)?'selected':''; ?>
                            <option value="<?php echo $v; ?>" <?php echo $s; ?>><?php echo $r; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filtro-select <?php echo !empty($origem)?'ativo':''; ?>" name="origem" onchange="this.form.submit()">
                        <option value="">Todas as origens</option>
                        <?php foreach(['Instagram','Indicação','Site','WhatsApp','Google','Outro'] as $op): $s=($origem===$op)?'selected':''; ?>
                            <option value="<?php echo $op; ?>" <?php echo $s; ?>><?php echo $op; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filtro-select <?php echo !empty($etiq)?'ativo':''; ?>" name="etiqueta" onchange="this.form.submit()">
                        <option value="">Todas as etiquetas</option>
                        <?php foreach($etiquetas_disponiveis as $et): $s=($etiq===$et)?'selected':''; ?>
                            <option value="<?php echo $et; ?>" <?php echo $s; ?>><?php echo $et; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (eh_admin() && $tem_atribuicao && !empty($lista_colabs)): ?>
                    <select class="filtro-select <?php echo isset($_GET['responsavel'])&&$_GET['responsavel']!==''?'ativo':''; ?>"
                            name="responsavel" onchange="this.form.submit()">
                        <option value="">Todos os responsáveis</option>
                        <option value="-1" <?php echo (($_GET['responsavel']??'')=='-1')?'selected':''; ?>>Sem responsável</option>
                        <?php foreach($lista_colabs as $lc): $sel=(($_GET['responsavel']??'')==$lc['id'])?'selected':''; ?>
                        <option value="<?php echo $lc['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($lc['nome_completo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar</button>
                    <?php if ($tem_filtro): ?>
                        <a href="leads.php?view=tabela" class="btn btn-secondary btn-sm" style="color:var(--vermelho);border-color:#FECACA;">× Limpar</a>
                    <?php endif; ?>
                    <div class="filtros-contagem">
                        <?php if ($tem_filtro): ?>
                            <strong><?php echo $total_filtrado; ?></strong> de <?php echo $total_geral; ?> leads
                            <?php if ($total_paginas > 1): ?>
                             · pág. <?php echo $pagina_atual_n; ?>/<?php echo $total_paginas; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <strong><?php echo $total_geral; ?></strong> leads no total
                            <?php if ($total_paginas > 1): ?>
                             · pág. <?php echo $pagina_atual_n; ?>/<?php echo $total_paginas; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- Tabela -->
            <table id="tabela-leads">
                <thead>
                    <tr>
                        <th class="col-sel" style="display:none;width:36px;">
                            <input type="checkbox" class="cb-lead" id="cb-todos" onchange="selecionarTodos(this.checked)">
                        </th>
                        <th><?php echo th_link('nome',      'Lead',   $ordem, $direcao, $get_atual); ?></th>
                        <th>Tipo / Etiqueta</th>
                        <th><?php echo th_link('valor',     'Valor',  $ordem, $direcao, $get_atual); ?></th>
                        <th><?php echo th_link('status',    'Status', $ordem, $direcao, $get_atual); ?></th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($total_filtrado === 0): ?>
                    <tr><td colspan="6"><div class="empty-state">
                        <?php if ($tem_filtro): ?>
                            <strong>Nenhum lead encontrado</strong><a href="leads.php?view=tabela" style="color:var(--azul);">Limpar filtros</a>
                        <?php else: ?>
                            <strong>Nenhum lead cadastrado</strong>Clique em "+ Novo lead" para começar
                        <?php endif; ?>
                    </div></td></tr>
                <?php else: while($l = mysqli_fetch_assoc($resultado)):
                    // Destaque: lead atribuído ao colaborador logado
                    $e_meu = $tem_atribuicao && !eh_admin() && ($l['atribuido_para'] ?? 0) == $meu_colab_id;
                ?>
                    <tr data-id="<?php echo $l['id']; ?>" <?php echo $e_meu ? 'style="background:var(--azul-light);border-left:3px solid var(--azul);"' : ''; ?>>
                        <td class="col-sel" style="display:none;">
                            <input type="checkbox" class="cb-lead cb-linha" value="<?php echo $l['id']; ?>" onchange="atualizarSelecao()">
                        </td>

                        <!-- Nome + telefone + responsável -->
                        <td>
                            <div><span class="lead-nome-link" onclick="abrirDrawer(<?php echo $l['id']; ?>)"><?php echo htmlspecialchars($l['nome']); ?></span></div>
                            <?php if ($l['telefone']): ?>
                            <div style="font-size:11px;color:var(--text-3);margin-top:2px;"><?php echo htmlspecialchars($l['telefone']); ?></div>
                            <?php endif; ?>
                            <?php if ($tem_atribuicao && !empty($l['resp_nome'])): ?>
                            <div style="font-size:10px;color:var(--azul);margin-top:3px;font-weight:600;">
                                → <?php echo htmlspecialchars($l['resp_nome']); ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Tipo + etiqueta juntos -->
                        <td>
                            <?php if (!empty($l['tipo_proposta'])): $pc='prop-'.str_replace(' ','-',$l['tipo_proposta']); ?>
                                <span class="proposta-tag <?php echo $pc; ?>" style="font-size:10px;padding:2px 6px;margin-bottom:3px;display:inline-flex;"><?php echo htmlspecialchars($l['tipo_proposta']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($l['etiqueta'])): $ec='etq-'.str_replace(' ','-',$l['etiqueta']); ?>
                                <br><span class="etiqueta-badge <?php echo $ec; ?>" style="margin-top:2px;"><?php echo htmlspecialchars($l['etiqueta']); ?></span>
                            <?php endif; ?>
                            <?php if (empty($l['tipo_proposta']) && empty($l['etiqueta'])): ?>—<?php endif; ?>
                        </td>

                        <!-- Valor -->
                        <td>
                            <?php if ($l['status']==='fechado' && $l['valor']>0): ?>
                                <span style="color:var(--verde);font-weight:700;font-size:12px;">R$ <?php echo number_format($l['valor'],2,',','.'); ?></span>
                            <?php elseif(($l['possivel_ganho']??0)>0): ?>
                                <span style="color:var(--text-3);font-size:11px;" title="Possível ganho">~R$ <?php echo number_format($l['possivel_ganho'],2,',','.'); ?></span>
                            <?php else: ?><span style="color:var(--text-3);">—</span><?php endif; ?>
                        </td>

                        <!-- Status -->
                        <td><?php
                            $map=['novo'=>['badge-novo','Novo'],'em_contato'=>['badge-contato','Em contato'],'proposta_enviada'=>['badge-proposta','Proposta enviada'],'negociacao'=>['badge-negociacao','Negociação'],'fechado'=>['badge-fechado','Fechado'],'perdido'=>['badge-perdido','Perdido']];
                            [$cls,$lbl]=$map[$l['status']]??['badge-novo',$l['status']];
                            echo "<span class=\"badge $cls\">$lbl</span>";
                        ?></td>

                        <!-- Ação -->
                        <td><?php if($pode_editar_leads): ?><a href="leads_editar.php?id=<?php echo $l['id']; ?>" class="btn btn-secondary btn-sm">Editar</a><?php endif; ?></td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>

            <!-- ── Paginação ── -->
            <?php if ($total_paginas > 1): ?>
            <div class="paginacao">
                <!-- Info: mostrando X–Y de Z -->
                <div class="pag-info">
                    <?php
                    $ini = $offset + 1;
                    $fim = min($offset + $por_pagina, $total_filtrado);
                    ?>
                    Mostrando <strong><?php echo $ini; ?>–<?php echo $fim; ?></strong>
                    de <strong><?php echo $total_filtrado; ?></strong> leads
                </div>

                <!-- Botões de página -->
                <div class="pag-botoes">

                    <!-- Anterior -->
                    <a href="<?php echo $url_pag_base . '&pagina=' . ($pagina_atual_n - 1); ?>"
                       class="pag-btn <?php echo $pagina_atual_n <= 1 ? 'desabilitado' : ''; ?>">
                        ← Anterior
                    </a>

                    <?php
                    // Gera os números de página com reticências inteligentes
                    // Mostra: 1 … 4 5 [6] 7 8 … 20
                    $janela = 2; // páginas ao redor da atual
                    $paginas_mostrar = [];

                    for ($p = 1; $p <= $total_paginas; $p++) {
                        if (
                            $p === 1 ||
                            $p === $total_paginas ||
                            ($p >= $pagina_atual_n - $janela && $p <= $pagina_atual_n + $janela)
                        ) {
                            $paginas_mostrar[] = $p;
                        }
                    }

                    $ultimo_exibido = null;
                    foreach ($paginas_mostrar as $p):
                        // Adiciona reticências quando há salto
                        if ($ultimo_exibido !== null && $p > $ultimo_exibido + 1):
                    ?>
                        <span class="pag-reticencias">…</span>
                    <?php endif; ?>
                        <a href="<?php echo $url_pag_base . '&pagina=' . $p; ?>"
                           class="pag-btn <?php echo $p === $pagina_atual_n ? 'ativo' : ''; ?>">
                            <?php echo $p; ?>
                        </a>
                    <?php
                        $ultimo_exibido = $p;
                    endforeach;
                    ?>

                    <!-- Próxima -->
                    <a href="<?php echo $url_pag_base . '&pagina=' . ($pagina_atual_n + 1); ?>"
                       class="pag-btn <?php echo $pagina_atual_n >= $total_paginas ? 'desabilitado' : ''; ?>">
                        Próxima →
                    </a>

                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ============================================================
         VIEW: KANBAN
         ============================================================ -->
    <?php else: ?>

    <div class="kanban-section">
        <div class="kanban-wrap" id="kanban">
            <?php foreach ($colunas_kanban as $status_key => $coluna): ?>
            <div class="kanban-coluna" id="col-<?php echo $status_key; ?>">
                <div class="coluna-header">
                    <div class="coluna-dot" style="background:<?php echo $coluna['cor']; ?>;"></div>
                    <span class="coluna-titulo"><?php echo $coluna['titulo']; ?></span>
                    <span class="coluna-count" id="count-<?php echo $status_key; ?>"><?php echo count($coluna['leads']); ?></span>
                </div>
                <div class="coluna-corpo" id="corpo-<?php echo $status_key; ?>" data-status="<?php echo $status_key; ?>">
                    <?php if (empty($coluna['leads'])): ?>
                        <div class="coluna-vazia">Nenhum lead aqui</div>
                    <?php endif; ?>
                    <?php foreach ($coluna['leads'] as $lead):
                        $prop_css = 'prop-'.str_replace(' ','-',$lead['tipo_proposta']??'');
                    ?>
                    <div class="kanban-card"
                         data-id="<?php echo $lead['id']; ?>"
                         data-possivel="<?php echo $lead['possivel_ganho']??0; ?>"
                         data-nome="<?php echo htmlspecialchars($lead['nome']); ?>"
                         draggable="<?php echo $pode_editar_leads ? 'true' : 'false'; ?>"
                         style="<?php echo !$pode_editar_leads ? 'cursor:default;' : ''; ?>"
                         onclick="abrirDrawer(<?php echo $lead['id']; ?>)">
                        <div class="kanban-card-linha" style="background:<?php echo $coluna['cor']; ?>;"></div>
                        <div class="card-nome-k"><?php echo htmlspecialchars($lead['nome']); ?></div>
                        <?php if (!empty($lead['tipo_proposta'])): ?>
                            <div><span class="proposta-tag <?php echo $prop_css; ?>"><?php echo htmlspecialchars($lead['tipo_proposta']); ?></span></div>
                        <?php endif; ?>
                        <div class="card-info-k">
                            <?php if ($lead['telefone']): ?>
                            <div class="card-info-linha">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91A16 16 0 0 0 13 14.85l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16.92z"/></svg>
                                <?php echo htmlspecialchars($lead['telefone']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if (($lead['possivel_ganho']??0) > 0): ?>
                            <div class="card-info-linha">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/></svg>
                                Possível: R$ <?php echo number_format($lead['possivel_ganho'],2,',','.'); ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($lead['valor']>0): ?>
                            <div class="card-info-linha" style="color:var(--verde);font-weight:600;">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" style="stroke:var(--verde);"><polyline points="20 6 9 17 4 12"/></svg>
                                R$ <?php echo number_format($lead['valor'],2,',','.'); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer-k">
                            <div>
                                <?php if (!empty($lead['etiqueta'])): $ec='etq-'.str_replace(' ','-',$lead['etiqueta']); ?>
                                    <span class="etiqueta-badge <?php echo $ec; ?>"><?php echo htmlspecialchars($lead['etiqueta']); ?></span>
                                <?php else: ?>
                                    <span style="font-size:11px;color:var(--text-3);"><?php echo date('d/m',strtotime($lead['criado_em'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if($pode_editar_leads): ?>
                            <a href="leads_editar.php?id=<?php echo $lead['id']; ?>" class="btn btn-secondary btn-sm" style="font-size:11px;padding:3px 8px;" onclick="event.stopPropagation()">Editar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php endif; ?>

</div>

<!-- ── Drawer lateral ── -->
<div class="drawer-overlay" id="drawer-overlay" onclick="fecharDrawer()"></div>
<div class="drawer" id="drawer">
    <div class="drawer-header" id="drawer-header">
        <div class="drawer-header-info">
            <div class="drawer-nome" id="drawer-nome">Carregando...</div>
            <div class="drawer-badges" id="drawer-badges"></div>
        </div>
        <button class="drawer-fechar" onclick="fecharDrawer()">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="drawer-corpo" id="drawer-corpo">
        <div class="drawer-loading"><div class="spinner"></div> Carregando...</div>
    </div>
    <div class="drawer-rodape" id="drawer-rodape" style="display:none;">
        <a href="#" id="btn-ir-editar" class="btn btn-primary" style="width:100%;justify-content:center;">✏️ Editar este lead</a>
    </div>
</div>

<!-- Modal fechamento kanban -->
<div class="modal-overlay" id="modal-fechado">
    <div class="modal-box">
        <div class="modal-titulo">🎉 Lead fechado!</div>
        <div class="modal-sub" id="modal-fechado-sub">Qual foi o valor acordado?</div>
        <div id="sugestao-wrap" style="display:none;margin-bottom:14px;">
            <div style="font-size:11px;color:var(--text-3);margin-bottom:6px;">Possível ganho estimado:</div>
            <button type="button" id="btn-usar-sugestao" class="btn btn-secondary btn-sm" onclick="usarSugestao()">
                Usar R$ <span id="sugestao-valor"></span>
            </button>
        </div>
        <div class="form-group">
            <label class="form-label">Valor acordado <span class="req">*</span></label>
            <div class="input-group">
                <span class="input-prefix">R$</span>
                <input class="form-control" type="number" id="modal-valor" placeholder="0.00" step="0.01" min="0">
            </div>
        </div>
        <div class="modal-acoes">
            <button class="btn btn-secondary" onclick="cancelarFechamento()">Cancelar</button>
            <button class="btn btn-primary" onclick="confirmarFechamento()">💰 Registrar</button>
        </div>
    </div>
</div>

<!-- Modais de ações em massa -->
<div class="modal-overlay" id="modal-etiqueta">
    <div class="modal-box">
        <div class="modal-titulo" style="margin-bottom:16px;">🏷 Adicionar etiqueta</div>
        <div class="etiquetas-grid">
            <?php foreach($etiquetas_disponiveis as $et): ?>
                <button class="etq-opcao" onclick="selecionarEtiqueta(this,'<?php echo $et; ?>')"><?php echo $et; ?></button>
            <?php endforeach; ?>
        </div>
        <div class="modal-acoes">
            <button class="btn btn-secondary btn-sm" onclick="fecharModal('modal-etiqueta')">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="confirmarEtiqueta()">Aplicar</button>
        </div>
    </div>
</div>
<div class="modal-overlay" id="modal-excluir">
    <div class="modal-box">
        <div class="modal-titulo" style="margin-bottom:12px;">Excluir selecionados</div>
        <div style="font-size:13px;color:var(--text-2);margin-bottom:16px;">
            Excluir <strong id="qtd-excluir" style="color:var(--vermelho);">0</strong> lead(s)? Não pode ser desfeito.
        </div>
        <div class="modal-acoes">
            <button class="btn btn-secondary btn-sm" onclick="fecharModal('modal-excluir')">Cancelar</button>
            <button class="btn btn-danger btn-sm" onclick="confirmarExclusao()">Excluir</button>
        </div>
    </div>
</div>

<!-- Forms ocultos -->
<form id="form-exportar" action="leads_massa.php" method="post" style="display:none;"><input type="hidden" name="acao" value="exportar_csv"><div id="inputs-exportar"></div></form>
<form id="form-etiqueta" action="leads_massa.php" method="post" style="display:none;"><input type="hidden" name="acao" value="etiqueta"><input type="hidden" name="etiqueta_valor" id="etiqueta-valor-hidden"><div id="inputs-etiqueta"></div></form>
<form id="form-excluir" action="leads_massa.php" method="post" style="display:none;"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="confirmar_exclusao" value="1"><div id="inputs-excluir"></div></form>

<div class="toast" id="toast"></div>

<script>
// ============================================================
// DRAWER
// ============================================================
var drawerAberto = false;
var statusMap = {
    'novo':'<span class="badge badge-novo">Novo</span>',
    'em_contato':'<span class="badge badge-contato">Em contato</span>',
    'proposta_enviada':'<span class="badge badge-proposta">Proposta enviada</span>',
    'negociacao':'<span class="badge badge-negociacao">Negociação</span>',
    'fechado':'<span class="badge badge-fechado">Fechado</span>',
    'perdido':'<span class="badge badge-perdido">Perdido</span>',
};
var tipoLabels = {'ligacao':'📞 Ligação','whatsapp':'💬 WhatsApp','email':'✉️ Email','reuniao':'🤝 Reunião','outro':'💡 Outro'};
var docIcones  = {'pdf':{cls:'icone-pdf',txt:'PDF'},'doc':{cls:'icone-word',txt:'DOC'},'docx':{cls:'icone-word',txt:'DOC'},'jpg':{cls:'icone-img',txt:'IMG'},'jpeg':{cls:'icone-img',txt:'IMG'},'png':{cls:'icone-img',txt:'IMG'}};

function abrirDrawer(leadId) {
    drawerAberto = true;
    document.getElementById('drawer-overlay').classList.add('aberto');
    document.getElementById('drawer').classList.add('aberto');
    document.getElementById('drawer-nome').textContent = 'Carregando...';
    document.getElementById('drawer-badges').innerHTML = '';
    document.getElementById('drawer-corpo').innerHTML = '<div class="drawer-loading"><div class="spinner"></div> Carregando...</div>';
    document.getElementById('drawer-rodape').style.display = 'none';
    fetch('lead_dados.php?id=' + leadId)
        .then(function(r){return r.json();})
        .then(function(data){if(data.erro){document.getElementById('drawer-corpo').innerHTML='<div class="drawer-loading">Erro.</div>';return;}montarDrawer(data);})
        .catch(function(){document.getElementById('drawer-corpo').innerHTML='<div class="drawer-loading">Erro de conexão.</div>';});
}

function montarDrawer(data) {
    var lead=data.lead, hist=data.historico, docs=data.documentos, log=data.log||[];
    document.getElementById('drawer-nome').textContent = lead.nome;
    var badges = (statusMap[lead.status]||'');
    if (lead.etiqueta) badges += ' <span class="etiqueta-badge etq-'+lead.etiqueta.replace(/ /g,'-')+'">'+lead.etiqueta+'</span>';
    if (lead.tipo_proposta) badges += ' <span class="proposta-tag prop-'+lead.tipo_proposta.replace(/ /g,'-')+'" style="font-size:10px;">'+lead.tipo_proposta+'</span>';
    document.getElementById('drawer-badges').innerHTML = badges;
    var html = '';

    // ── Informações ──
    html += '<div class="drawer-secao"><div class="drawer-secao-titulo">Informações</div><div class="dados-grid">';
    html += dadoItem('Telefone', lead.telefone||'—');
    html += dadoItem('Email', lead.email||'—');
    html += dadoItem('Origem', lead.origem||'—');
    html += dadoItem('Cadastrado em', formatarData(lead.criado_em));
    if (lead.possivel_ganho > 0) html += dadoItem('Possível ganho', 'R$ '+formatarMoeda(lead.possivel_ganho));
    if (lead.status==='fechado'&&lead.valor>0) html += dadoItem('Valor fechado', 'R$ '+formatarMoeda(lead.valor));
    html += '</div></div>';

    // ── Observações ──
    if (lead.observacoes&&lead.observacoes.trim()) {
        html += '<div class="drawer-secao"><div class="drawer-secao-titulo">Observações</div><div class="obs-texto">'+escHtml(lead.observacoes)+'</div></div>';
    }

    // ── Histórico ──
    html += '<div class="drawer-secao"><div class="drawer-secao-titulo">Histórico ('+hist.length+')</div>';
    if (hist.length===0) { html += '<div style="font-size:12px;color:var(--text-3);">Nenhum contato registrado</div>'; }
    else {
        html += '<div class="mini-timeline">';
        hist.forEach(function(h){
            html += '<div class="mini-item"><div class="mini-dot dot-'+h.tipo+'"></div><div class="mini-card"><div class="mini-header"><span class="mini-tipo">'+(tipoLabels[h.tipo]||h.tipo)+'</span><span class="mini-data">'+formatarDataHora(h.data_hora)+'</span></div>';
            if(h.anotacao) html += '<div class="mini-anotacao">'+escHtml(h.anotacao)+'</div>';
            if(h.proximo)  html += '<div class="mini-proximo">'+escHtml(h.proximo)+'</div>';
            html += '</div></div>';
        });
        html += '</div>';
    }
    html += '</div>';

    // ── Documentos ──
    html += '<div class="drawer-secao"><div class="drawer-secao-titulo">Documentos ('+docs.length+')</div>';
    if (docs.length===0) { html += '<div style="font-size:12px;color:var(--text-3);">Nenhum documento</div>'; }
    else {
        docs.forEach(function(d,i){
            var ic=docIcones[d.extensao]||{cls:'icone-outro',txt:(d.extensao||'?').toUpperCase()};
            var url='uploads/'+encodeURIComponent(d.nome_arquivo);
            var temPrevia=['jpg','jpeg','png','gif','pdf'].indexOf(d.extensao)!==-1;
            var pid='previa-'+i;
            html += '<div class="mini-doc"><div class="mini-doc-icone '+ic.cls+'">'+ic.txt+'</div>';
            html += '<div style="flex:1;min-width:0;"><div class="mini-doc-nome">'+escHtml(d.nome_original)+'</div><div class="mini-doc-tam">'+d.tamanho_fmt+' · '+d.criado_em+'</div></div>';
            html += '<div style="display:flex;gap:4px;">';
            if(temPrevia) html += '<button class="btn btn-secondary btn-sm" onclick="togglePrevia(\''+pid+'\',this);event.stopPropagation();">👁 Ver</button>';
            html += '<a href="'+url+'" download="'+escHtml(d.nome_original)+'" class="btn btn-secondary btn-sm" onclick="event.stopPropagation()">↓</a>';
            html += '</div></div>';
            if(temPrevia){
                html += '<div class="doc-previa-wrap"><div class="doc-previa-area" id="'+pid+'">';
                if(d.extensao==='pdf') html += '<iframe class="doc-previa-pdf" src="'+url+'#toolbar=0"></iframe>';
                else html += '<img class="doc-previa-img" src="'+url+'" loading="lazy">';
                html += '</div></div>';
            }
        });
    }
    html += '</div>';

    // ── Log de atividades ──
    html += '<div class="drawer-secao">';
    html += '<div class="drawer-secao-titulo">Atividades ('+log.length+')</div>';
    if (log.length === 0) {
        html += '<div style="font-size:12px;color:var(--text-3);">Nenhuma atividade registrada</div>';
    } else {
        html += '<div style="display:flex;flex-direction:column;gap:0;">';
        log.forEach(function(entry, i) {
            var linha = '<div style="display:flex;align-items:flex-start;gap:10px;padding:9px 0;'
                + (i < log.length-1 ? 'border-bottom:1px solid var(--border);' : '') + '">';

            // Bolinha colorida
            linha += '<div style="width:28px;height:28px;border-radius:50%;background:'+entry.bg
                   + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;">'
                   + '<svg viewBox="0 0 24 24" fill="none" stroke="'+entry.fg+'" stroke-width="2" width="12" height="12">'
                   + iconeLogJS(entry.acao) + '</svg></div>';

            // Texto
            linha += '<div style="flex:1;min-width:0;">';
            linha += '<div style="font-size:12px;color:var(--text-1);line-height:1.4;">'
                   + '<strong style="font-weight:700;">'+escHtml(entry.usuario)+'</strong> '
                   + escHtml(entry.texto) + '</div>';
            if (entry.detalhe) {
                linha += '<div style="font-size:11px;color:var(--text-3);background:var(--surface-2);'
                       + 'border:1px solid var(--border);border-radius:5px;padding:2px 7px;'
                       + 'display:inline-block;margin-top:3px;">'
                       + escHtml(entry.detalhe) + '</div>';
            }
            linha += '</div>';

            // Data
            linha += '<div style="font-size:10px;color:var(--text-3);white-space:nowrap;padding-top:2px;">'
                   + escHtml(entry.quando) + '</div>';

            linha += '</div>';
            html += linha;
        });
        html += '</div>';
    }
    html += '</div>';

    document.getElementById('drawer-corpo').innerHTML = html;
    document.getElementById('btn-ir-editar').href = 'leads_editar.php?id='+lead.id;
    document.getElementById('drawer-rodape').style.display = 'block';
}

function fecharDrawer() { drawerAberto=false; document.getElementById('drawer-overlay').classList.remove('aberto'); document.getElementById('drawer').classList.remove('aberto'); }
function togglePrevia(id,btn) { var a=document.getElementById(id); if(!a)return; var ab=a.classList.toggle('aberta'); btn.textContent=ab?'✕ Fechar':'👁 Ver'; }
document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&drawerAberto) fecharDrawer(); });

function dadoItem(l,v){return '<div><div class="dado-label">'+l+'</div><div class="dado-valor">'+escHtml(String(v))+'</div></div>';}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/\n/g,'<br>');}
function formatarData(s){if(!s)return'—';var p=s.split(' ')[0].split('-');return p[2]+'/'+p[1]+'/'+p[0];}
function formatarDataHora(s){if(!s)return'—';var p=s.split(' '),d=p[0].split('-'),h=p[1]?p[1].substring(0,5):'';return d[2]+'/'+d[1]+'/'+d[0]+(h?' às '+h:'');}
function formatarMoeda(v){return parseFloat(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}

// Ícones SVG por tipo de ação do log
function iconeLogJS(acao) {
    var icons = {
        'criou':            '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'editou':           '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'status_alterado':  '<polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/>',
        'valor_alterado':   '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/>',
        'fechou':           '<polyline points="20 6 9 17 4 12"/>',
        'arquivo_enviado':  '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'arquivo_removido': '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'lembrete_criado':  '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    };
    return icons[acao] || icons['editou'];
}

// ============================================================
// KANBAN — drag and drop
// ============================================================
var podeEditarLeads = <?php echo $pode_editar_leads ? 'true' : 'false'; ?>;
var cardArrastando = null, dadosFechamento = null;
var autoScrollTimer = null; // timer do auto-scroll

// ── Auto-scroll durante o drag ──
// Detecta quando o mouse está perto das bordas e rola a página
var SCROLL_ZONA = 120;  // pixels da borda que ativam o scroll
var SCROLL_SPEED = 14;  // pixels por frame

function iniciarAutoScroll(e) {
    pararAutoScroll();
    var y = e.clientY;
    var alturaJanela = window.innerHeight;

    if (y < SCROLL_ZONA) {
        // Próximo do topo — sobe
        var intensidade = Math.max(4, SCROLL_SPEED * (1 - y / SCROLL_ZONA));
        autoScrollTimer = setInterval(function() {
            window.scrollBy(0, -intensidade);
        }, 16); // ~60fps
    } else if (y > alturaJanela - SCROLL_ZONA) {
        // Próximo do rodapé — desce
        var intensidade = Math.max(4, SCROLL_SPEED * (1 - (alturaJanela - y) / SCROLL_ZONA));
        autoScrollTimer = setInterval(function() {
            window.scrollBy(0, intensidade);
        }, 16);
    }
}

function pararAutoScroll() {
    if (autoScrollTimer) {
        clearInterval(autoScrollTimer);
        autoScrollTimer = null;
    }
}

// Registra o movimento do mouse durante qualquer drag na página
document.addEventListener('dragover', function(e) {
    if (cardArrastando) iniciarAutoScroll(e);
});
document.addEventListener('dragend', pararAutoScroll);
document.addEventListener('drop', pararAutoScroll);

// ── Cards: inicializar drag ──
function inicializarCards() {
    document.querySelectorAll('.kanban-card').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            if (!podeEditarLeads) { e.preventDefault(); return false; }
            cardArrastando = card;
            card.classList.add('dragging');
            e.dataTransfer.setData('text/plain', card.dataset.id);
            e.dataTransfer.effectAllowed = 'move';
            e.stopPropagation();
            // Destaca todas as colunas como alvos potenciais
            document.querySelectorAll('.kanban-coluna').forEach(function(col) {
                col.classList.add('drop-alvo');
            });
        });
        card.addEventListener('dragend', function() {
            card.classList.remove('dragging');
            document.querySelectorAll('.coluna-corpo').forEach(function(c) {
                c.classList.remove('drag-over');
            });
            document.querySelectorAll('.kanban-coluna').forEach(function(col) {
                col.classList.remove('drop-alvo', 'drop-alvo-ativo');
            });
            cardArrastando = null;
            pararAutoScroll();
        });
        card.addEventListener('click', function(e) {
            if (card.classList.contains('dragging')) {
                e.stopPropagation(); e.preventDefault();
            }
        });
    });
}

// ── Colunas: aceitar drop ──
// Registra tanto no coluna-corpo quanto no coluna-header
// para que a área de drop seja a coluna inteira
function registrarDropZone(elemento, colunaCorpo) {
    elemento.addEventListener('dragover', function(e) {
        if (!podeEditarLeads || !cardArrastando) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        // Destaca o corpo da coluna
        document.querySelectorAll('.coluna-corpo').forEach(function(c) {
            c.classList.remove('drag-over');
        });
        colunaCorpo.classList.add('drag-over');
        // Destaca a coluna inteira
        var kanbanColuna = colunaCorpo.closest('.kanban-coluna');
        document.querySelectorAll('.kanban-coluna').forEach(function(c) {
            c.classList.remove('drop-alvo-ativo');
        });
        if (kanbanColuna) kanbanColuna.classList.add('drop-alvo-ativo');
    });

    elemento.addEventListener('dragleave', function(e) {
        // Só remove se o mouse saiu para fora da coluna inteira
        var kanbanColuna = colunaCorpo.closest('.kanban-coluna');
        if (kanbanColuna && kanbanColuna.contains(e.relatedTarget)) return;
        colunaCorpo.classList.remove('drag-over');
        if (kanbanColuna) kanbanColuna.classList.remove('drop-alvo-ativo');
    });

    elemento.addEventListener('drop', function(e) {
        e.preventDefault();
        colunaCorpo.classList.remove('drag-over');
        var kanbanColuna = colunaCorpo.closest('.kanban-coluna');
        if (kanbanColuna) kanbanColuna.classList.remove('drop-alvo-ativo');
        if (!cardArrastando || !podeEditarLeads) return;
        var novoStatus = colunaCorpo.dataset.status;
        var colunaAtual = cardArrastando.closest('.coluna-corpo');
        var statusAtual = colunaAtual ? colunaAtual.dataset.status : null;
        if (statusAtual === novoStatus) return;
        if (novoStatus === 'fechado') {
            var possivel = parseFloat(cardArrastando.dataset.possivel) || 0;
            dadosFechamento = {
                card: cardArrastando, colunaDestino: colunaCorpo,
                colunaOrigem: colunaAtual, leadId: cardArrastando.dataset.id,
                leadNome: cardArrastando.dataset.nome, possivel: possivel
            };
            abrirModalFechado(dadosFechamento);
        } else {
            moverCard(cardArrastando, colunaCorpo, colunaAtual, novoStatus, 0);
        }
        pararAutoScroll();
    });
}

// Registra drop no corpo E no header de cada coluna
document.querySelectorAll('.kanban-coluna').forEach(function(kanbanColuna) {
    var corpo  = kanbanColuna.querySelector('.coluna-corpo');
    var header = kanbanColuna.querySelector('.coluna-header');
    if (corpo)  registrarDropZone(corpo, corpo);
    if (header) registrarDropZone(header, corpo);
    // Também registra na coluna inteira para pegar espaços vazios
    registrarDropZone(kanbanColuna, corpo);
});

function abrirModalFechado(d){
    document.getElementById('modal-fechado-sub').textContent='Qual foi o valor acordado com '+d.leadNome+'?';
    if(d.possivel>0){document.getElementById('sugestao-valor').textContent=d.possivel.toLocaleString('pt-BR',{minimumFractionDigits:2});document.getElementById('sugestao-wrap').style.display='block';document.getElementById('modal-valor').value=d.possivel;}
    else{document.getElementById('sugestao-wrap').style.display='none';document.getElementById('modal-valor').value='';}
    document.getElementById('modal-fechado').classList.add('aberto');
    setTimeout(function(){document.getElementById('modal-valor').focus();document.getElementById('modal-valor').select();},100);
}
function usarSugestao(){if(dadosFechamento){document.getElementById('modal-valor').value=dadosFechamento.possivel;document.getElementById('modal-valor').focus();}}
function confirmarFechamento(){
    var v=parseFloat(document.getElementById('modal-valor').value)||0;
    if(v<=0){mostrarToast('Digite o valor');return;}
    document.getElementById('modal-fechado').classList.remove('aberto');
    moverCard(dadosFechamento.card,dadosFechamento.colunaDestino,dadosFechamento.colunaOrigem,'fechado',v);
    dadosFechamento=null;
}
function cancelarFechamento(){document.getElementById('modal-fechado').classList.remove('aberto');dadosFechamento=null;}

function moverCard(card,dest,orig,novoStatus,valorVenda){
    var statusOrig=orig?orig.dataset.status:null;
    var vazia=dest.querySelector('.coluna-vazia'); if(vazia)vazia.remove();
    dest.appendChild(card);
    var cor=document.querySelector('#col-'+novoStatus+' .coluna-dot').style.background;
    card.querySelector('.kanban-card-linha').style.background=cor;
    if(statusOrig)atualizarContador(statusOrig,-1);
    atualizarContador(novoStatus,+1);
    if(orig&&orig.querySelectorAll('.kanban-card').length===0){var d=document.createElement('div');d.className='coluna-vazia';d.textContent='Nenhum lead aqui';orig.appendChild(d);}
    if(novoStatus==='fechado'&&valorVenda>0){var fmt=valorVenda.toLocaleString('pt-BR',{minimumFractionDigits:2});var li=card.querySelector('.linha-valor-k');if(li)li.remove();var n=document.createElement('div');n.className='card-info-linha linha-valor-k';n.style.cssText='color:var(--verde);font-weight:600;';n.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke-width="2" style="stroke:var(--verde);width:11px;height:11px;"><polyline points="20 6 9 17 4 12"/></svg> R$ '+fmt;card.querySelector('.card-info-k').appendChild(n);}
    salvarStatusAjax(card.dataset.id,novoStatus,valorVenda);
}

function atualizarContador(s,d){var e=document.getElementById('count-'+s);if(e)e.textContent=Math.max(0,(parseInt(e.textContent)||0)+d);}

function salvarStatusAjax(leadId,novoStatus,valorVenda){
    fetch('leads.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'atualizar_status=1&lead_id='+leadId+'&novo_status='+novoStatus+'&valor_venda='+(valorVenda||0)})
    .then(function(r){return r.json();})
    .then(function(data){mostrarToast(data.ok?'✓ Salvo!':'Erro ao salvar');})
    .catch(function(){mostrarToast('Erro de conexão');});
}

document.getElementById('modal-fechado').addEventListener('click',function(e){if(e.target===this)cancelarFechamento();});
document.getElementById('modal-valor').addEventListener('keydown',function(e){if(e.key==='Enter')confirmarFechamento();});
inicializarCards();

// ============================================================
// SELEÇÃO EM MASSA (tabela)
// ============================================================
var modoSelecao=false, etiquetaSel='';
function toggleSelecao(){
    modoSelecao=!modoSelecao;
    var btn=document.getElementById('btn-selecionar');
    document.querySelectorAll('.col-sel').forEach(function(c){c.style.display=modoSelecao?'table-cell':'none';});
    if(modoSelecao){btn.textContent='Cancelar';btn.style.color='var(--vermelho)';btn.style.borderColor='#FECACA';}
    else{btn.textContent='Selecionar';btn.style.color='';btn.style.borderColor='';desmarcarTodos();}
}
function selecionarTodos(m){document.querySelectorAll('.cb-linha').forEach(function(cb){cb.checked=m;cb.closest('tr').classList.toggle('selecionada',m);});atualizarSelecao();}
function desmarcarTodos(){document.querySelectorAll('.cb-linha').forEach(function(cb){cb.checked=false;cb.closest('tr').classList.remove('selecionada');});var ct=document.getElementById('cb-todos');if(ct)ct.checked=false;atualizarSelecao();}
function atualizarSelecao(){var m=document.querySelectorAll('.cb-linha:checked');document.getElementById('info-selecionados').textContent=m.length+' selecionado(s)';document.getElementById('barra-massa').classList.toggle('visivel',m.length>0);document.querySelectorAll('.cb-linha').forEach(function(cb){cb.closest('tr').classList.toggle('selecionada',cb.checked);});}
function getIdsSelecionados(){var ids=[];document.querySelectorAll('.cb-linha:checked').forEach(function(cb){ids.push(cb.value);});return ids;}
function preencherInputsIds(cid,ids){var c=document.getElementById(cid);c.innerHTML='';ids.forEach(function(id){var i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=id;c.appendChild(i);});}
function exportarCSV(){var ids=getIdsSelecionados();if(!ids.length)return;preencherInputsIds('inputs-exportar',ids);document.getElementById('form-exportar').submit();}
function abrirModalEtiqueta(){if(!getIdsSelecionados().length)return;etiquetaSel='';document.querySelectorAll('.etq-opcao').forEach(function(b){b.classList.remove('marcada');});document.getElementById('modal-etiqueta').classList.add('aberto');}
function selecionarEtiqueta(btn,v){document.querySelectorAll('.etq-opcao').forEach(function(b){b.classList.remove('marcada');});btn.classList.add('marcada');etiquetaSel=v;}
function confirmarEtiqueta(){if(!etiquetaSel){alert('Selecione uma etiqueta.');return;}preencherInputsIds('inputs-etiqueta',getIdsSelecionados());document.getElementById('etiqueta-valor-hidden').value=etiquetaSel;document.getElementById('form-etiqueta').submit();fecharModal('modal-etiqueta');}
function abrirModalExcluir(){var ids=getIdsSelecionados();if(!ids.length)return;document.getElementById('qtd-excluir').textContent=ids.length;document.getElementById('modal-excluir').classList.add('aberto');}
function confirmarExclusao(){preencherInputsIds('inputs-excluir',getIdsSelecionados());document.getElementById('form-excluir').submit();fecharModal('modal-excluir');}
function fecharModal(id){document.getElementById(id).classList.remove('aberto');}
document.querySelectorAll('.modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)o.classList.remove('aberto');});});

var toastTimer=null;
function mostrarToast(msg){var t=document.getElementById('toast');t.textContent=msg;t.classList.add('visivel');if(toastTimer)clearTimeout(toastTimer);toastTimer=setTimeout(function(){t.classList.remove('visivel');},2500);}
</script>

</body>
</html>