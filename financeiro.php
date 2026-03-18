<?php
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_permissao('ver_financeiro');
$pagina_atual = 'financeiro';
// Verifica se pode editar despesas
$pode_editar_financeiro = tem_permissao('editar_financeiro');

$msg = ""; $tipo_msg = "";

// ============================================================
// AÇÃO: Adicionar nova despesa
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_despesa'])) {
    if (!$pode_editar_financeiro) { $msg="Sem permissão para adicionar despesas."; $tipo_msg="error"; }
    else {
    $desc  = mysqli_real_escape_string($conexao, trim($_POST['descricao']));
    $valor = str_replace(',', '.', trim($_POST['valor_despesa']));
    $data  = $_POST['data_despesa'];
    if (empty($desc)||empty($valor)||empty($data)) { $msg="Preencha todos os campos."; $tipo_msg="error"; }
    elseif (!is_numeric($valor)||$valor<=0) { $msg="Valor inválido."; $tipo_msg="error"; }
    else {
        if (mysqli_query($conexao,"INSERT INTO despesas (descricao,valor,data) VALUES ('$desc',$valor,'$data')"))
            { $msg="Despesa registrada!"; $tipo_msg="success"; }
        else { $msg="Erro: ".mysqli_error($conexao); $tipo_msg="error"; }
    }
    }
}

// ============================================================
// AÇÃO: Atualizar despesa existente
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_despesa'])) {
    if (!$pode_editar_financeiro) { $msg="Sem permissão."; $tipo_msg="error"; }
    else {
    $did   = (int)$_POST['despesa_id'];
    $desc  = mysqli_real_escape_string($conexao, trim($_POST['edit_descricao']));
    $valor = str_replace(',', '.', trim($_POST['edit_valor']));
    $data  = $_POST['edit_data'];
    if (empty($desc)||empty($valor)||empty($data)) { $msg="Preencha todos os campos."; $tipo_msg="error"; }
    elseif (!is_numeric($valor)||$valor<=0) { $msg="Valor inválido."; $tipo_msg="error"; }
    else {
        if (mysqli_query($conexao,"UPDATE despesas SET descricao='$desc', valor=$valor, data='$data' WHERE id=$did"))
            { $msg="Despesa atualizada!"; $tipo_msg="success"; }
        else { $msg="Erro: ".mysqli_error($conexao); $tipo_msg="error"; }
    }
    }
}

// ============================================================
// AÇÃO: Excluir despesa
// ============================================================
if (isset($_GET['excluir_despesa']) && is_numeric($_GET['excluir_despesa'])) {
    if (!$pode_editar_financeiro) { $msg="Sem permissão."; $tipo_msg="error"; }
    else {
    $did = (int)$_GET['excluir_despesa'];
    if (mysqli_query($conexao,"DELETE FROM despesas WHERE id=$did"))
        { $msg="Despesa excluída."; $tipo_msg="success"; }
    else { $msg="Erro: ".mysqli_error($conexao); $tipo_msg="error"; }
    }
}

// ============================================================
// BUSCANDO OS DADOS FINANCEIROS
// ============================================================
$r=mysqli_query($conexao,"SELECT SUM(valor) as t FROM vendas WHERE MONTH(data_venda)=MONTH(CURDATE()) AND YEAR(data_venda)=YEAR(CURDATE())");
$faturamento_mes = (float)(mysqli_fetch_assoc($r)['t'] ?? 0);

$r2=mysqli_query($conexao,"SELECT SUM(valor) as t FROM despesas WHERE MONTH(data)=MONTH(CURDATE()) AND YEAR(data)=YEAR(CURDATE())");
$despesas_mes = (float)(mysqli_fetch_assoc($r2)['t'] ?? 0);

$resultado_mes = $faturamento_mes - $despesas_mes;

// ── Mês passado para comparação ──
$r_mp1 = mysqli_query($conexao,"SELECT SUM(valor) as t FROM vendas WHERE MONTH(data_venda)=MONTH(CURDATE()-INTERVAL 1 MONTH) AND YEAR(data_venda)=YEAR(CURDATE()-INTERVAL 1 MONTH)");
$fat_mes_passado = (float)(mysqli_fetch_assoc($r_mp1)['t'] ?? 0);

$r_mp2 = mysqli_query($conexao,"SELECT SUM(valor) as t FROM despesas WHERE MONTH(data)=MONTH(CURDATE()-INTERVAL 1 MONTH) AND YEAR(data)=YEAR(CURDATE()-INTERVAL 1 MONTH)");
$desp_mes_passado = (float)(mysqli_fetch_assoc($r_mp2)['t'] ?? 0);

$result_mes_passado = $fat_mes_passado - $desp_mes_passado;

