<?php
// ============================================================
// ARQUIVO: funil.php
// O QUE FAZ: Relatório de funil de conversão por período
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_permissao('ver_leads');

$pagina_atual = 'funil';

// ── Períodos rápidos ──
$hoje     = date('Y-m-d');
$periodos = [
    '7d'  => ['label' => 'Últimos 7 dias',   'ini' => date('Y-m-d', strtotime('-6 days')),   'fim' => $hoje],
    '30d' => ['label' => 'Últimos 30 dias',  'ini' => date('Y-m-d', strtotime('-29 days')),  'fim' => $hoje],
    '3m'  => ['label' => 'Últimos 3 meses',  'ini' => date('Y-m-d', strtotime('-89 days')),  'fim' => $hoje],
    '6m'  => ['label' => 'Últimos 6 meses',  'ini' => date('Y-m-d', strtotime('-179 days')), 'fim' => $hoje],
    'mes' => ['label' => 'Este mês',         'ini' => date('Y-m-01'),                         'fim' => $hoje],
    'ano' => ['label' => 'Este ano',          'ini' => date('Y-01-01'),                        'fim' => $hoje],
];

// Lê filtros da URL
$periodo_sel = $_GET['periodo'] ?? '30d';
if (isset($periodos[$periodo_sel])) {
    $data_ini = $periodos[$periodo_sel]['ini'];
    $data_fim = $periodos[$periodo_sel]['fim'];
} else {
    $periodo_sel = 'custom';
}

// Datas customizadas via input
if ($periodo_sel === 'custom' || isset($_GET['data_ini'])) {
    $data_ini    = $_GET['data_ini'] ?? date('Y-m-01');
    $data_fim    = $_GET['data_fim'] ?? $hoje;
    $periodo_sel = 'custom';
}

// Sanitiza datas
$data_ini = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ini) ? $data_ini : date('Y-m-01');
$data_fim = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim) ? $data_fim : $hoje;
if ($data_ini > $data_fim) [$data_ini, $data_fim] = [$data_fim, $data_ini];

$data_ini_e = mysqli_real_escape_string($conexao, $data_ini);
$data_fim_e = mysqli_real_escape_string($conexao, $data_fim);

// ── Status com labels e cores ──
$status_info = [
    'novo'             => ['label' => 'Novo',             'cor' => '#008CFF', 'light' => '#E8F4FF'],
    'em_contato'       => ['label' => 'Em contato',       'cor' => '#F59E0B', 'light' => '#FEF3C7'],
    'proposta_enviada' => ['label' => 'Proposta enviada', 'cor' => '#7C3AED', 'light' => '#EDE9FE'],
    'negociacao'       => ['label' => 'Negociação',       'cor' => '#F97316', 'light' => '#FFF7ED'],
    'fechado'          => ['label' => 'Fechado',           'cor' => '#10B981', 'light' => '#D1FAE5'],
    'perdido'          => ['label' => 'Perdido',           'cor' => '#EF4444', 'light' => '#FEE2E2'],
];

// ── Query 1: leads criados no período por status ──
$r_criados = mysqli_query($conexao,
    "SELECT status, COUNT(*) as qtd
     FROM leads
     WHERE DATE(criado_em) BETWEEN '$data_ini_e' AND '$data_fim_e'
     GROUP BY status"
);
$criados_por_status = [];
$total_criados = 0;
while ($row = mysqli_fetch_assoc($r_criados)) {
    $criados_por_status[$row['status']] = (int)$row['qtd'];
    $total_criados += (int)$row['qtd'];
}

// ── Query 2: snapshot atual (estado atual de todos os leads) ──
$r_atual = mysqli_query($conexao,
    "SELECT status, COUNT(*) as qtd FROM leads GROUP BY status"
);
$atual_por_status = [];
$total_atual = 0;
while ($row = mysqli_fetch_assoc($r_atual)) {
    $atual_por_status[$row['status']] = (int)$row['qtd'];
    $total_atual += (int)$row['qtd'];
}

