<?php
// ============================================================
// ARQUIVO: dashboard.php
// O QUE FAZ: Painel geral + relatórios avançados
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_permissao('ver_dashboard');
$pagina_atual = 'dashboard';
$nome = nome_usuario();

// ============================================================
// AÇÃO: Salvar / alterar / zerar meta mensal
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_meta']) && eh_admin()) {

    $mes_date = date('Y-m-01'); // Primeiro dia do mês atual
    $mes_e    = mysqli_real_escape_string($conexao, $mes_date);

    if (isset($_POST['meta_zerar'])) {
        // Remove a meta do mês
        mysqli_query($conexao, "DELETE FROM metas WHERE mes = '$mes_e'");
    } else {
        $valor_raw = str_replace(',', '.', trim($_POST['meta_valor'] ?? '0'));
        $valor     = is_numeric($valor_raw) ? (float)$valor_raw : 0;

        if ($valor > 0) {
            // INSERT se não existe, UPDATE se já existe (UPSERT)
            mysqli_query($conexao,
                "INSERT INTO metas (mes, valor)
                 VALUES ('$mes_e', $valor)
                 ON DUPLICATE KEY UPDATE valor = $valor"
            );
        }
    }

    // Redireciona para evitar resubmissão ao recarregar (PRG pattern)
    // PRG = Post-Redirect-Get: redireciona para GET depois de um POST
    header("Location: dashboard.php");
    exit();
}