// Calcula variação percentual entre dois valores
// Retorna array ['pct'=>18, 'sinal'=>'+', 'cor'=>'verde', 'seta'=>'↑']
function calcular_variacao($atual, $anterior) {
    if ($anterior == 0) {
        if ($atual > 0) return ['pct'=>null, 'txt'=>'novo este mês', 'cor'=>'verde', 'seta'=>'↑'];
        return null;
    }
    $pct  = round(($atual - $anterior) / $anterior * 100);
    $sub  = abs($pct) . '% vs mês passado';
    if ($pct > 0)  return ['pct'=>$pct,  'txt'=>$sub, 'cor'=>'verde',   'seta'=>'↑'];
    if ($pct < 0)  return ['pct'=>$pct,  'txt'=>$sub, 'cor'=>'vermelho','seta'=>'↓'];
    return            ['pct'=>0,    'txt'=>'igual ao mês passado', 'cor'=>'text-3',  'seta'=>'→'];
}

$var_fat    = calcular_variacao($faturamento_mes, $fat_mes_passado);
$var_desp   = calcular_variacao($despesas_mes,   $desp_mes_passado);
$var_result = calcular_variacao($resultado_mes,  $result_mes_passado);

// ============================================================
// DADOS DOS 3 GRÁFICOS — Lucro líquido (receitas − despesas)
// ============================================================
// A estratégia é buscar receitas e despesas separado por período,
// depois cruzar os resultados no PHP subtraindo as despesas.
//
// Por que não fazer tudo em um SQL só?
// Porque vendas e despesas têm datas em colunas diferentes
// (data_venda vs data), o que complica muito o JOIN.
// Fazer duas queries simples e cruzar no PHP é mais claro e seguro.

// ── Gráfico 1: Últimos 30 dias — agrupado por DIA ──

// Receitas por dia
$r_g1 = mysqli_query($conexao,
    "SELECT DATE_FORMAT(data_venda,'%Y-%m-%d') as chave,
            DATE_FORMAT(data_venda,'%d/%m')    as label,
            SUM(valor) as total
     FROM vendas
     WHERE data_venda >= CURDATE() - INTERVAL 30 DAY
     GROUP BY DATE_FORMAT(data_venda,'%Y-%m-%d')
     ORDER BY chave ASC"
);
// Guardamos num array associativo: chave = '2025-03-15', valor = receita do dia
$rec_g1 = [];
while($row = mysqli_fetch_assoc($r_g1)) {
    $rec_g1[$row['chave']] = ['label' => $row['label'], 'total' => (float)$row['total']];
}

// Despesas por dia — mesma lógica
$r_g1d = mysqli_query($conexao,
    "SELECT DATE_FORMAT(data,'%Y-%m-%d') as chave,
            SUM(valor) as total
     FROM despesas
     WHERE data >= CURDATE() - INTERVAL 30 DAY
     GROUP BY DATE_FORMAT(data,'%Y-%m-%d')"
);
// Array de despesas por dia
$desp_g1 = [];
while($row = mysqli_fetch_assoc($r_g1d)) {
    $desp_g1[$row['chave']] = (float)$row['total'];
}

// Cruzando: para cada dia com receita, subtrai a despesa daquele dia
// Se não houver despesa naquele dia, o ?? 0 garante que usamos zero
$g1_labels=[]; $g1_lucro=[]; $g1_receita=[]; $g1_despesa=[];
foreach($rec_g1 as $chave => $dados) {
    $g1_labels[]  = $dados['label'];
    $g1_receita[] = $dados['total'];
    $desp         = $desp_g1[$chave] ?? 0;
    $g1_despesa[] = $desp;
    $g1_lucro[]   = max(0, $dados['total'] - $desp);
}
$g1_valores = $g1_lucro; // compatibilidade

// ── Gráfico 2: Últimos 3 meses — agrupado por MÊS ──

// Receitas por mês
$r_g2 = mysqli_query($conexao,
    "SELECT DATE_FORMAT(data_venda,'%Y-%m') as chave,
            DATE_FORMAT(data_venda,'%m/%Y') as label,
            SUM(valor) as total
     FROM vendas
     WHERE data_venda >= CURDATE() - INTERVAL 3 MONTH
     GROUP BY DATE_FORMAT(data_venda,'%Y-%m')
     ORDER BY chave ASC"
);
$rec_g2 = [];
while($row = mysqli_fetch_assoc($r_g2)) {
    $rec_g2[$row['chave']] = ['label' => $row['label'], 'total' => (float)$row['total']];
}

// Despesas por mês
$r_g2d = mysqli_query($conexao,
    "SELECT DATE_FORMAT(data,'%Y-%m') as chave,
            SUM(valor) as total
     FROM despesas
     WHERE data >= CURDATE() - INTERVAL 3 MONTH
     GROUP BY DATE_FORMAT(data,'%Y-%m')"
);
$desp_g2 = [];
while($row = mysqli_fetch_assoc($r_g2d)) {
    $desp_g2[$row['chave']] = (float)$row['total'];
}

