<?php
// ============================================================
// ARQUIVO: colaboradores.php
// O QUE FAZ: Gerenciar colaboradores, permissões e filtros
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_login();
if (!tem_permissao('gerenciar_colab')) { header("Location: sem_permissao.php"); exit(); }

$pagina_atual = 'colaboradores';
$msg = ""; $tipo_msg = "";

// ============================================================
// AÇÃO: Salvar permissões
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_permissoes'])) {
    $cid    = (int)$_POST['colab_id'];
    $meu_id = (int)($_SESSION['colab_id'] ?? 0);

    if (!eh_admin() && $cid === $meu_id) {
        $msg = "Você não pode editar suas próprias permissões.";
        $tipo_msg = "error";
    } elseif ($cid > 0) {
        // Verifica se o colaborador está bloqueado
        $chk = mysqli_query($conexao, "SELECT status FROM colaboradores WHERE id=$cid");
        $chk_row = mysqli_fetch_assoc($chk);
        if ($chk_row && $chk_row['status'] === 'bloqueado') {
            $msg = "Reative o colaborador antes de editar as permissões.";
            $tipo_msg = "error";
        } else {
        $vd = isset($_POST['ver_dashboard'])     ? 1 : 0;
        $vl = isset($_POST['ver_leads'])         ? 1 : 0;
        $vf = isset($_POST['ver_financeiro'])    ? 1 : 0;
        $el = isset($_POST['editar_leads'])      ? 1 : 0;
        $ef = isset($_POST['editar_financeiro']) ? 1 : 0;
        $gc = isset($_POST['gerenciar_colab'])   ? 1 : 0;

        mysqli_query($conexao,
            "UPDATE permissoes SET
                ver_dashboard=$vd, ver_leads=$vl, ver_financeiro=$vf,
                editar_leads=$el, editar_financeiro=$ef, gerenciar_colab=$gc
             WHERE colaborador_id_fk=$cid"
        );

        // Ativa se tem ao menos uma permissão de visualização
        if ($vd || $vl || $vf) {
            mysqli_query($conexao, "UPDATE colaboradores SET status='ativo' WHERE id=$cid");
        }

        $msg = "Permissões de colaborador #$cid atualizadas!";
        $tipo_msg = "success";

        // Envia e-mail com as permissões atualizadas
        enviar_email_colab($conexao, $cid, 'permissoes', [
            'ver_dashboard'     => $vd,
            'ver_leads'         => $vl,
            'ver_financeiro'    => $vf,
            'editar_leads'      => $el,
            'editar_financeiro' => $ef,
            'gerenciar_colab'   => $gc,
        ]);
        } // fim else (não bloqueado)
    }
}

// ============================================================
// FUNÇÃO AUXILIAR: Envia e-mail ao colaborador
// ============================================================
function enviar_email_colab($conexao, $cid, $tipo, $perms_array = []) {
    $r = mysqli_query($conexao,
        "SELECT nome_completo, email, colaborador_id FROM colaboradores WHERE id=$cid"
    );
    if (!$r || mysqli_num_rows($r) === 0) return;
    $c     = mysqli_fetch_assoc($r);
    $nome  = explode(' ', $c['nome_completo'])[0];
    $id    = $c['colaborador_id'];
    $email = $c['email'];

    $nomes_perms = [
        'ver_dashboard'     => 'Visualizar Dashboard',
        'ver_leads'         => 'Visualizar Leads',
        'ver_financeiro'    => 'Visualizar Financeiro',
        'editar_leads'      => 'Editar Leads',
        'editar_financeiro' => 'Adicionar Despesas',
        'gerenciar_colab'   => 'Gerenciar Colaboradores',
    ];

    if ($tipo === 'permissoes') {
        $lista = [];
        foreach ($nomes_perms as $chave => $rotulo) {
            if (!empty($perms_array[$chave])) $lista[] = '  ✓ ' . $rotulo;
        }
        $lista_txt = !empty($lista)
            ? implode("\n", $lista)
            : '  (nenhuma permissão ativa no momento)';

        $assunto = "Norion CRM — Suas permissões foram atualizadas";
        $corpo   = "Olá, {$nome}!\n\n"
            . "Suas permissões no Norion CRM foram atualizadas.\n\n"
            . "ID de acesso: {$id}\n\n"
            . "Permissões ativas:\n{$lista_txt}\n\n"
            . "Acesse agora: http://localhost/norion-crm/\n\n"
            . "— Norion Systems";

    } elseif ($tipo === 'bloqueado') {
        $assunto = "Norion CRM — Sua conta foi bloqueada";
        $corpo   = "Olá, {$nome}!\n\n"
            . "Sua conta no Norion CRM foi bloqueada pelo administrador.\n"
            . "Entre em contato com a Norion Systems para mais informações.\n\n"
            . "ID: {$id}\n\n"
            . "— Norion Systems";

    } elseif ($tipo === 'reativado') {
        $assunto = "Norion CRM — Sua conta foi reativada";
        $corpo   = "Olá, {$nome}!\n\n"
            . "Sua conta no Norion CRM foi reativada!\n"
            . "Faça login com seu ID para acessar o sistema.\n\n"
            . "ID de acesso: {$id}\n"
            . "Link: http://localhost/norion-crm/\n\n"
            . "— Norion Systems";
    } else {
        return;
    }

    $headers = "From: noreply@norion.com.br\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($email, $assunto, $corpo, $headers);
}