// ── Query 3: leads fechados no período (faturamento) ──
$r_fat = mysqli_query($conexao,
    "SELECT COUNT(*) as qtd, COALESCE(SUM(valor),0) as total
     FROM leads
     WHERE status = 'fechado'
     AND DATE(criado_em) BETWEEN '$data_ini_e' AND '$data_fim_e'"
);
$fat_row      = mysqli_fetch_assoc($r_fat);
$fat_qtd      = (int)$fat_row['qtd'];
$fat_total    = (float)$fat_row['total'];
$taxa_conv    = $total_criados > 0
    ? round($fat_qtd / $total_criados * 100, 1)
    : 0;
$ticket_medio = $fat_qtd > 0 ? $fat_total / $fat_qtd : 0;

// ── Query 4: evolução por mês (últimos 6 meses sempre) ──
$r_evolucao = mysqli_query($conexao,
    "SELECT
        DATE_FORMAT(criado_em, '%Y-%m') as mes,
        DATE_FORMAT(criado_em, '%b/%y') as mes_label,
        COUNT(*) as total,
        SUM(CASE WHEN status='fechado' THEN 1 ELSE 0 END) as fechados,
        COALESCE(SUM(CASE WHEN status='fechado' THEN valor ELSE 0 END),0) as faturamento
     FROM leads
     WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(criado_em, '%Y-%m')
     ORDER BY mes ASC"
);
$evolucao = [];
while ($row = mysqli_fetch_assoc($r_evolucao)) {
    $evolucao[] = $row;
}

// ── Query 5: origem que mais converte no período ──
$r_origem = mysqli_query($conexao,
    "SELECT
        origem,
        COUNT(*) as total,
        SUM(CASE WHEN status='fechado' THEN 1 ELSE 0 END) as fechados
     FROM leads
     WHERE DATE(criado_em) BETWEEN '$data_ini_e' AND '$data_fim_e'
       AND origem != ''
     GROUP BY origem
     ORDER BY fechados DESC, total DESC
     LIMIT 6"
);
$origens = [];
while ($row = mysqli_fetch_assoc($r_origem)) {
    $row['taxa'] = $row['total'] > 0 ? round($row['fechados'] / $row['total'] * 100) : 0;
    $origens[] = $row;
}