$g2_labels=[]; $g2_lucro=[]; $g2_receita=[]; $g2_despesa=[];
foreach($rec_g2 as $chave => $dados) {
    $g2_labels[]  = $dados['label'];
    $g2_receita[] = $dados['total'];
    $desp         = $desp_g2[$chave] ?? 0;
    $g2_despesa[] = $desp;
    $g2_lucro[]   = max(0, $dados['total'] - $desp);
}
$g2_valores = $g2_lucro;

// ── Gráfico 3: Últimos 6 meses — agrupado por MÊS ──

// Receitas por mês
$r_g3 = mysqli_query($conexao,
    "SELECT DATE_FORMAT(data_venda,'%Y-%m') as chave,
            DATE_FORMAT(data_venda,'%m/%Y') as label,
            SUM(valor) as total
     FROM vendas
     WHERE data_venda >= CURDATE() - INTERVAL 6 MONTH
     GROUP BY DATE_FORMAT(data_venda,'%Y-%m')
     ORDER BY chave ASC"
);
$rec_g3 = [];
while($row = mysqli_fetch_assoc($r_g3)) {
    $rec_g3[$row['chave']] = ['label' => $row['label'], 'total' => (float)$row['total']];
}

// Despesas por mês
$r_g3d = mysqli_query($conexao,
    "SELECT DATE_FORMAT(data,'%Y-%m') as chave,
            SUM(valor) as total
     FROM despesas
     WHERE data >= CURDATE() - INTERVAL 6 MONTH
     GROUP BY DATE_FORMAT(data,'%Y-%m')"
);
$desp_g3 = [];
while($row = mysqli_fetch_assoc($r_g3d)) {
    $desp_g3[$row['chave']] = (float)$row['total'];
}

$g3_labels=[]; $g3_lucro=[]; $g3_receita=[]; $g3_despesa=[];
foreach($rec_g3 as $chave => $dados) {
    $g3_labels[]  = $dados['label'];
    $g3_receita[] = $dados['total'];
    $desp         = $desp_g3[$chave] ?? 0;
    $g3_despesa[] = $desp;
    $g3_lucro[]   = max(0, $dados['total'] - $desp);
}
$g3_valores = $g3_lucro;

$vendas=mysqli_query($conexao,"SELECT v.id,v.valor,v.data_venda,l.nome as lead_nome,l.origem FROM vendas v JOIN leads l ON v.lead_id=l.id ORDER BY v.data_venda DESC");