// ============================================================
// AÇÃO: Bloquear / ativar
// ============================================================
if (isset($_GET['bloquear']) && is_numeric($_GET['bloquear'])) {
    $cid = (int)$_GET['bloquear'];
    mysqli_query($conexao, "UPDATE colaboradores SET status='bloqueado' WHERE id=$cid");
    enviar_email_colab($conexao, $cid, 'bloqueado');
    $msg = "Colaborador bloqueado."; $tipo_msg = "success";
}
if (isset($_GET['ativar']) && is_numeric($_GET['ativar'])) {
    $cid = (int)$_GET['ativar'];
    mysqli_query($conexao, "UPDATE colaboradores SET status='ativo' WHERE id=$cid");
    enviar_email_colab($conexao, $cid, 'reativado');
    $msg = "Colaborador reativado."; $tipo_msg = "success";
}

// ============================================================
// FILTROS
// ============================================================
$filtro_status = trim($_GET['status']    ?? '');
$filtro_perm   = trim($_GET['permissao'] ?? '');
$filtro_cargo  = trim($_GET['cargo']     ?? '');
$filtro_busca  = trim($_GET['busca']     ?? '');
$tem_filtro    = $filtro_status || $filtro_perm || $filtro_cargo || $filtro_busca;

$perms_disponiveis = [
    'ver_dashboard'     => 'Ver Dashboard',
    'ver_leads'         => 'Ver Leads',
    'ver_financeiro'    => 'Ver Financeiro',
    'editar_leads'      => 'Editar Leads',
    'editar_financeiro' => 'Add Despesas',
    'gerenciar_colab'   => 'Gerenciar Colabs',
];

$where = "WHERE 1=1";
if (!empty($filtro_status)) {
    $fs = mysqli_real_escape_string($conexao, $filtro_status);
    $where .= " AND c.status = '$fs'";
}
if (!empty($filtro_busca)) {
    $fb = mysqli_real_escape_string($conexao, $filtro_busca);
    $where .= " AND (c.nome_completo LIKE '%$fb%' OR c.colaborador_id LIKE '%$fb%' OR c.email LIKE '%$fb%' OR c.cargo LIKE '%$fb%')";
}
if (!empty($filtro_perm) && array_key_exists($filtro_perm, $perms_disponiveis)) {
    $where .= " AND p.$filtro_perm = 1";
}
if (!empty($filtro_cargo)) {
    $fc = mysqli_real_escape_string($conexao, $filtro_cargo);
    $where .= " AND c.cargo = '$fc'";
}

// ============================================================
// BUSCANDO COLABORADORES
// ============================================================
$colaboradores = mysqli_query($conexao,
    "SELECT c.*, p.ver_dashboard, p.ver_leads, p.ver_financeiro,
            p.editar_leads, p.editar_financeiro, p.gerenciar_colab
     FROM colaboradores c
     LEFT JOIN permissoes p ON p.colaborador_id_fk = c.id
     $where
     ORDER BY
        CASE c.status WHEN 'pendente' THEN 0 WHEN 'ativo' THEN 1 ELSE 2 END,
        c.criado_em DESC"
);
$total_filtrado = mysqli_num_rows($colaboradores);

// Contadores para os badges de status
$cnt = mysqli_query($conexao,
    "SELECT status, COUNT(*) as qtd FROM colaboradores GROUP BY status"
);
$contadores = ['todos'=>0,'pendente'=>0,'ativo'=>0,'bloqueado'=>0];
while ($row = mysqli_fetch_assoc($cnt)) {
    $contadores[$row['status']] = (int)$row['qtd'];
    $contadores['todos'] += (int)$row['qtd'];
}