// Formata datas para exibição
$data_ini_fmt = date('d/m/Y', strtotime($data_ini));
$data_fim_fmt = date('d/m/Y', strtotime($data_fim));
?>
<!DOCTYPE html>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Funil de Conversão</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Filtro de período ── */
        .periodo-bar {
            display: flex; align-items: center; gap: 6px;
            padding: 14px 20px; border-bottom: 1px solid var(--border);
            background: var(--surface-2); flex-wrap: wrap;
        }
        .periodo-btn {
            padding: 5px 12px; border-radius: 20px; font-size: 12px;
            font-weight: 600; border: 1px solid var(--border-2);
            background: var(--surface); color: var(--text-2);
            text-decoration: none; cursor: pointer; transition: all 0.15s;
            white-space: nowrap;
        }
        .periodo-btn:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
        .periodo-btn.ativo { border-color:var(--azul); background:var(--azul); color:white; }
        .periodo-sep { width:1px; height:20px; background:var(--border); flex-shrink:0; }
        .periodo-custom { display:flex; align-items:center; gap:6px; }
        .periodo-input {
            height:32px; border:1px solid var(--border-2); border-radius:var(--radius);
            padding:0 10px; font-size:12px; font-family:'Manrope',sans-serif;
            color:var(--text-1); background:var(--surface); outline:none;
        }
        .periodo-input:focus { border-color:var(--azul); }

        /* ── Cards de resumo ── */
        .resumo-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
        .resumo-card {
            background:var(--surface); border:1px solid var(--border);
            border-radius:var(--radius-lg); padding:16px 18px;
        }
        .resumo-num { font-size:28px; font-weight:800; letter-spacing:-0.5px; line-height:1; color:var(--text-1); }
        .resumo-label { font-size:11px; color:var(--text-3); margin-top:4px; font-weight:500; text-transform:uppercase; letter-spacing:0.5px; }
        .resumo-sub { font-size:11px; color:var(--text-3); margin-top:3px; }

        /* ── Funil visual ── */
        .funil-wrap { display:flex; flex-direction:column; gap:8px; }
        .funil-linha {
            display:flex; align-items:center; gap:12px;
            padding:10px 14px; border-radius:var(--radius);
            background:var(--surface-2); border:1px solid var(--border);
            transition:background 0.15s;
        }
        .funil-linha:hover { background:var(--surface); }
        .funil-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .funil-label { font-size:13px; font-weight:600; color:var(--text-1); min-width:140px; }
        .funil-barra-bg { flex:1; height:10px; background:var(--border); border-radius:10px; overflow:hidden; }
        .funil-barra { height:100%; border-radius:10px; transition:width 0.8s ease; }
        .funil-qtd { font-size:14px; font-weight:800; color:var(--text-1); min-width:36px; text-align:right; }
        .funil-pct { font-size:11px; color:var(--text-3); min-width:36px; text-align:right; }

        /* ── Origens ── */
        .origens-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .origem-card {
            background:var(--surface); border:1px solid var(--border);
            border-radius:var(--radius); padding:12px 14px;
        }
        .origem-nome { font-size:12px; font-weight:700; color:var(--text-1); margin-bottom:6px; }
        .origem-nums { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
        .origem-total { font-size:11px; color:var(--text-3); }
        .origem-fech  { font-size:11px; font-weight:700; color:var(--verde); }
        .origem-taxa  { font-size:13px; font-weight:800; margin-left:auto; }

        /* ── Tabela de evolução ── */
        .evolucao-item {
            display:flex; align-items:center; gap:10px;
            padding:8px 0; border-bottom:1px solid var(--border);
        }
        .evolucao-item:last-child { border-bottom:none; }
        .evolucao-mes { font-size:12px; font-weight:700; color:var(--text-2); min-width:55px; }
        .evolucao-barra-bg { flex:1; height:7px; background:var(--border); border-radius:10px; overflow:hidden; }
        .evolucao-barra { height:100%; border-radius:10px; background:var(--azul); }
        .evolucao-nums { font-size:11px; color:var(--text-3); white-space:nowrap; }
        .evolucao-fech { font-size:12px; font-weight:700; color:var(--verde); min-width:32px; text-align:right; }

        @media(max-width:768px) {
            .resumo-grid { grid-template-columns:1fr 1fr; gap:8px; }
            .origens-grid { grid-template-columns:1fr; }
            .funil-label { min-width:110px; }
        }
        @media(max-width:480px) {
            .resumo-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">Funil de Conversão</span>
        <div class="topbar-acoes">
            <span style="font-size:12px;color:var(--text-3);">
                <?php echo $data_ini_fmt; ?> → <?php echo $data_fim_fmt; ?>
            </span>
        </div>
    </div>

    <!-- Filtro de período -->
    <div class="periodo-bar">
        <?php foreach ($periodos as $key => $p): ?>
        <a href="funil.php?periodo=<?php echo $key; ?>"
           class="periodo-btn <?php echo $periodo_sel === $key ? 'ativo' : ''; ?>">
            <?php echo $p['label']; ?>
        </a>
        <?php endforeach; ?>

        <div class="periodo-sep"></div>

        <!-- Datas customizadas -->
        <form method="get" action="funil.php" class="periodo-custom">
            <input type="hidden" name="periodo" value="custom">
            <input class="periodo-input" type="date" name="data_ini"
                value="<?php echo $data_ini; ?>" max="<?php echo $hoje; ?>">
            <span style="font-size:12px;color:var(--text-3);">até</span>
            <input class="periodo-input" type="date" name="data_fim"
                value="<?php echo $data_fim; ?>" max="<?php echo $hoje; ?>">
            <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
        </form>
    </div>

    <div class="page-content">

        <!-- ── Cards de resumo ── -->
        <div class="resumo-grid">
            <div class="resumo-card">
                <div class="resumo-num"><?php echo $total_criados; ?></div>
                <div class="resumo-label">Leads no período</div>
                <div class="resumo-sub">criados entre as datas</div>
            </div>
            <div class="resumo-card" style="border-left:3px solid var(--verde);">
                <div class="resumo-num" style="color:var(--verde);"><?php echo $fat_qtd; ?></div>
                <div class="resumo-label">Fechados</div>
                <div class="resumo-sub">no mesmo período</div>
            </div>
            <div class="resumo-card" style="border-left:3px solid var(--azul);">
                <div class="resumo-num" style="color:var(--azul);"><?php echo $taxa_conv; ?>%</div>
                <div class="resumo-label">Taxa de conversão</div>
                <div class="resumo-sub">fechados / total</div>
            </div>
            <div class="resumo-card" style="border-left:3px solid var(--amarelo);">
                <div class="resumo-num" style="font-size:18px;color:var(--verde);">
                    R$ <?php echo number_format($fat_total, 2, ',', '.'); ?>
                </div>
                <div class="resumo-label">Faturamento</div>
                <?php if ($ticket_medio > 0): ?>
                <div class="resumo-sub">ticket médio R$ <?php echo number_format($ticket_medio, 2, ',', '.'); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Linha 2: funil + origens ── -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

            <!-- Funil de leads no período -->
            <div class="card">
                <div style="font-size:13px;font-weight:700;color:var(--text-1);margin-bottom:4px;">
                    Funil do período
                </div>
                <div style="font-size:11px;color:var(--text-3);margin-bottom:14px;">
                    Leads criados entre <?php echo $data_ini_fmt; ?> e <?php echo $data_fim_fmt; ?>
                </div>

                <?php if ($total_criados === 0): ?>
                <div class="empty-state"><strong>Nenhum lead no período</strong>Tente um intervalo diferente</div>
                <?php else: ?>
                <div class="funil-wrap">
                    <?php foreach ($status_info as $sk => $si):
                        $qtd  = $criados_por_status[$sk] ?? 0;
                        $pct  = $total_criados > 0 ? round($qtd / $total_criados * 100) : 0;
                        $larg = $total_criados > 0 ? round($qtd / $total_criados * 100) : 0;
                    ?>
                    <div class="funil-linha">
                        <div class="funil-dot" style="background:<?php echo $si['cor']; ?>;"></div>
                        <div class="funil-label"><?php echo $si['label']; ?></div>
                        <div class="funil-barra-bg">
                            <div class="funil-barra"
                                 style="width:<?php echo $larg; ?>%;background:<?php echo $si['cor']; ?>;"></div>
                        </div>
                        <div class="funil-qtd"><?php echo $qtd; ?></div>
                        <div class="funil-pct"><?php echo $pct; ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Snapshot atual do funil -->
            <div class="card">
                <div style="font-size:13px;font-weight:700;color:var(--text-1);margin-bottom:4px;">
                    Estado atual
                </div>
                <div style="font-size:11px;color:var(--text-3);margin-bottom:14px;">
                    Todos os leads hoje — independente da data de criação
                </div>

                <?php if ($total_atual === 0): ?>
                <div class="empty-state"><strong>Nenhum lead cadastrado</strong></div>
                <?php else: ?>
                <div class="funil-wrap">
                    <?php foreach ($status_info as $sk => $si):
                        $qtd  = $atual_por_status[$sk] ?? 0;
                        $pct  = $total_atual > 0 ? round($qtd / $total_atual * 100) : 0;
                        $larg = $total_atual > 0 ? round($qtd / $total_atual * 100) : 0;
                    ?>
                    <div class="funil-linha">
                        <div class="funil-dot" style="background:<?php echo $si['cor']; ?>;"></div>
                        <div class="funil-label"><?php echo $si['label']; ?></div>
                        <div class="funil-barra-bg">
                            <div class="funil-barra"
                                 style="width:<?php echo $larg; ?>%;background:<?php echo $si['cor']; ?>;"></div>
                        </div>
                        <div class="funil-qtd"><?php echo $qtd; ?></div>
                        <div class="funil-pct"><?php echo $pct; ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Linha 3: evolução mensal + origens ── -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            <!-- Evolução mensal (últimos 6 meses fixo) -->
            <div class="card">
                <div style="font-size:13px;font-weight:700;color:var(--text-1);margin-bottom:4px;">
                    Evolução mensal
                </div>
                <div style="font-size:11px;color:var(--text-3);margin-bottom:14px;">
                    Últimos 6 meses — total de leads e fechamentos
                </div>

                <?php if (empty($evolucao)): ?>
                <div class="empty-state"><strong>Sem dados</strong></div>
                <?php else:
                    $max_total = max(array_column($evolucao, 'total')) ?: 1;
                ?>
                <div>
                    <?php foreach ($evolucao as $ev):
                        $larg_ev = round($ev['total'] / $max_total * 100);
                        $taxa_ev = $ev['total'] > 0 ? round($ev['fechados'] / $ev['total'] * 100) : 0;
                    ?>
                    <div class="evolucao-item">
                        <div class="evolucao-mes"><?php echo $ev['mes_label']; ?></div>
                        <div class="evolucao-barra-bg">
                            <div class="evolucao-barra" style="width:<?php echo $larg_ev; ?>%;"></div>
                        </div>
                        <div class="evolucao-nums"><?php echo $ev['total']; ?> leads</div>
                        <div class="evolucao-fech">✓ <?php echo $ev['fechados']; ?></div>
                        <div style="font-size:10px;color:var(--text-3);min-width:32px;text-align:right;"><?php echo $taxa_ev; ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Origem que mais converte -->
            <div class="card">
                <div style="font-size:13px;font-weight:700;color:var(--text-1);margin-bottom:4px;">
                    Origens no período
                </div>
                <div style="font-size:11px;color:var(--text-3);margin-bottom:14px;">
                    De onde vieram os leads e qual converte mais
                </div>

                <?php if (empty($origens)): ?>
                <div class="empty-state"><strong>Nenhuma origem registrada</strong></div>
                <?php else: ?>
                <div class="funil-wrap">
                    <?php
                    $max_orig = max(array_column($origens, 'total')) ?: 1;
                    foreach ($origens as $or):
                        $larg_or = round($or['total'] / $max_orig * 100);
                        $cor_taxa_or = $or['taxa'] >= 50 ? 'var(--verde)'
                                     : ($or['taxa'] >= 25 ? 'var(--amarelo)'
                                     : 'var(--text-3)');
                    ?>
                    <div class="funil-linha">
                        <div class="funil-label" style="min-width:100px;font-size:12px;">
                            <?php echo htmlspecialchars($or['origem']); ?>
                        </div>
                        <div class="funil-barra-bg">
                            <div class="funil-barra"
                                 style="width:<?php echo $larg_or; ?>%;background:var(--azul);"></div>
                        </div>
                        <div class="funil-qtd" style="font-size:13px;"><?php echo $or['total']; ?></div>
                        <div style="font-size:12px;font-weight:700;color:<?php echo $cor_taxa_or; ?>;min-width:40px;text-align:right;">
                            <?php echo $or['taxa']; ?>%
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>
</body>
</html>