// ============================================================
// LISTA DE DESPESAS — mostra apenas os últimos 30 dias
// ============================================================
// As despesas são tratadas como mensais: ficam visíveis por 30 dias.
// Depois somem da lista, mas continuam contando nos gráficos
// de 3 e 6 meses enquanto o período ainda alcançar aquela data.
// A coluna "Expira em" mostra quantos dias faltam para sumir da lista.
//
// DATEDIFF(data_limite, CURDATE()) = diferença em dias entre duas datas
// data + INTERVAL 30 DAY = soma 30 dias a uma data
$despesas=mysqli_query($conexao,
    "SELECT *,
            DATEDIFF(data + INTERVAL 30 DAY, CURDATE()) as dias_restantes
     FROM despesas
     WHERE data >= CURDATE() - INTERVAL 30 DAY
     ORDER BY data DESC"
);
// dias_restantes = quantos dias até completar 30 dias desde o cadastro
// Se for 1 = expira amanhã, se for 0 = expira hoje, negativo = já expirou
// (o WHERE já filtra os expirados, mas DATEDIFF ajuda a mostrar o aviso)
?>
<!DOCTYPE html>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Financeiro</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .fin-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px; }
        .fin-form { display:grid; grid-template-columns:1fr 110px 140px auto; gap:10px; align-items:end; margin-bottom:16px; }

        /* ── Botões de seleção de período ── */
        .btn-periodo {
            padding: 5px 12px;
            border-radius: 7px;
            border: 1px solid var(--border-2);
            background: var(--surface);
            color: var(--text-2);
            font-family: 'Manrope', sans-serif;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-periodo:hover {
            background: var(--azul-light);
            border-color: var(--azul-mid);
            color: var(--azul);
        }
        /* Estado ativo — período selecionado */
        .btn-periodo.ativo {
            background: var(--azul);
            border-color: var(--azul);
            color: white;
        }

        /* Cabeçalho do gráfico clicável */
        #grafico-titulo, #seta-periodo {
            cursor: pointer;
            user-select: none;
            /* user-select: none = não seleciona o texto ao clicar */
        }
        #grafico-titulo:hover { color: var(--azul); }

        /* ── Linha de despesa com modo de edição inline ── */
        .desp-row td { vertical-align:middle; }

        /* Modo visualização — o que aparece normalmente */
        .view-mode { display:flex; align-items:center; }

        /* Modo edição — aparece quando clicar em Editar */
        /* display:none = escondido por padrão */
        .edit-mode { display:none; }

        /* Quando a linha tem a classe "editando", inverte os modos */
        .desp-row.editando .view-mode { display:none; }
        .desp-row.editando .edit-mode { display:flex; align-items:center; gap:6px; }

        /* Inputs pequenos dentro da linha de edição */
        .input-inline {
            height:34px;
            border:1px solid var(--border-2);
            border-radius:7px;
            padding:0 10px;
            font-size:13px;
            font-family:'Manrope',sans-serif;
            color:var(--text-1);
            background:var(--surface);
            outline:none;
        }
        .input-inline:focus { border-color:var(--azul); box-shadow:0 0 0 2px rgba(0,140,255,0.12); }
        .input-inline-desc { width:160px; }
        .input-inline-valor { width:90px; }
        .input-inline-data  { width:130px; }

        /* Botões pequenos de ação dentro da linha */
        .btn-icon {
            display:inline-flex; align-items:center; justify-content:center;
            width:30px; height:30px; border-radius:7px; border:none; cursor:pointer;
            background:none; transition:background 0.12s; flex-shrink:0;
        }
        .btn-icon:hover { background:var(--surface-2); }
        .btn-icon svg { width:14px; height:14px; }
        .btn-icon-edit  svg { stroke:var(--text-3); }
        .btn-icon-save  { background:var(--azul); }
        .btn-icon-save:hover { background:var(--azul-hover); }
        .btn-icon-save svg { stroke:#fff; }
        .btn-icon-cancel svg { stroke:var(--text-3); }
        .btn-icon-delete svg { stroke:var(--vermelho); }
        .btn-icon-delete:hover { background:var(--vermelho-light); }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">Financeiro</span>
    </div>
    <div class="page-content">

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $tipo_msg==='error'?'error':'success'; ?>" style="margin-bottom:16px;">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <!-- Cards de resumo com comparação -->
        <div class="grid-3" style="margin-bottom:20px;">

            <?php
            // Helper que gera o HTML da linha de variação
            function html_var($var, $inverso = false) {
                if (!$var) return '';
                // Para despesas, crescimento é ruim (inverso)
                $cor  = $inverso
                    ? ($var['cor'] === 'verde' ? 'var(--vermelho)' : 'var(--verde)')
                    : ($var['cor'] === 'verde' ? 'var(--verde)'    : 'var(--vermelho)');
                if ($var['cor'] === 'text-3') $cor = 'var(--text-3)';
                return '<div style="display:flex;align-items:center;gap:4px;margin-top:6px;">'
                     . '<span style="font-size:12px;font-weight:700;color:'.$cor.';">'.$var['seta'].' </span>'
                     . '<span style="font-size:11px;font-weight:600;color:'.$cor.';">'
                     . htmlspecialchars($var['txt']).'</span></div>';
            }
            ?>

            <div class="metric-card verde">
                <div class="metric-label">Receitas do mês</div>
                <div class="metric-value" style="font-size:20px;">
                    R$ <?php echo number_format($faturamento_mes,2,',','.'); ?>
                </div>
                <?php echo html_var($var_fat); ?>
            </div>

            <div class="metric-card vermelho">
                <div class="metric-label">Despesas do mês</div>
                <div class="metric-value" style="font-size:20px;">
                    R$ <?php echo number_format($despesas_mes,2,',','.'); ?>
                </div>
                <?php echo html_var($var_desp, true); // inverso: despesa crescer é ruim ?>
            </div>

            <div class="metric-card <?php echo $resultado_mes>=0?'verde':'vermelho'; ?>">
                <div class="metric-label">Resultado do mês</div>
                <div class="metric-value" style="font-size:20px;">
                    <?php echo $resultado_mes>=0?'+':''; ?>R$ <?php echo number_format(abs($resultado_mes),2,',','.'); ?>
                </div>
                <?php echo html_var($var_result); ?>
            </div>

        </div>

        <!-- ============================================================
             BLOCO DOS GRÁFICOS COM SELETOR DE PERÍODO
             ============================================================ -->
        <div class="card" style="margin-bottom:20px;">

            <!-- Cabeçalho: título + seletor de série + seletor de período -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:12px;flex-wrap:wrap;">

                <!-- Título clicável -->
                <div style="display:flex;align-items:center;gap:8px;cursor:pointer;" onclick="document.getElementById('seletor-periodo').style.display=document.getElementById('seletor-periodo').style.display==='flex'?'none':'flex';document.getElementById('seta-periodo').style.transform=document.getElementById('seletor-periodo').style.display==='flex'?'rotate(180deg)':'rotate(0deg)';">
                    <span style="font-size:13px;font-weight:700;color:var(--text-1);" id="grafico-titulo">Últimos 30 dias</span>
                    <svg id="seta-periodo" style="width:13px;height:13px;stroke:var(--text-3);transition:transform 0.2s;" viewBox="0 0 24 24" fill="none" stroke-width="2.5">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>

                <!-- Alternador de série -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <?php
                    $series = ['lucro'=>'Lucro','receita'=>'Receita','despesa'=>'Despesas'];
                    $cores  = ['lucro'=>'#008CFF','receita'=>'#10B981','despesa'=>'#EF4444'];
                    foreach ($series as $k => $label): ?>
                    <button id="btn-serie-<?php echo $k; ?>"
                        onclick="trocarSerie('<?php echo $k; ?>')"
                        class="btn-serie <?php echo $k === 'lucro' ? 'ativo' : ''; ?>"
                        style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;border:1px solid var(--border-2);background:var(--surface);color:var(--text-2);cursor:pointer;transition:all 0.15s;">
                        <span class="radio-dot" style="width:8px;height:8px;border-radius:50%;border:2px solid <?php echo $cores[$k]; ?>;background:<?php echo $k === 'lucro' ? $cores[$k] : 'transparent'; ?>;flex-shrink:0;transition:background 0.15s;"></span>
                        <?php echo $label; ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Seletor de período -->
                <div id="seletor-periodo" style="display:none;gap:6px;align-items:center;">
                    <button class="btn-periodo ativo" onclick="trocarGrafico(1)" id="btn-p1">30 dias</button>
                    <button class="btn-periodo"       onclick="trocarGrafico(2)" id="btn-p2">3 meses</button>
                    <button class="btn-periodo"       onclick="trocarGrafico(3)" id="btn-p3">6 meses</button>
                </div>
            </div>

            <!-- Os três canvas — só um fica visível por vez -->
            <div id="wrap-g1" style="position:relative;height:260px;display:block;">
                <canvas id="grafico1"></canvas>
            </div>
            <div id="wrap-g2" style="position:relative;height:260px;display:none;">
                <canvas id="grafico2"></canvas>
            </div>
            <div id="wrap-g3" style="position:relative;height:260px;display:none;">
                <canvas id="grafico3"></canvas>
            </div>

        </div>

        <div class="fin-grid">

            <!-- VENDAS -->
            <div class="table-container">
                <div style="padding:14px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;">Vendas realizadas</div>
                <table>
                    <thead><tr><th>Lead</th><th>Origem</th><th>Data</th><th>Valor</th></tr></thead>
                    <tbody>
                    <?php if(mysqli_num_rows($vendas)===0): ?>
                        <tr><td colspan="4"><div class="empty-state"><strong>Nenhuma venda ainda</strong></div></td></tr>
                    <?php else: while($v=mysqli_fetch_assoc($vendas)): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($v['lead_nome']); ?></strong></td>
                            <td><?php echo $v['origem']?'<span class="tag">'.htmlspecialchars($v['origem']).'</span>':'—'; ?></td>
                            <td style="color:var(--text-3);"><?php echo date('d/m/Y',strtotime($v['data_venda'])); ?></td>
                            <td style="color:var(--verde);font-weight:700;">R$ <?php echo number_format($v['valor'],2,',','.'); ?></td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- DESPESAS -->
            <div>
                <!-- Formulário de nova despesa — só para quem tem permissão -->
                <?php if ($pode_editar_financeiro): ?>
                <div class="card" style="margin-bottom:16px;">
                    <div style="font-size:13px;font-weight:700;margin-bottom:14px;">Adicionar despesa</div>
                    <form action="" method="post">
                        <div class="fin-form">
                            <div>
                                <label class="form-label">Descrição</label>
                                <input class="form-control" type="text" name="descricao" placeholder="Ex: Domínio, ferramentas...">
                            </div>
                            <div>
                                <label class="form-label">Valor (R$)</label>
                                <input class="form-control" type="number" name="valor_despesa" placeholder="0.00" step="0.01" min="0">
                            </div>
                            <div>
                                <label class="form-label">Data</label>
                                <input class="form-control" type="date" name="data_despesa" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div style="padding-top:20px;">
                                <button type="submit" name="salvar_despesa" class="btn btn-primary" style="height:40px;padding:0 14px;">+ Add</button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="card" style="margin-bottom:16px;padding:12px 16px;">
                    <div style="font-size:12px;color:var(--text-3);text-align:center;">
                        Você tem acesso somente para visualizar as despesas.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tabela de despesas com edição inline -->
                <div class="table-container">
                    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-size:13px;font-weight:700;">Despesas do mês</span>
                        <!-- Explicação do comportamento de expiração -->
                        <span style="font-size:11px;color:var(--text-3);">Somem da lista após 30 dias</span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Descrição</th>
                                <th>Data</th>
                                <th>Valor</th>
                                <th>Expira em</th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(mysqli_num_rows($despesas)===0): ?>
                            <tr><td colspan="5"><div class="empty-state"><strong>Nenhuma despesa este mês</strong></div></td></tr>
                        <?php else: while($d=mysqli_fetch_assoc($despesas)): ?>

                            <tr class="desp-row" id="row-<?php echo $d['id']; ?>">

                                <!-- COLUNA: Descrição -->
                                <td>
                                    <span class="view-mode">
                                        <?php echo htmlspecialchars($d['descricao']); ?>
                                    </span>
                                    <span class="edit-mode">
                                        <input class="input-inline input-inline-desc" type="text"
                                            name="edit_descricao"
                                            form="form-edit-<?php echo $d['id']; ?>"
                                            value="<?php echo htmlspecialchars($d['descricao']); ?>">
                                    </span>
                                </td>

                                <!-- COLUNA: Data -->
                                <td>
                                    <span class="view-mode" style="color:var(--text-3);">
                                        <?php echo date('d/m/Y',strtotime($d['data'])); ?>
                                    </span>
                                    <span class="edit-mode">
                                        <input class="input-inline input-inline-data" type="date"
                                            name="edit_data"
                                            form="form-edit-<?php echo $d['id']; ?>"
                                            value="<?php echo $d['data']; ?>">
                                    </span>
                                </td>

                                <!-- COLUNA: Valor -->
                                <td>
                                    <span class="view-mode" style="color:var(--vermelho);font-weight:700;">
                                        R$ <?php echo number_format($d['valor'],2,',','.'); ?>
                                    </span>
                                    <span class="edit-mode">
                                        <input class="input-inline input-inline-valor" type="number"
                                            name="edit_valor"
                                            form="form-edit-<?php echo $d['id']; ?>"
                                            step="0.01" min="0"
                                            value="<?php echo $d['valor']; ?>">
                                    </span>
                                </td>

                                <!-- COLUNA: Expira em -->
                                <td class="view-mode">
                                    <?php
                                    $dias = (int)$d['dias_restantes'];
                                    // dias_restantes vem do DATEDIFF que calculamos na query
                                    if ($dias <= 0) {
                                        // Expira hoje (0) — já não devia aparecer mas por segurança
                                        echo '<span style="font-size:11px;font-weight:600;color:var(--vermelho);">Hoje</span>';
                                    } elseif ($dias <= 3) {
                                        // Menos de 3 dias — aviso vermelho urgente
                                        echo '<span style="font-size:11px;font-weight:600;color:var(--vermelho);">' . $dias . ' dia' . ($dias > 1 ? 's' : '') . '</span>';
                                    } elseif ($dias <= 7) {
                                        // Menos de 7 dias — aviso amarelo
                                        echo '<span style="font-size:11px;font-weight:600;color:var(--amarelo);">' . $dias . ' dias</span>';
                                    } else {
                                        // Mais de 7 dias — cor discreta
                                        echo '<span style="font-size:11px;color:var(--text-3);">' . $dias . ' dias</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <!-- ── Botões no modo VISUALIZAÇÃO ── -->
                                    <div class="view-mode" style="gap:4px;">
                                        <?php if ($pode_editar_financeiro): ?>
                                        <!-- Botão Editar: chama editarDespesa(id) via JS -->
                                        <button type="button" class="btn-icon btn-icon-edit"
                                            onclick="editarDespesa(<?php echo $d['id']; ?>)"
                                            title="Editar">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                            </svg>
                                        </button>
                                        <?php endif; ?>

                                        <!-- Botão Excluir -->
                                        <?php if ($pode_editar_financeiro): ?>
                                        <a href="financeiro.php?excluir_despesa=<?php echo $d['id']; ?>"
                                            onclick="return confirm('Excluir esta despesa?')"
                                            class="btn-icon btn-icon-delete" title="Excluir">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                                                <polyline points="3 6 5 6 21 6"/>
                                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                                <path d="M9 6V4h6v2"/>
                                            </svg>
                                        </a>
                                        <?php endif; ?>
                                    </div>

                                    <!-- ── Botões no modo EDIÇÃO ── -->
                                    <div class="edit-mode" style="gap:4px;">

                                        <!-- Botão Salvar: submete o form de edição -->
                                        <button type="submit"
                                            form="form-edit-<?php echo $d['id']; ?>"
                                            class="btn-icon btn-icon-save" title="Salvar">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5">
                                                <polyline points="20 6 9 17 4 12"/>
                                            </svg>
                                        </button>

                                        <!-- Botão Cancelar: volta ao modo visualização sem salvar -->
                                        <button type="button" class="btn-icon btn-icon-cancel"
                                            onclick="cancelarEdicao(<?php echo $d['id']; ?>)" title="Cancelar">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                                                <line x1="18" y1="6" x2="6" y2="18"/>
                                                <line x1="6" y1="6" x2="18" y2="18"/>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Form oculto de atualização -->
                                    <!-- Este form não aparece na tela — só existe para enviar os dados -->
                                    <!-- Os inputs acima usam form="form-edit-X" para se conectar a ele -->
                                    <form id="form-edit-<?php echo $d['id']; ?>" action="" method="post" style="display:none;">
                                        <!-- Campo oculto com o ID da despesa a ser atualizada -->
                                        <input type="hidden" name="despesa_id" value="<?php echo $d['id']; ?>">
                                        <!-- name="atualizar_despesa" diz ao PHP qual ação executar -->
                                        <input type="hidden" name="atualizar_despesa" value="1">
                                    </form>

                                </td>
                            </tr>

                        <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// window.onload garante que:
// 1) O Chart.js já foi baixado e está disponível
// 2) Todos os elementos HTML (canvas, botões) já existem na página
// Sem isso o JS tenta criar os gráficos antes dos canvas existirem → tela em branco
window.onload = function() {

    // Dados vindos do PHP — json_encode converte array PHP em JSON para o JS
    var g1_labels  = <?php echo json_encode($g1_labels); ?>;
    var g1_valores = <?php echo json_encode($g1_valores); ?>;
    if (!g1_labels.length)  { g1_labels=['Sem dados'];  g1_valores=[0]; }

    var g2_labels  = <?php echo json_encode($g2_labels); ?>;
    var g2_valores = <?php echo json_encode($g2_valores); ?>;
    if (!g2_labels.length)  { g2_labels=['Sem dados'];  g2_valores=[0]; }

    var g3_labels  = <?php echo json_encode($g3_labels); ?>;
    var g3_valores = <?php echo json_encode($g3_valores); ?>;
    if (!g3_labels.length)  { g3_labels=['Sem dados'];  g3_valores=[0]; }

    // ── Dados passados do PHP para o JS ──
    var dados = {
        1: {
            labels:  <?php echo json_encode($g1_labels); ?>,
            lucro:   <?php echo json_encode($g1_lucro); ?>,
            receita: <?php echo json_encode($g1_receita); ?>,
            despesa: <?php echo json_encode($g1_despesa); ?>
        },
        2: {
            labels:  <?php echo json_encode($g2_labels); ?>,
            lucro:   <?php echo json_encode($g2_lucro); ?>,
            receita: <?php echo json_encode($g2_receita); ?>,
            despesa: <?php echo json_encode($g2_despesa); ?>
        },
        3: {
            labels:  <?php echo json_encode($g3_labels); ?>,
            lucro:   <?php echo json_encode($g3_lucro); ?>,
            receita: <?php echo json_encode($g3_receita); ?>,
            despesa: <?php echo json_encode($g3_despesa); ?>
        }
    };

    // Série ativa: 'lucro', 'receita' ou 'despesa'
    var serieAtiva = 'lucro';
    var periodoAtivo = 1;

    var corSerie = {
        lucro:   { linha: '#008CFF', fundo: 'rgba(0,140,255,0.08)',  ponto: '#008CFF' },
        receita: { linha: '#10B981', fundo: 'rgba(16,185,129,0.08)', ponto: '#10B981' },
        despesa: { linha: '#EF4444', fundo: 'rgba(239,68,68,0.08)',  ponto: '#EF4444' }
    };

    var nomeSerie = { lucro: 'Lucro líquido', receita: 'Receita', despesa: 'Despesas' };

    // Formata número como R$ com separadores
    function fmt(v) {
        return 'R$ ' + new Intl.NumberFormat('pt-BR', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        }).format(v || 0);
    }

    // Calcula ticks automáticos baseados no maior valor de todas as séries do período
    function calcularTicks(d) {
        var todos = d.lucro.concat(d.receita).concat(d.despesa);
        var maxVal = Math.max.apply(null, todos.concat([0]));
        if (maxVal === 0) return [0, 1000, 5000, 10000, 50000, 100000];
        var mag   = Math.pow(10, Math.floor(Math.log10(maxVal)));
        var passo = Math.ceil((maxVal * 1.3) / (5 * mag)) * mag;
        var ticks = [];
        for (var i = 0; i <= 5; i++) ticks.push(i * passo);
        return ticks;
    }

    // Cria o gráfico de linha para um canvas
    function criarGrafico(canvasId, d) {
        var el = document.getElementById(canvasId);
        if (!el) return null;
        var ticks = calcularTicks(d);
        var cor   = corSerie[serieAtiva];

        return new Chart(el.getContext('2d'), {
            type: 'line',
            data: {
                labels: d.labels,
                datasets: [{
                    label: nomeSerie[serieAtiva],
                    data: d[serieAtiva],
                    borderColor: cor.linha,
                    backgroundColor: cor.fundo,
                    borderWidth: 2.5,
                    pointBackgroundColor: cor.ponto,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.4,  // curva suave
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',       // tooltip mostra todos os valores do ponto
                    intersect: false     // ativa mesmo sem estar exatamente em cima do ponto
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(13,17,23,0.92)',
                        titleColor: '#E6EDF3',
                        bodyColor: '#8B949E',
                        borderColor: '#30363D',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            title: function(items) {
                                return items[0].label;
                            },
                            // Tooltip mostra as 3 métricas sempre, independente da série ativa
                            beforeBody: function(items) { return ''; },
                            label: function() { return null; }, // desativa label padrão
                            afterBody: function(items) {
                                var i   = items[0].dataIndex;
                                var rec = d.receita[i] || 0;
                                var des = d.despesa[i] || 0;
                                var luc = d.lucro[i]   || 0;
                                return [
                                    'Receita:         ' + fmt(rec),
                                    'Despesas:      ' + fmt(des),
                                    '──────────────────',
                                    'Lucro líquido: ' + fmt(luc)
                                ];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        min: 0,
                        max: ticks[ticks.length - 1],
                        grid: { color: 'rgba(128,128,128,0.08)' },
                        afterBuildTicks: function(axis) {
                            axis.ticks = ticks.map(function(v) { return { value: v }; });
                        },
                        ticks: {
                            callback: function(v) {
                                if (v >= 1000000) return 'R$ ' + (v/1000000).toFixed(1) + 'M';
                                if (v >= 1000)    return 'R$ ' + (v/1000).toFixed(0) + 'k';
                                return 'R$ ' + v;
                            },
                            font: { size: 11 }
                        },
                        afterFit: function(axis) { axis.width = 80; }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 } }
                    }
                }
            }
        });
    }

    // Cria os 3 gráficos
    var charts = {
        1: criarGrafico('grafico1', dados[1]),
        2: criarGrafico('grafico2', dados[2]),
        3: criarGrafico('grafico3', dados[3])
    };

    // Atualiza todos os gráficos quando muda a série ou o período
    function atualizarSerie() {
        var cor = corSerie[serieAtiva];
        [1, 2, 3].forEach(function(p) {
            var c = charts[p];
            if (!c) return;
            var ticks = calcularTicks(dados[p]);
            c.data.datasets[0].data            = dados[p][serieAtiva];
            c.data.datasets[0].label           = nomeSerie[serieAtiva];
            c.data.datasets[0].borderColor      = cor.linha;
            c.data.datasets[0].backgroundColor  = cor.fundo;
            c.data.datasets[0].pointBackgroundColor = cor.ponto;
            c.options.scales.y.max = ticks[ticks.length - 1];
            // Atualiza os ticks no próximo render
            c.options.scales.y.afterBuildTicks = function(axis) {
                axis.ticks = ticks.map(function(v) { return { value: v }; });
            };
            c.update();
        });
    }

    // Alternador de série — botões de radio
    window.trocarSerie = function(serie) {
        serieAtiva = serie;
        // Atualiza estilo dos botões
        ['lucro','receita','despesa'].forEach(function(s) {
            var btn = document.getElementById('btn-serie-' + s);
            if (!btn) return;
            var dot = btn.querySelector('.radio-dot');
            if (s === serie) {
                btn.classList.add('ativo');
                if (dot) dot.style.background = corSerie[s].linha;
            } else {
                btn.classList.remove('ativo');
                if (dot) dot.style.background = 'transparent';
            }
        });
        atualizarSerie();
    };

    // ── Seletor de período ──
    var titulos = {
        1: 'Últimos 30 dias',
        2: 'Últimos 3 meses',
        3: 'Últimos 6 meses'
    };

    window.trocarGrafico = function(numero) {
        document.getElementById('wrap-g' + periodoAtivo).style.display = 'none';
        document.getElementById('wrap-g' + numero).style.display       = 'block';
        document.getElementById('grafico-titulo').textContent           = titulos[numero];
        document.getElementById('btn-p' + periodoAtivo).classList.remove('ativo');
        document.getElementById('btn-p' + numero).classList.add('ativo');
        periodoAtivo = numero;
        if (charts[numero]) charts[numero].update();
    };

    var seletorAberto = false;
    function toggleSeletor() {
        seletorAberto = !seletorAberto;
        document.getElementById('seletor-periodo').style.display = seletorAberto ? 'flex' : 'none';
        document.getElementById('seta-periodo').style.transform  = seletorAberto ? 'rotate(180deg)' : 'rotate(0deg)';
    }
    document.getElementById('grafico-titulo').addEventListener('click', toggleSeletor);
    document.getElementById('seta-periodo').addEventListener('click', toggleSeletor);

    // ── Edição inline de despesas ──
    window.editarDespesa   = function(id) { document.getElementById('row-' + id).classList.add('editando'); };
    window.cancelarEdicao  = function(id) { document.getElementById('row-' + id).classList.remove('editando'); };

}; // fim window.onload
</script>

</body>
</html>