// Contagem por cargo (para o dashboard)
$cargos_cnt_r = mysqli_query($conexao,
    "SELECT
        COALESCE(NULLIF(cargo,''), 'Sem cargo') as cargo,
        COUNT(*) as qtd
     FROM colaboradores
     GROUP BY cargo
     ORDER BY qtd DESC, cargo ASC"
);
$cargos_dashboard = [];
while ($row = mysqli_fetch_assoc($cargos_cnt_r)) {
    $cargos_dashboard[] = $row;
}

// Colaborador mais recente
$ultimo_r   = mysqli_query($conexao, "SELECT nome_completo, criado_em FROM colaboradores ORDER BY criado_em DESC LIMIT 1");
$ultimo_col = mysqli_fetch_assoc($ultimo_r);

// Total de permissões avançadas ativas
$av_r = mysqli_query($conexao,
    "SELECT COUNT(*) as t FROM permissoes
     WHERE editar_leads=1 OR editar_financeiro=1 OR gerenciar_colab=1"
);
$total_avancados = (int)(mysqli_fetch_assoc($av_r)['t'] ?? 0);

// Cargos distintos para o filtro de cargo
$cargos_r = mysqli_query($conexao,
    "SELECT DISTINCT cargo FROM colaboradores WHERE cargo != '' AND cargo IS NOT NULL ORDER BY cargo"
);
$cargos = [];
while ($row = mysqli_fetch_assoc($cargos_r)) {
    $cargos[] = $row['cargo'];
}