// ============================================================
// META DO MÊS ATUAL
// ============================================================
// DATE_FORMAT(CURDATE(), '%Y-%m-01') = primeiro dia do mês atual
$r_meta  = mysqli_query($conexao,
    "SELECT valor FROM metas WHERE mes = DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);
$meta_mes = $r_meta && mysqli_num_rows($r_meta) > 0
    ? (float)mysqli_fetch_assoc($r_meta)['valor']
    : 0;
// mes_atual no formato YYYY-MM para o input do dashboard
$mes_atual = date('Y-m');

// ============================================================
// LEMBRETES URGENTES — vencidos ou vencem em até 1 dia
// ============================================================
$criado_por_filtro_dash = eh_admin()
    ? ""
    : "AND (criado_por = " . (int)$_SESSION['colab_id'] . " OR criado_por IS NULL)";

$r_lemb = mysqli_query($conexao,
    "SELECT l.*, ld.nome as lead_nome, ld.id as lead_id_num
     FROM lembretes l
     JOIN leads ld ON ld.id = l.lead_id
     WHERE l.concluido = 0
       AND l.data_hora <= DATE_ADD(NOW(), INTERVAL 1 DAY)
       $criado_por_filtro_dash
     ORDER BY l.data_hora ASC
     LIMIT 10"
);
$lembretes_urgentes = [];
while ($row = mysqli_fetch_assoc($r_lemb)) {
    $lembretes_urgentes[] = $row;
}
$total_urgentes = count($lembretes_urgentes);

// ============================================================
// BLOCO 1: Métricas gerais de leads
// ============================================================

// Total de leads e agrupamento por status numa só query
// GROUP BY agrupa todas as linhas com o mesmo status numa única linha
$r = mysqli_query($conexao,
    "SELECT status, COUNT(*) as qtd FROM leads GROUP BY status"
);
$por_status  = [];
$total_leads = 0;
while ($row = mysqli_fetch_assoc($r)) {
    $por_status[$row['status']] = (int)$row['qtd'];
    $total_leads += $row['qtd'];
}

$total_novos           = $por_status['novo']             ?? 0;
$total_contato         = $por_status['em_contato']       ?? 0;
$total_proposta        = $por_status['proposta_enviada'] ?? 0;
$total_negociacao      = $por_status['negociacao']       ?? 0;
$total_fechados        = $por_status['fechado']          ?? 0;
$total_perdidos        = $por_status['perdido']          ?? 0;

// ============================================================
// BLOCO 2: Taxa de conversão
// ============================================================
// Taxa = (fechados / total) * 100
// round() arredonda para não mostrar 33.333333...
$taxa_conversao = $total_leads > 0
    ? round(($total_fechados / $total_leads) * 100, 1)
    : 0;

// ============================================================
// BLOCO 3: Faturamento do mês atual
// ============================================================
$r2 = mysqli_query($conexao,
    "SELECT SUM(valor) as t FROM vendas
     WHERE MONTH(data_venda) = MONTH(CURDATE())
       AND YEAR(data_venda)  = YEAR(CURDATE())"
);
$faturamento_mes = (float)(mysqli_fetch_assoc($r2)['t'] ?? 0);

// Vendas no mês
$r3 = mysqli_query($conexao,
    "SELECT COUNT(*) as qtd FROM vendas
     WHERE MONTH(data_venda) = MONTH(CURDATE())
       AND YEAR(data_venda)  = YEAR(CURDATE())"
);
$vendas_mes = (int)(mysqli_fetch_assoc($r3)['qtd'] ?? 0);

// ============================================================
// BLOCO 4: Ticket médio por mês (últimos 6 meses)
// ============================================================
// Ticket médio = faturamento total do mês / número de vendas do mês
// AVG() = média — mas usamos SUM/COUNT para ter os dois separados
// COALESCE(x, 0) = se x for NULL, retorna 0

$r4 = mysqli_query($conexao,
    "SELECT
        DATE_FORMAT(data_venda, '%m/%Y') as mes,
        DATE_FORMAT(data_venda, '%Y-%m') as mes_ordem,
        COUNT(*)       as qtd_vendas,
        SUM(valor)     as total,
        AVG(valor)     as ticket_medio
     FROM vendas
     WHERE data_venda >= CURDATE() - INTERVAL 6 MONTH
     GROUP BY DATE_FORMAT(data_venda, '%Y-%m')
     ORDER BY mes_ordem ASC"
);
$ticket_labels  = [];
$ticket_valores = [];
$ticket_medio_geral = 0;
$total_vendas_geral = 0;
$faturamento_total  = 0;

while ($row = mysqli_fetch_assoc($r4)) {
    $ticket_labels[]  = $row['mes'];
    $ticket_valores[] = round((float)$row['ticket_medio'], 2);
    $faturamento_total  += (float)$row['total'];
    $total_vendas_geral += (int)$row['qtd_vendas'];
}
// Ticket médio geral dos últimos 6 meses
$ticket_medio_geral = $total_vendas_geral > 0
    ? round($faturamento_total / $total_vendas_geral, 2)
    : 0;

// ============================================================
// BLOCO 5: Origem que mais converte
// ============================================================
// Queremos saber: de cada origem, quantos leads vieram
// e quantos fecharam — para calcular a taxa de conversão por origem

$r5 = mysqli_query($conexao,
    "SELECT
        origem,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'fechado' THEN 1 ELSE 0 END) as fechados
        -- CASE WHEN = condicional dentro do SQL
        -- Se status for 'fechado', conta 1; senão conta 0
        -- SUM() soma todos esses 1s = total de fechados por origem
     FROM leads
     WHERE origem != '' AND origem IS NOT NULL
     GROUP BY origem
     ORDER BY fechados DESC, total DESC
     LIMIT 6"
);
$origens_dados = [];
while ($row = mysqli_fetch_assoc($r5)) {
    $taxa = $row['total'] > 0
        ? round(($row['fechados'] / $row['total']) * 100, 1)
        : 0;
    $origens_dados[] = [
        'origem'   => $row['origem'],
        'total'    => (int)$row['total'],
        'fechados' => (int)$row['fechados'],
        'taxa'     => $taxa,
    ];
}

// ============================================================
// BLOCO 6: Tempo médio entre cadastro e fechamento
// ============================================================
// DATEDIFF(data_fechamento, data_cadastro) = diferença em dias
// Mas não temos "data_fechamento" salva — usamos a data_venda
// como proxy (data em que a venda foi registrada)

$r6 = mysqli_query($conexao,
    "SELECT
        AVG(DATEDIFF(v.data_venda, l.criado_em)) as media_dias
     FROM vendas v
     JOIN leads l ON v.lead_id = l.id
     WHERE DATEDIFF(v.data_venda, l.criado_em) >= 0"
    // >= 0 filtra casos onde data_venda < criado_em (erro de dados)
);
$row6       = mysqli_fetch_assoc($r6);
$media_dias = $row6['media_dias'] !== null ? round((float)$row6['media_dias']) : null;