// URL base sem os filtros (para montar os links dos pills)
function url_filtro($params) {
    $base = array_filter([
        'status'    => $_GET['status']    ?? '',
        'permissao' => $_GET['permissao'] ?? '',
        'cargo'     => $_GET['cargo']     ?? '',
        'busca'     => $_GET['busca']     ?? '',
    ]);
    $merged = array_merge($base, $params);
    $merged = array_filter($merged); // remove vazios
    return 'colaboradores.php' . (!empty($merged) ? '?' . http_build_query($merged) : '');
}
?>
<!DOCTYPE html>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Colaboradores</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Dashboard de colaboradores ── */
        .colab-dash {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            background: var(--border);
            border-bottom: 1px solid var(--border);
        }
        .dash-bloco {
            background: var(--surface);
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .dash-numero {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1;
            color: var(--text-1);
        }
        .dash-label {
            font-size: 11px;
            color: var(--text-3);
            font-weight: 500;
        }
        .dash-sub {
            font-size: 11px;
            color: var(--text-3);
            margin-top: 2px;
        }
        .dash-numero.azul    { color: var(--azul); }
        .dash-numero.verde   { color: var(--verde); }
        .dash-numero.vermelho{ color: var(--vermelho); }
        .dash-numero.amarelo { color: var(--amarelo); }

        /* Card de cargos */
        .cargos-bloco {
            background: var(--surface);
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }
        .cargos-titulo {
            font-size: 11px; font-weight: 700; color: var(--text-3);
            text-transform: uppercase; letter-spacing: 0.6px;
            margin-bottom: 12px;
        }
        .cargos-lista { display: flex; flex-direction: column; gap: 8px; }
        .cargo-item {
            display: flex; align-items: center; gap: 10px;
        }
        .cargo-nome {
            font-size: 13px; font-weight: 600; color: var(--text-1);
            min-width: 120px;
        }
        .cargo-barra-wrap {
            flex: 1; height: 6px;
            background: var(--border); border-radius: 10px; overflow: hidden;
        }
        .cargo-barra {
            height: 100%; border-radius: 10px;
            background: var(--azul);
            transition: width 0.6s ease;
        }
        .cargo-qtd {
            font-size: 12px; font-weight: 700; color: var(--text-2);
            min-width: 20px; text-align: right;
        }

        /* ── Barra de filtros ── */
        .filtros-colab {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--surface-2);
        }
        .filtro-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
            border: 1px solid var(--border-2);
            background: var(--surface); color: var(--text-2);
            text-decoration: none; cursor: pointer;
            transition: all 0.15s; white-space: nowrap;
        }
        .filtro-pill:hover     { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
        .filtro-pill.ativo     { border-color:var(--azul); background:var(--azul); color:white; }
        .filtro-pill.verde     { border-color:var(--verde); background:var(--verde); color:white; }
        .filtro-pill.vermelho  { border-color:var(--vermelho); background:var(--vermelho); color:white; }
        .filtro-pill.amarelo   { border-color:var(--amarelo); background:var(--amarelo); color:white; }
        .filtro-num {
            background: rgba(255,255,255,0.25);
            border-radius: 20px; padding: 0 5px;
            font-size: 10px; font-weight: 800;
        }
        .filtro-num-dark {
            background: var(--surface-2); border-radius: 20px;
            padding: 0 6px; font-size: 10px;
            font-weight: 700; color: var(--text-3);
        }
        .filtro-sep { width:1px; height:20px; background:var(--border); flex-shrink:0; }

        /* Busca à direita */
        .filtro-busca-wrap { position:relative; margin-left:auto; }
        .filtro-busca {
            height:34px; border:1px solid var(--border-2); border-radius:var(--radius);
            padding:0 12px 0 32px; font-size:12px; font-family:'Manrope',sans-serif;
            color:var(--text-1); background:var(--surface); outline:none; width:190px; transition:border-color 0.15s;
        }
        .filtro-busca:focus { border-color:var(--azul); }
        .filtro-busca-icone { position:absolute; left:9px; top:50%; transform:translateY(-50%); width:13px; height:13px; stroke:var(--text-3); pointer-events:none; }

        /* Resultado da busca */
        .filtros-info {
            padding: 10px 20px;
            font-size: 12px; color: var(--text-3);
            display: flex; align-items: center; gap: 8px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }
        .filtros-info strong { color: var(--text-1); }
        .limpar-link { color:var(--vermelho); text-decoration:none; font-weight:600; font-size:11px; }
        .limpar-link:hover { text-decoration:underline; }

        /* ── Card de colaborador ── */
        .colab-card {
            background:var(--surface); border:1px solid var(--border);
            border-radius:var(--radius-lg); padding:20px 22px; margin-bottom:14px;
        }
        .colab-card.pendente  { border-left:3px solid var(--azul); }
        .colab-card.ativo     { border-left:3px solid var(--verde); }
        .colab-card.bloqueado { border-left:3px solid var(--vermelho); opacity:0.75; }
        .colab-header { display:flex; align-items:center; gap:14px; margin-bottom:16px; }
        .colab-avatar {
            width:42px; height:42px; border-radius:50%;
            background:var(--azul-light); color:var(--azul);
            display:flex; align-items:center; justify-content:center;
            font-size:16px; font-weight:800; flex-shrink:0;
        }
        .colab-info { flex:1; min-width:0; }
        .colab-nome { font-size:15px; font-weight:700; color:var(--text-1); }
        .colab-meta { font-size:12px; color:var(--text-3); margin-top:2px; }
        .colab-id   { font-family:monospace; font-weight:700; color:var(--azul); }
        .cargo-badge {
            display:inline-block; padding:1px 8px; border-radius:20px;
            background:var(--surface-2); border:1px solid var(--border);
            font-size:11px; font-weight:600; color:var(--text-2);
            margin-left:6px;
        }
        .status-badge { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
        .sb-pendente  { background:var(--azul-light); color:#0055B3; }
        .sb-ativo     { background:var(--verde-light); color:var(--verde-text); }
        .sb-bloqueado { background:var(--vermelho-light); color:var(--vermelho-text); }

        /* ── Permissões ── */
        .perm-titulo { font-size:11px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; }
        .perm-grid   { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:8px; }
        .perm-item {
            display:flex; align-items:center; gap:8px;
            padding:8px 10px; border:1px solid var(--border);
            border-radius:var(--radius); cursor:pointer; transition:all 0.15s;
            background:var(--surface-2);
        }
        .perm-item:hover { border-color:var(--azul); background:var(--azul-light); }
        .perm-item input[type="checkbox"] { width:15px; height:15px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; }
        .perm-item span { font-size:12px; font-weight:600; color:var(--text-2); cursor:pointer; line-height:1.3; }
        .avancado-titulo {
            font-size:10px; font-weight:700; color:var(--amarelo-text);
            text-transform:uppercase; letter-spacing:0.5px;
            display:flex; align-items:center; gap:4px; margin:10px 0 6px;
        }
        .avancado-titulo::before,.avancado-titulo::after { content:''; flex:1; height:1px; background:var(--amarelo); opacity:0.4; }
        .perm-item-av { border-color:var(--amarelo); background:var(--amarelo-light); }
        .perm-item-av span { color:var(--amarelo-text); }
        .perm-item-av:hover,.perm-item-av.marcado { border-color:var(--azul); background:var(--azul-light); }
        .perm-item-av.marcado span { color:var(--azul); }

        /* ── Rodapé do card ── */
        .colab-footer {
            display:flex; align-items:center; justify-content:space-between;
            margin-top:16px; padding-top:14px; border-top:1px solid var(--border);
        }
        .aviso-proprio {
            background:var(--surface-2); border:1px solid var(--border);
            border-radius:var(--radius); padding:10px 14px;
            font-size:12px; color:var(--text-3);
            display:flex; align-items:center; gap:6px; margin-bottom:12px;
        }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">
            Colaboradores
            <span style="font-size:12px;font-weight:500;color:var(--text-3);margin-left:6px;">
                <?php echo $contadores['todos']; ?> cadastrados
            </span>
        </span>
        <div class="topbar-acoes">
            <!-- Botão que abre/fecha o painel de métricas -->
            <button class="btn btn-secondary btn-sm" id="btn-dash" onclick="toggleDash()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;">
                    <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
                Ver painel
            </button>
        </div>
    </div>

    <!-- ============================================================
         PAINEL DE MÉTRICAS — começa fechado, abre ao clicar
         ============================================================ -->
    <div id="painel-dash" style="display:none;border-bottom:1px solid var(--border);">

        <!-- Linha 1: 4 métricas principais -->
        <div class="colab-dash">
            <div class="dash-bloco">
                <div class="dash-numero"><?php echo $contadores['todos']; ?></div>
                <div class="dash-label">Total de colaboradores</div>
                <div class="dash-sub">que já passaram pela empresa</div>
            </div>
            <div class="dash-bloco">
                <div class="dash-numero verde"><?php echo $contadores['ativo']; ?></div>
                <div class="dash-label">Ativos agora</div>
                <div class="dash-sub">
                    <?php
                    $pct_ativos = $contadores['todos'] > 0
                        ? round($contadores['ativo'] / $contadores['todos'] * 100)
                        : 0;
                    echo $pct_ativos . '% do total';
                    ?>
                </div>
            </div>
            <div class="dash-bloco">
                <div class="dash-numero vermelho"><?php echo $contadores['bloqueado']; ?></div>
                <div class="dash-label">Bloqueados</div>
                <div class="dash-sub">acesso revogado</div>
            </div>
            <div class="dash-bloco">
                <div class="dash-numero azul"><?php echo $contadores['pendente']; ?></div>
                <div class="dash-label">Aguardando aprovação</div>
                <div class="dash-sub">
                    <?php echo $contadores['pendente'] > 0
                        ? 'Ação necessária'
                        : 'Nenhum pendente'; ?>
                </div>
            </div>
        </div>

        <!-- Linha 2: permissões avançadas + último cadastro -->
        <div class="colab-dash" style="border-top:1px solid var(--border);">
            <div class="dash-bloco">
                <div class="dash-numero amarelo"><?php echo $total_avancados; ?></div>
                <div class="dash-label">Com permissões avançadas</div>
                <div class="dash-sub">editar leads, despesas ou colabs</div>
            </div>
            <?php
            // Total com acesso a leads
            $r = mysqli_query($conexao, "SELECT COUNT(*) as t FROM permissoes WHERE ver_leads=1");
            $total_leads_acc = (int)(mysqli_fetch_assoc($r)['t'] ?? 0);
            // Total com acesso ao financeiro
            $r = mysqli_query($conexao, "SELECT COUNT(*) as t FROM permissoes WHERE ver_financeiro=1");
            $total_fin_acc = (int)(mysqli_fetch_assoc($r)['t'] ?? 0);
            // Total com acesso ao dashboard
            $r = mysqli_query($conexao, "SELECT COUNT(*) as t FROM permissoes WHERE ver_dashboard=1");
            $total_dash_acc = (int)(mysqli_fetch_assoc($r)['t'] ?? 0);
            ?>
            <div class="dash-bloco">
                <div class="dash-numero"><?php echo $total_leads_acc; ?></div>
                <div class="dash-label">Acesso a Leads</div>
                <div class="dash-sub">visualizam a lista de leads</div>
            </div>
            <div class="dash-bloco">
                <div class="dash-numero"><?php echo $total_fin_acc; ?></div>
                <div class="dash-label">Acesso ao Financeiro</div>
                <div class="dash-sub">visualizam dados financeiros</div>
            </div>
            <div class="dash-bloco">
                <div class="dash-label" style="margin-bottom:6px;">Último cadastro</div>
                <?php if ($ultimo_col): ?>
                    <div style="font-size:13px;font-weight:700;color:var(--text-1);">
                        <?php echo htmlspecialchars(explode(' ', $ultimo_col['nome_completo'])[0]); ?>
                    </div>
                    <div class="dash-sub">
                        <?php echo date('d/m/Y', strtotime($ultimo_col['criado_em'])); ?>
                    </div>
                <?php else: ?>
                    <div class="dash-sub">Nenhum ainda</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Linha 3: distribuição por cargos -->
        <?php if (!empty($cargos_dashboard)): ?>
        <div class="cargos-bloco">
            <div class="cargos-titulo">Distribuição por cargo</div>
            <div class="cargos-lista">
                <?php foreach($cargos_dashboard as $cg):
                    $pct = $contadores['todos'] > 0
                        ? round($cg['qtd'] / $contadores['todos'] * 100)
                        : 0;
                ?>
                <div class="cargo-item">
                    <span class="cargo-nome"><?php echo htmlspecialchars($cg['cargo']); ?></span>
                    <div class="cargo-barra-wrap">
                        <div class="cargo-barra" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <span class="cargo-qtd"><?php echo $cg['qtd']; ?></span>
                    <span style="font-size:10px;color:var(--text-3);min-width:28px;text-align:right;"><?php echo $pct; ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <!-- fim painel-dash -->

    <?php if ($msg): ?>
    <div style="padding:12px 20px 0;">
        <div class="alert alert-<?php echo $tipo_msg==='error'?'error':'success'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Barra de filtros ── -->
    <div class="filtros-colab">

        <!-- Status -->
        <a href="colaboradores.php<?php echo $filtro_perm||$filtro_cargo||$filtro_busca ? '?'.http_build_query(array_filter(['permissao'=>$filtro_perm,'cargo'=>$filtro_cargo,'busca'=>$filtro_busca])) : ''; ?>"
           class="filtro-pill <?php echo !$filtro_status ? 'ativo' : ''; ?>">
            Todos
            <span class="<?php echo !$filtro_status ? 'filtro-num' : 'filtro-num-dark'; ?>"><?php echo $contadores['todos']; ?></span>
        </a>

        <?php if ($contadores['pendente'] > 0): ?>
        <a href="<?php echo url_filtro(['status'=>'pendente']); ?>"
           class="filtro-pill <?php echo $filtro_status==='pendente' ? 'ativo' : ''; ?>">
            ⏳ Pendentes
            <span class="<?php echo $filtro_status==='pendente' ? 'filtro-num' : 'filtro-num-dark'; ?>"><?php echo $contadores['pendente']; ?></span>
        </a>
        <?php endif; ?>

        <?php if ($contadores['ativo'] > 0): ?>
        <a href="<?php echo url_filtro(['status'=>'ativo']); ?>"
           class="filtro-pill <?php echo $filtro_status==='ativo' ? 'verde' : ''; ?>">
            ✓ Ativos
            <span class="<?php echo $filtro_status==='ativo' ? 'filtro-num' : 'filtro-num-dark'; ?>"><?php echo $contadores['ativo']; ?></span>
        </a>
        <?php endif; ?>

        <?php if ($contadores['bloqueado'] > 0): ?>
        <a href="<?php echo url_filtro(['status'=>'bloqueado']); ?>"
           class="filtro-pill <?php echo $filtro_status==='bloqueado' ? 'vermelho' : ''; ?>">
            🚫 Bloqueados
            <span class="<?php echo $filtro_status==='bloqueado' ? 'filtro-num' : 'filtro-num-dark'; ?>"><?php echo $contadores['bloqueado']; ?></span>
        </a>
        <?php endif; ?>

        <?php if (!empty($cargos)): ?>
        <div class="filtro-sep"></div>

        <!-- Filtro por cargo -->
        <select onchange="window.location.href=this.value"
            style="height:34px;border:1px solid var(--border-2);border-radius:var(--radius);padding:0 10px;font-size:12px;font-family:'Manrope',sans-serif;color:var(--text-1);background:var(--surface);outline:none;cursor:pointer;<?php echo $filtro_cargo ? 'border-color:var(--azul);background:var(--azul-light);color:var(--azul);font-weight:600;' : ''; ?>">
            <option value="<?php echo url_filtro(['cargo'=>'']); ?>"><?php echo $filtro_cargo ? '× ' . $filtro_cargo : 'Todos os cargos'; ?></option>
            <?php foreach($cargos as $cargo):
                $sel = ($filtro_cargo === $cargo) ? 'selected' : ''; ?>
                <option value="<?php echo url_filtro(['cargo'=>$cargo]); ?>" <?php echo $sel; ?>>
                    <?php echo htmlspecialchars($cargo); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div class="filtro-sep"></div>

        <!-- Filtro por permissão -->
        <select onchange="window.location.href=this.value"
            style="height:34px;border:1px solid var(--border-2);border-radius:var(--radius);padding:0 10px;font-size:12px;font-family:'Manrope',sans-serif;color:var(--text-1);background:var(--surface);outline:none;cursor:pointer;<?php echo $filtro_perm ? 'border-color:var(--azul);background:var(--azul-light);color:var(--azul);font-weight:600;' : ''; ?>">
            <option value="<?php echo url_filtro(['permissao'=>'']); ?>"><?php echo $filtro_perm ? '× ' . ($perms_disponiveis[$filtro_perm]??'') : 'Filtrar por permissão'; ?></option>
            <?php foreach($perms_disponiveis as $key => $label):
                $sel = ($filtro_perm === $key) ? 'selected' : ''; ?>
                <option value="<?php echo url_filtro(['permissao'=>$key]); ?>" <?php echo $sel; ?>>
                    <?php echo $label; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Busca por nome/ID/email -->
        <form method="get" action="colaboradores.php" class="filtro-busca-wrap">
            <?php if($filtro_status): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filtro_status); ?>"><?php endif; ?>
            <?php if($filtro_perm):   ?><input type="hidden" name="permissao" value="<?php echo htmlspecialchars($filtro_perm); ?>"><?php endif; ?>
            <?php if($filtro_cargo):  ?><input type="hidden" name="cargo" value="<?php echo htmlspecialchars($filtro_cargo); ?>"><?php endif; ?>
            <svg class="filtro-busca-icone" viewBox="0 0 24 24" fill="none" stroke-width="2.5">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input class="filtro-busca" type="text" name="busca"
                placeholder="Buscar por nome, ID, cargo..."
                value="<?php echo htmlspecialchars($filtro_busca); ?>"
                autocomplete="off">
        </form>

    </div>

    <!-- Linha de resultado + limpar filtros -->
    <?php if ($tem_filtro || $total_filtrado !== $contadores['todos']): ?>
    <div class="filtros-info">
        Mostrando <strong><?php echo $total_filtrado; ?></strong> de <?php echo $contadores['todos']; ?> colaboradores
        <?php if ($tem_filtro): ?>
            · <a href="colaboradores.php" class="limpar-link">× Limpar filtros</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="padding:20px;max-width:800px;">

        <?php if ($total_filtrado === 0): ?>
            <div class="card">
                <div class="empty-state">
                    <?php if ($tem_filtro): ?>
                        <strong>Nenhum colaborador encontrado</strong>
                        <a href="colaboradores.php" style="color:var(--azul);">Limpar filtros</a>
                    <?php else: ?>
                        <strong>Nenhum colaborador cadastrado</strong>
                        Colaboradores se cadastram pela tela de login clicando em "Criar conta"
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>

        <?php while ($c = mysqli_fetch_assoc($colaboradores)):
            $inicial    = strtoupper(substr($c['nome_completo'], 0, 1));
            $sb_cls     = 'sb-' . $c['status'];
            $sb_txt     = ['pendente'=>'Pendente','ativo'=>'Ativo','bloqueado'=>'Bloqueado'][$c['status']] ?? $c['status'];
            $eh_proprio = !eh_admin() && ((int)($_SESSION['colab_id'] ?? 0) === (int)$c['id']);
        ?>
        <div class="colab-card <?php echo $c['status']; ?>"
             <?php echo $eh_proprio ? 'style="opacity:0.7;"' : ''; ?>>

            <!-- Cabeçalho -->
            <div class="colab-header">
                <div class="colab-avatar"><?php echo $inicial; ?></div>
                <div class="colab-info">
                    <div class="colab-nome">
                        <?php echo htmlspecialchars($c['nome_completo']); ?>
                        <?php if ($c['cargo']): ?>
                            <span class="cargo-badge"><?php echo htmlspecialchars($c['cargo']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="colab-meta">
                        <span class="colab-id"><?php echo htmlspecialchars($c['colaborador_id']); ?></span>
                        · <?php echo htmlspecialchars($c['email']); ?>
                        · desde <?php echo date('d/m/Y', strtotime($c['criado_em'])); ?>
                    </div>
                </div>
                <span class="status-badge <?php echo $sb_cls; ?>"><?php echo $sb_txt; ?></span>
            </div>

            <!-- Aviso se é o próprio colaborador -->
            <?php if ($eh_proprio): ?>
            <div class="aviso-proprio">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Você não pode editar suas próprias permissões.
            </div>
            <?php elseif ($c['status'] === 'bloqueado'): ?>
            <div class="aviso-proprio" style="border-color:#FECACA;background:#FEF2F2;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                <span style="color:#991B1B;">Colaborador bloqueado — reative a conta para editar permissões.</span>
            </div>
            <?php endif; ?>

            <!-- Formulário de permissões -->
            <form action="" method="post" id="form-<?php echo $c['id']; ?>">
                <input type="hidden" name="colab_id" value="<?php echo $c['id']; ?>">
                <input type="hidden" name="salvar_permissoes" value="1">
                <?php if ($eh_proprio || $c['status'] === 'bloqueado'): ?>
                <fieldset disabled style="border:none;padding:0;margin:0;">
                <?php endif; ?>

                <div class="perm-titulo">Permissões de visualização</div>
                <div class="perm-grid">
                    <?php foreach(['ver_dashboard'=>'Ver Dashboard','ver_leads'=>'Ver Leads','ver_financeiro'=>'Ver Financeiro'] as $campo=>$rotulo):
                        $chk = $c[$campo] ? 'checked' : ''; ?>
                    <label class="perm-item">
                        <input type="checkbox" name="<?php echo $campo; ?>" <?php echo $chk; ?>>
                        <span><?php echo $rotulo; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="avancado-titulo">⚠ Permissões avançadas</div>
                <div class="perm-grid">
                    <?php foreach(['editar_leads'=>'Editar Leads','editar_financeiro'=>'Add Despesas','gerenciar_colab'=>'Gerenciar Colabs'] as $campo=>$rotulo):
                        $chk    = $c[$campo] ? 'checked' : '';
                        $marcado = $c[$campo] ? 'marcado' : '';
                        $lid    = $c['id'].'-'.$campo; ?>
                    <label class="perm-item perm-item-av <?php echo $marcado; ?>" id="label-<?php echo $lid; ?>">
                        <input type="checkbox" name="<?php echo $campo; ?>" <?php echo $chk; ?>
                               onchange="confirmarAvancado(this,'<?php echo addslashes($rotulo); ?>','<?php echo $lid; ?>')">
                        <span><?php echo $rotulo; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <?php if ($eh_proprio || $c['status'] === 'bloqueado'): ?>
                </fieldset>
                <?php endif; ?>

                <!-- Rodapé -->
                <div class="colab-footer">
                    <?php if (!$eh_proprio): ?>
                    <div style="display:flex;gap:8px;">
                        <a href="perfil.php?id=<?php echo $c['id']; ?>"
                           class="btn btn-secondary btn-sm">
                           👤 Ver perfil
                        </a>
                        <?php if ($c['status'] !== 'bloqueado'): ?>
                            <a href="?bloquear=<?php echo $c['id']; ?><?php echo $tem_filtro ? '&'.http_build_query(array_filter(['status'=>$filtro_status,'permissao'=>$filtro_perm,'cargo'=>$filtro_cargo,'busca'=>$filtro_busca])) : ''; ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Bloquear <?php echo addslashes($c['nome_completo']); ?>?')">
                               Bloquear
                            </a>
                        <?php else: ?>
                            <a href="?ativar=<?php echo $c['id']; ?>"
                               class="btn btn-secondary btn-sm">Reativar</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($c['status'] !== 'bloqueado'): ?>
                    <button type="submit" class="btn btn-primary btn-sm">
                        ✓ Confirmar — <?php echo htmlspecialchars($c['nome_completo']); ?>
                    </button>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--text-3);">Reative para editar permissões</span>
                    <?php endif; ?>
                    <?php else: ?>
                        <span style="font-size:12px;color:var(--text-3);">Esta é a sua conta</span>
                    <?php endif; ?>
                </div>

            </form>
        </div>
        <?php endwhile; ?>

        <?php endif; ?>
    </div>
</div>

<script>
// Abre/fecha o painel de métricas
function toggleDash() {
    var painel = document.getElementById('painel-dash');
    var btn    = document.getElementById('btn-dash');
    var aberto = painel.style.display !== 'none';

    painel.style.display = aberto ? 'none' : 'block';

    if (aberto) {
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:middle;"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Ver painel';
        btn.style.background  = '';
        btn.style.borderColor = '';
        btn.style.color       = '';
    } else {
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;vertical-align:middle;"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Fechar painel';
        btn.style.background  = 'var(--azul-light)';
        btn.style.borderColor = 'var(--azul)';
        btn.style.color       = 'var(--azul)';
    }
}

// Confirmação ao marcar permissão avançada
function confirmarAvancado(checkbox, rotulo, labelId) {
    if (checkbox.checked) {
        var ok = confirm(
            'Conceder "' + rotulo + '" a este colaborador?\n\n' +
            'Esta é uma permissão avançada. Você poderá revogar depois.'
        );
        if (!ok) { checkbox.checked = false; return; }
    }
    var label = document.getElementById('label-' + labelId);
    if (label) label.classList.toggle('marcado', checkbox.checked);
}

// Submete a busca com Enter
document.querySelector('.filtro-busca').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') this.closest('form').submit();
});
</script>

</body>
</html>