// ============================================================
// BLOCO 7: Últimos 5 leads para a tabela do dashboard
// ============================================================
$ultimos_leads = mysqli_query($conexao,
    "SELECT id, nome, origem, status, criado_em, valor, possivel_ganho, tipo_proposta
     FROM leads ORDER BY criado_em DESC LIMIT 5"
);
?>
<!DOCTYPE html>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Painel</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Grids do dashboard ── */
        .dash-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }
        .dash-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 1024px) {
            .dash-grid-4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .dash-grid-4 { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .dash-grid-2 { grid-template-columns: 1fr; gap: 12px; }
        }
        @media (max-width: 480px) {
            .dash-grid-4 { grid-template-columns: 1fr; }
        }
        /* ── Barra de meta ── */
        .meta-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .meta-header {
            display: flex; align-items: center;
            justify-content: space-between;
            margin-bottom: 12px; gap: 12px;
        }
        .meta-titulo {
            font-size: 13px; font-weight: 700;
            color: var(--text-1);
        }
        .meta-valores {
            font-size: 12px; color: var(--text-3);
            white-space: nowrap;
        }
        .meta-valores strong { color: var(--text-1); }
        .meta-barra-bg {
            height: 10px; background: var(--border);
            border-radius: 10px; overflow: hidden;
            margin-bottom: 8px;
        }
        .meta-barra {
            height: 100%; border-radius: 10px;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            /* Cor muda conforme o progresso */
        }
        .meta-rodape {
            display: flex; align-items: center;
            justify-content: space-between; gap: 12px;
        }
        .meta-pct {
            font-size: 22px; font-weight: 800;
            letter-spacing: -0.5px; line-height: 1;
        }
        .meta-faltam {
            font-size: 12px; color: var(--text-3);
        }
        /* Formulário inline de edição da meta (só admin) */
        .meta-form {
            display: flex; align-items: center; gap: 6px;
        }
        .meta-input {
            height: 30px; width: 130px;
            border: 1px solid var(--border-2);
            border-radius: var(--radius);
            padding: 0 10px 0 22px;
            font-size: 12px; font-family: 'Manrope', sans-serif;
            color: var(--text-1); background: var(--surface-2);
            outline: none; transition: border-color 0.15s;
        }
        .meta-input:focus { border-color: var(--azul); background: var(--surface); }
        .meta-input-wrap { position: relative; }
        .meta-input-prefix {
            position: absolute; left: 8px; top: 50%;
            transform: translateY(-50%);
            font-size: 11px; color: var(--text-3); font-weight: 600;
            pointer-events: none;
        }
        /* ── Barra de progresso genérica ── */
        .progress-wrap {
            height: 6px;
            background: var(--border);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 6px;
        }
        .progress-bar {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
        }

        /* ── Card de origem ── */
        .origem-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .origem-item:last-child { border-bottom: none; }
        .origem-nome {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-1);
            min-width: 90px;
        }
        .origem-stats {
            flex: 1;
        }
        .origem-numeros {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--text-3);
            margin-bottom: 4px;
        }
        .origem-taxa {
            font-size: 12px;
            font-weight: 700;
            color: var(--azul);
            min-width: 38px;
            text-align: right;
        }

        /* ── Número destaque grande ── */
        .stat-grande {
            font-family: 'Manrope', sans-serif;
            font-size: 36px;
            font-weight: 800;
            letter-spacing: -1px;
            line-height: 1;
            color: var(--text-1);
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-3);
            margin-top: 4px;
        }

        /* ── Grid de métricas 2x2 ── */
        .grade-metricas {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1px;
            background: var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .metrica-bloco {
            background: var(--surface);
            padding: 16px 18px;
        }
        .metrica-bloco:first-child { border-radius: var(--radius-lg) 0 0 0; }
        .metrica-bloco:nth-child(2) { border-radius: 0 var(--radius-lg) 0 0; }
        .metrica-bloco:nth-child(3) { border-radius: 0 0 0 var(--radius-lg); }
        .metrica-bloco:last-child { border-radius: 0 0 var(--radius-lg) 0; }
        .metrica-numero {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-1);
            letter-spacing: -0.5px;
            line-height: 1;
        }
        .metrica-desc {
            font-size: 11px;
            color: var(--text-3);
            margin-top: 4px;
            font-weight: 500;
        }

        /* Seção título dentro do card */
        .card-secao-titulo {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-secao-titulo a {
            font-size: 11px;
            color: var(--azul);
            text-decoration: none;
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0;
        }

        /* Donut de conversão (círculo SVG) */
        .donut-wrap {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .donut-svg { flex-shrink: 0; }
        .donut-legenda { flex: 1; }
        .donut-legenda-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-2);
            margin-bottom: 6px;
        }
        .donut-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .donut-legenda-item strong {
            margin-left: auto;
            color: var(--text-1);
        }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">Painel</span>
        <div class="topbar-acoes">
            <a href="leads_novo.php" class="btn btn-primary btn-sm">+ Novo lead</a>
        </div>
    </div>

    <div class="page-content">

        <!-- ── Barra de meta mensal ── -->
        <?php
        $pct_meta  = ($meta_mes > 0 && $faturamento_mes > 0)
            ? min(round(($faturamento_mes / $meta_mes) * 100, 1), 100) : 0;
        $falta     = max($meta_mes - $faturamento_mes, 0);
        $cor_barra = $pct_meta >= 80 ? 'var(--verde)'
                   : ($pct_meta >= 40 ? 'var(--amarelo)' : 'var(--vermelho)');
        $dias_restantes      = (int)date('t') - (int)date('j');
        $por_dia_necessario  = ($dias_restantes > 0 && $falta > 0)
            ? round($falta / $dias_restantes, 2) : 0;
        // Mês em português
        $meses_pt = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
                     'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        $mes_label = $meses_pt[(int)date('n') - 1] . ' ' . date('Y');
        ?>
        <div class="meta-card">
            <div class="meta-header">
                <!-- Título + mês -->
                <div>
                    <div class="meta-titulo">
                        Meta de vendas
                        <span style="color:var(--text-3);font-weight:500;font-size:12px;">
                            — <?php echo $mes_label; ?>
                        </span>
                    </div>
                </div>

                <!-- Valor atual vs meta -->
                <div class="meta-valores">
                    <strong style="color:var(--verde);">
                        R$ <?php echo number_format($faturamento_mes,2,',','.'); ?>
                    </strong>
                    <?php if ($meta_mes > 0): ?>
                        de R$ <?php echo number_format($meta_mes,2,',','.'); ?>
                    <?php else: ?>
                        <span style="color:var(--text-3);">— Meta não definida</span>
                    <?php endif; ?>
                </div>

                <!-- Formulário POST direto — só admin -->
                <?php if (eh_admin()): ?>
                <form method="post" action="dashboard.php"
                      style="display:flex;align-items:center;gap:6px;">
                    <input type="hidden" name="acao_meta" value="1">
                    <div class="meta-input-wrap">
                        <span class="meta-input-prefix">R$</span>
                        <input class="meta-input" type="number"
                            name="meta_valor"
                            placeholder="Meta do mês"
                            step="100" min="0"
                            value="<?php echo $meta_mes > 0 ? (int)$meta_mes : ''; ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"
                        style="height:34px;font-size:12px;padding:0 14px;white-space:nowrap;">
                        <?php echo $meta_mes > 0 ? '✏️ Alterar' : 'Definir'; ?>
                    </button>
                    <?php if ($meta_mes > 0): ?>
                    <button type="submit" name="meta_zerar" value="1"
                        class="btn btn-secondary btn-sm"
                        style="height:34px;font-size:12px;padding:0 10px;"
                        onclick="return confirm('Remover a meta deste mês?')">
                        ✕
                    </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>

            <!-- Barra de progresso -->
            <div class="meta-barra-bg">
                <div class="meta-barra"
                     style="width:<?php echo $pct_meta; ?>%;
                            background:<?php echo $meta_mes > 0 ? $cor_barra : 'var(--border)'; ?>;
                            transition:width 0.8s ease;">
                </div>
            </div>

            <!-- Rodapé com info -->
            <div class="meta-rodape">
                <div>
                    <div class="meta-pct" style="color:<?php echo $meta_mes > 0 ? $cor_barra : 'var(--text-3)'; ?>;">
                        <?php echo $meta_mes > 0 ? $pct_meta . '%' : '—'; ?>
                    </div>
                    <div class="meta-faltam">
                        <?php if ($meta_mes <= 0): ?>
                            Defina uma meta para acompanhar o progresso
                        <?php elseif ($faturamento_mes >= $meta_mes): ?>
                            🎉 Meta atingida! Parabéns!
                        <?php else: ?>
                            Faltam R$ <?php echo number_format($falta,2,',','.'); ?> para a meta
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($meta_mes > 0 && $falta > 0 && $dias_restantes > 0): ?>
                <div style="text-align:right;">
                    <div style="font-size:12px;color:var(--text-3);">
                        <?php echo $dias_restantes; ?> dias restantes
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:2px;">
                        ~R$ <?php echo number_format($por_dia_necessario,2,',','.'); ?> por dia
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Alertas de lembretes urgentes ── -->
        <?php if ($total_urgentes > 0): ?>
        <div style="margin-bottom:16px;">
            <?php
            $tipos_ico = [
                'reuniao'         => '🤝',
                'follow_up'       => '📞',
                'enviar_proposta' => '📄',
            ];
            foreach ($lembretes_urgentes as $lem):
                $dt       = new DateTime($lem['data_hora']);
                $agora    = new DateTime();
                $vencido  = $dt < $agora;
                $bg       = $vencido ? 'var(--vermelho-light)' : 'var(--amarelo-light)';
                $borda    = $vencido ? '#FECACA' : '#FCD34D';
                $cor_txt  = $vencido ? 'var(--vermelho-text)' : 'var(--amarelo-text)';
                $ico      = $tipos_ico[$lem['tipo']] ?? '📌';
                $prazo    = $vencido
                    ? 'Vencido — ' . $dt->format('d/m H:i')
                    : 'Hoje às ' . $dt->format('H:i');
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:<?php echo $bg; ?>;border:1px solid <?php echo $borda; ?>;border-radius:var(--radius-lg);margin-bottom:8px;">
                <span style="font-size:18px;"><?php echo $ico; ?></span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:700;color:<?php echo $cor_txt; ?>;">
                        <?php echo htmlspecialchars($lem['titulo']); ?>
                    </div>
                    <div style="font-size:11px;color:<?php echo $cor_txt; ?>;opacity:0.8;margin-top:2px;">
                        <?php echo $prazo; ?>
                        · Lead: <strong><?php echo htmlspecialchars($lem['lead_nome']); ?></strong>
                    </div>
                </div>
                <a href="leads_editar.php?id=<?php echo $lem['lead_id_num']; ?>&aba=lembretes"
                   class="btn btn-sm"
                   style="background:<?php echo $vencido ? 'var(--vermelho)' : 'var(--amarelo)'; ?>;color:white;border:none;white-space:nowrap;">
                    Ver lead →
                </a>
                <form action="lembrete_salvar.php" method="post" style="display:inline;">
                    <input type="hidden" name="acao" value="concluir">
                    <input type="hidden" name="id" value="<?php echo $lem['id']; ?>">
                    <input type="hidden" name="lead_id" value="<?php echo $lem['lead_id_num']; ?>">
                    <button type="submit" class="btn btn-sm"
                        style="background:rgba(0,0,0,0.1);color:<?php echo $cor_txt; ?>;border:none;"
                        title="Marcar como concluído">✓ Concluir</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Linha 1: Cards de métricas rápidas ── -->
        <div class="dash-grid-4" style="margin-bottom:20px;">

            <div class="metric-card">
                <div class="metric-label">Total de leads</div>
                <div class="metric-value"><?php echo $total_leads; ?></div>
            </div>

            <div class="metric-card azul">
                <div class="metric-label">Taxa de conversão</div>
                <div class="metric-value"><?php echo $taxa_conversao; ?>%</div>
            </div>

            <div class="metric-card verde">
                <div class="metric-label">Faturamento do mês</div>
                <div class="metric-value" style="font-size:18px;">
                    R$ <?php echo number_format($faturamento_mes, 2, ',', '.'); ?>
                </div>
            </div>

            <div class="metric-card <?php echo $ticket_medio_geral > 0 ? 'azul' : ''; ?>">
                <div class="metric-label">Ticket médio (6m)</div>
                <div class="metric-value" style="font-size:18px;">
                    <?php echo $ticket_medio_geral > 0
                        ? 'R$ ' . number_format($ticket_medio_geral, 2, ',', '.')
                        : '—';
                    ?>
                </div>
            </div>

        </div>

        <!-- ── Linha 2: Conversão + Origens ── -->
        <div class="dash-grid-2" style="margin-bottom:16px;">

            <!-- Card: Funil de leads (status) -->
            <div class="card">
                <div class="card-secao-titulo">
                    Funil de leads
                    <a href="leads.php">Ver todos →</a>
                </div>

                <!-- Donut chart SVG de taxa de conversão -->
                <div class="donut-wrap" style="margin-bottom:16px;">
                    <?php
                    // Calculando o círculo SVG do donut
                    // circumference = 2 * π * r = 2 * 3.14159 * 30 ≈ 188.5
                    $r_svg   = 30;
                    $circ    = 2 * M_PI * $r_svg; // M_PI = π em PHP
                    $offset  = $circ * (1 - $taxa_conversao / 100);
                    // stroke-dashoffset controla quanto do círculo é "vazio"
                    // offset alto = pouco preenchido; offset baixo = muito preenchido
                    ?>
                    <svg class="donut-svg" width="80" height="80" viewBox="0 0 80 80">
                        <!-- Círculo de fundo (cinza) -->
                        <circle cx="40" cy="40" r="<?php echo $r_svg; ?>"
                            fill="none" stroke="var(--border)" stroke-width="8"/>
                        <!-- Círculo de progresso (azul) -->
                        <circle cx="40" cy="40" r="<?php echo $r_svg; ?>"
                            fill="none" stroke="#008CFF" stroke-width="8"
                            stroke-dasharray="<?php echo round($circ, 2); ?>"
                            stroke-dashoffset="<?php echo round($offset, 2); ?>"
                            stroke-linecap="round"
                            transform="rotate(-90 40 40)"/>
                            <!-- rotate(-90) faz o progresso começar do topo -->
                        <text x="40" y="44" text-anchor="middle"
                            font-size="14" font-weight="800"
                            fill="var(--text-1)" font-family="Manrope">
                            <?php echo $taxa_conversao; ?>%
                        </text>
                    </svg>

                    <div class="donut-legenda">
                        <?php
                        $itens_funil = [
                            ['Novos',            $total_novos,       '#008CFF'],
                            ['Em contato',       $total_contato,     '#F59E0B'],
                            ['Proposta enviada', $total_proposta,    '#7C3AED'],
                            ['Negociação',       $total_negociacao,  '#F97316'],
                            ['Fechados',         $total_fechados,    '#10B981'],
                            ['Perdidos',         $total_perdidos,    '#EF4444'],
                        ];
                        foreach ($itens_funil as [$label, $qtd, $cor]):
                        ?>
                        <div class="donut-legenda-item">
                            <div class="donut-dot" style="background:<?php echo $cor; ?>;"></div>
                            <span><?php echo $label; ?></span>
                            <strong><?php echo $qtd; ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Barras de progresso por status -->
                <?php foreach ($itens_funil as [$label, $qtd, $cor]):
                    $pct = $total_leads > 0 ? round($qtd / $total_leads * 100) : 0;
                ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-3);margin-bottom:3px;">
                        <span><?php echo $label; ?></span>
                        <span><?php echo $pct; ?>%</span>
                    </div>
                    <div class="progress-wrap">
                        <div class="progress-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $cor; ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Card: Origem que mais converte -->
            <div class="card">
                <div class="card-secao-titulo">Origem que mais converte</div>

                <?php if (empty($origens_dados)): ?>
                    <div class="empty-state" style="padding:2rem 0;">
                        <strong>Sem dados ainda</strong>
                        Cadastre leads com origem para ver este relatório
                    </div>
                <?php else: ?>
                    <?php foreach ($origens_dados as $i => $orig):
                        // Cor da barra varia conforme a taxa de conversão
                        $cor_barra = $orig['taxa'] >= 50 ? '#10B981'
                                   : ($orig['taxa'] >= 20 ? '#008CFF' : '#F59E0B');
                    ?>
                    <div class="origem-item">
                        <span class="origem-nome"><?php echo htmlspecialchars($orig['origem']); ?></span>
                        <div class="origem-stats">
                            <div class="origem-numeros">
                                <span><?php echo $orig['total']; ?> leads</span>
                                <span><?php echo $orig['fechados']; ?> fechados</span>
                            </div>
                            <div class="progress-wrap">
                                <div class="progress-bar"
                                     style="width:<?php echo $orig['taxa']; ?>%;background:<?php echo $cor_barra; ?>;">
                                </div>
                            </div>
                        </div>
                        <span class="origem-taxa"><?php echo $orig['taxa']; ?>%</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Linha 3: Ticket médio + Tempo médio + Últimos leads ── -->
        <div class="dash-grid-2" style="margin-bottom:16px;">

            <!-- Card: Ticket médio por mês (gráfico) -->
            <div class="card">
                <div class="card-secao-titulo">Ticket médio por mês</div>

                <?php if (empty($ticket_labels)): ?>
                    <div class="empty-state" style="padding:2rem 0;">
                        <strong>Sem vendas ainda</strong>
                        Feche um lead para ver o ticket médio
                    </div>
                <?php else: ?>
                    <div style="position:relative;height:160px;">
                        <canvas id="grafico-ticket"></canvas>
                    </div>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:11px;color:var(--text-3);">Média geral (6 meses)</span>
                        <span style="font-size:15px;font-weight:800;color:var(--azul);">
                            R$ <?php echo number_format($ticket_medio_geral, 2, ',', '.'); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card: Tempo médio + métricas extras -->
            <div class="card">
                <div class="card-secao-titulo">Métricas de performance</div>

                <!-- Tempo médio de fechamento -->
                <div style="text-align:center;padding:16px 0;border-bottom:1px solid var(--border);margin-bottom:16px;">
                    <?php if ($media_dias !== null): ?>
                        <div class="stat-grande"><?php echo $media_dias; ?></div>
                        <div class="stat-label">dias em média para fechar um lead</div>
                        <div style="font-size:11px;color:var(--text-3);margin-top:6px;">
                            Entre o cadastro e a venda
                        </div>
                    <?php else: ?>
                        <div class="stat-grande" style="color:var(--text-3);">—</div>
                        <div class="stat-label">Feche um lead para calcular</div>
                    <?php endif; ?>
                </div>

                <!-- Grade 2x2 de métricas extras -->
                <div class="grade-metricas">
                    <div class="metrica-bloco">
                        <div class="metrica-numero"><?php echo $total_fechados; ?></div>
                        <div class="metrica-desc">Leads fechados</div>
                    </div>
                    <div class="metrica-bloco">
                        <div class="metrica-numero"><?php echo $total_perdidos; ?></div>
                        <div class="metrica-desc">Leads perdidos</div>
                    </div>
                    <div class="metrica-bloco">
                        <div class="metrica-numero"><?php echo $vendas_mes; ?></div>
                        <div class="metrica-desc">Vendas este mês</div>
                    </div>
                    <div class="metrica-bloco">
                        <div class="metrica-numero">
                            <?php
                            // Relação fechados/perdidos — mostra o "aproveitamento"
                            $total_decididos = $total_fechados + $total_perdidos;
                            echo $total_decididos > 0
                                ? round($total_fechados / $total_decididos * 100) . '%'
                                : '—';
                            ?>
                        </div>
                        <div class="metrica-desc">Aproveitamento</div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Linha 4: Tabela de últimos leads ── -->
        <div class="table-container">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:13px;font-weight:700;">Últimos leads</span>
                <a href="leads.php" class="btn btn-secondary btn-sm">Ver todos</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Nome</th><th>Tipo</th><th>Valor</th><th>Status</th><th>Data</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($ultimos_leads) === 0): ?>
                    <tr><td colspan="5">
                        <div class="empty-state">
                            <strong>Nenhum lead ainda</strong>
                            Clique em "+ Novo lead" para começar
                        </div>
                    </td></tr>
                <?php else: while ($l = mysqli_fetch_assoc($ultimos_leads)): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($l['nome']); ?></strong></td>
                        <td>
                            <?php if (!empty($l['tipo_proposta'])):
                                $pc = 'prop-'.str_replace(' ','-',$l['tipo_proposta']); ?>
                                <span style="display:inline-flex;align-items:center;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;background:var(--surface-2);border:1px solid var(--border);color:var(--text-2);">
                                    <?php echo htmlspecialchars($l['tipo_proposta']); ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['status'] === 'fechado' && $l['valor'] > 0): ?>
                                <span style="color:var(--verde);font-weight:700;font-size:13px;">
                                    R$ <?php echo number_format($l['valor'],2,',','.'); ?>
                                </span>
                            <?php elseif (($l['possivel_ganho'] ?? 0) > 0): ?>
                                <span style="color:var(--text-3);font-size:12px;" title="Possível ganho estimado">
                                    ~R$ <?php echo number_format($l['possivel_ganho'],2,',','.'); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-3);">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php
                            $map = [
                                'novo'       => ['badge-novo',    'Novo'],
                                'em_contato'       => ['badge-contato',   'Em contato'],
                                'proposta_enviada' => ['badge-proposta',  'Proposta enviada'],
                                'negociacao'       => ['badge-negociacao','Negociação'],
                                'fechado'          => ['badge-fechado',   'Fechado'],
                                'perdido'          => ['badge-perdido',   'Perdido'],
                            ];
                            [$cls,$lbl] = $map[$l['status']] ?? ['badge-novo',$l['status']];
                            echo "<span class=\"badge $cls\">$lbl</span>";
                        ?></td>
                        <td style="color:var(--text-3);">
                            <?php echo date('d/m/Y', strtotime($l['criado_em'])); ?>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ── Linha 5: Vendas realizadas ── -->
        <div class="table-container" style="margin-top:16px;">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:13px;font-weight:700;">Vendas realizadas</span>
                <a href="financeiro.php" class="btn btn-secondary btn-sm">Ver financeiro</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Lead</th>
                        <th>Tipo</th>
                        <th>Data</th>
                        <th>Valor</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $vendas_lista = mysqli_query($conexao,
                    "SELECT v.valor, v.data_venda, l.nome as lead_nome, l.tipo_proposta
                     FROM vendas v
                     JOIN leads l ON v.lead_id = l.id
                     ORDER BY v.data_venda DESC
                     LIMIT 8"
                );
                if (mysqli_num_rows($vendas_lista) === 0): ?>
                    <tr><td colspan="4">
                        <div class="empty-state">
                            <strong>Nenhuma venda ainda</strong>
                            Feche um lead para registrar a primeira venda
                        </div>
                    </td></tr>
                <?php else: while ($v = mysqli_fetch_assoc($vendas_lista)): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($v['lead_nome']); ?></strong></td>
                        <td>
                            <?php if (!empty($v['tipo_proposta'])): ?>
                                <span style="display:inline-flex;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;background:var(--surface-2);border:1px solid var(--border);color:var(--text-2);">
                                    <?php echo htmlspecialchars($v['tipo_proposta']); ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="color:var(--text-3);">
                            <?php echo date('d/m/Y', strtotime($v['data_venda'])); ?>
                        </td>
                        <td style="color:var(--verde);font-weight:700;">
                            R$ <?php echo number_format($v['valor'], 2, ',', '.'); ?>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// ── Salvar meta via AJAX ──
    <?php if (!empty($ticket_labels)): ?>
    // Gráfico de ticket médio por mês
    var labels  = <?php echo json_encode($ticket_labels); ?>;
    var valores = <?php echo json_encode($ticket_valores); ?>;

    new Chart(document.getElementById('grafico-ticket').getContext('2d'), {
        type: 'line',
        // Usamos linha em vez de barra — melhor para ver tendência ao longo do tempo
        data: {
            labels: labels,
            datasets: [{
                data: valores,
                borderColor: '#008CFF',
                backgroundColor: 'rgba(0,140,255,0.08)',
                borderWidth: 2,
                pointBackgroundColor: '#008CFF',
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                // fill: true preenche a área abaixo da linha
                tension: 0.3
                // tension: 0 = linha reta; 0.3 = levemente curva (mais bonito)
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            return 'R$ ' + new Intl.NumberFormat('pt-BR', {
                                minimumFractionDigits: 2
                            }).format(c.parsed.y);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: {
                        font: { size: 10 },
                        callback: function(v) {
                            // Escala automática — formato compacto para qualquer valor
                            if (v >= 1000000) return 'R$ ' + (v/1000000).toFixed(1) + 'M';
                            if (v >= 1000)    return 'R$ ' + (v/1000).toFixed(0) + 'k';
                            return 'R$ ' + v;
                        },
                        // Deixa o Chart.js calcular os ticks automaticamente
                        maxTicksLimit: 6
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 } }
                }
            }
        }
    });
    <?php endif; ?>

};
</script>

</body>
</html>