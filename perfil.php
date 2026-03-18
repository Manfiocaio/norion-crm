<?php
// ============================================================
// ARQUIVO: perfil.php
// O QUE FAZ: Exibe o perfil de um usuário
// Acesso: qualquer logado pode ver seu próprio perfil
//         Admin e gerenciar_colab podem ver qualquer perfil
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_login();

$pagina_atual = 'perfil';

// ── Salvar meta (admin) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_meta']) && (eh_admin() || tem_permissao('gerenciar_colab'))) {
    $pid_post   = (int)($_POST['colab_id'] ?? 0);
    $m_fech     = is_numeric($_POST['meta_fechados'] ?? '')    ? (int)$_POST['meta_fechados']    : 0;
    $m_fat_raw  = str_replace(',', '.', trim($_POST['meta_faturamento'] ?? ''));
    $m_fat      = is_numeric($m_fat_raw) && $m_fat_raw > 0    ? (float)$m_fat_raw                : 0;
    $mes_post   = date('Y-m-01'); // sempre mês atual

    // Cria tabela se não existir
    mysqli_query($conexao,
        "CREATE TABLE IF NOT EXISTS metas_colaborador (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            colaborador_id  INT NOT NULL,
            mes             DATE NOT NULL,
            meta_fechados   INT DEFAULT 0,
            meta_faturamento DECIMAL(12,2) DEFAULT 0,
            criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_colab_mes (colaborador_id, mes)
        )"
    );

    mysqli_query($conexao,
        "INSERT INTO metas_colaborador (colaborador_id, mes, meta_fechados, meta_faturamento)
         VALUES ($pid_post, '$mes_post', $m_fech, $m_fat)
         ON DUPLICATE KEY UPDATE meta_fechados=$m_fech, meta_faturamento=$m_fat, atualizado_em=NOW()"
    );

    header("Location: perfil.php?id=$pid_post&meta_salva=1");
    exit();
}

// ── Quem está sendo visualizado? ──
// Se não passou ?id=, mostra o próprio perfil
$ver_id   = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
$eh_admin = eh_admin();
$pode_gerir = $eh_admin || tem_permissao('gerenciar_colab');
// Pode gerir metas quem é admin OU tem permissão de gerenciar colaboradores

// Monta os dados do perfil a exibir
$perfil = null;
$tipo_perfil = ''; // 'admin' ou 'colaborador'

if ($ver_id === null) {
    // Ver o próprio perfil
    if ($eh_admin) {
        $tipo_perfil = 'admin';
        $perfil = [
            'nome'       => nome_usuario(),
            'id_str'     => 'norion01',
            'cargo'      => 'Administrador',
            'email'      => '—',
            'status'     => 'ativo',
            'criado_em'  => null,
        ];
    } else {
        $tipo_perfil = 'colaborador';
        $cid = (int)$_SESSION['colab_id'];
        $r   = mysqli_query($conexao,
            "SELECT c.*, p.ver_dashboard, p.ver_leads, p.ver_financeiro,
                    p.editar_leads, p.editar_financeiro, p.gerenciar_colab
             FROM colaboradores c
             LEFT JOIN permissoes p ON p.colaborador_id_fk = c.id
             WHERE c.id = $cid"
        );
        $perfil = mysqli_fetch_assoc($r);
    }
} else {
    // Ver perfil de outro — só admin ou quem tem gerenciar_colab
    if (!$eh_admin && !tem_permissao('gerenciar_colab')) {
        header("Location: perfil.php");
        exit();
    }
    $tipo_perfil = 'colaborador';
    $r = mysqli_query($conexao,
        "SELECT c.*, p.ver_dashboard, p.ver_leads, p.ver_financeiro,
                p.editar_leads, p.editar_financeiro, p.gerenciar_colab
         FROM colaboradores c
         LEFT JOIN permissoes p ON p.colaborador_id_fk = c.id
         WHERE c.id = $ver_id"
    );
    if (!$r || mysqli_num_rows($r) === 0) {
        header("Location: colaboradores.php");
        exit();
    }
    $perfil = mysqli_fetch_assoc($r);
}

// Dados do perfil normalizado
$nome      = $tipo_perfil === 'admin' ? $perfil['nome']         : $perfil['nome_completo'];
$id_str    = $tipo_perfil === 'admin' ? 'norion01'               : $perfil['colaborador_id'];
$cargo     = $tipo_perfil === 'admin' ? 'Administrador'          : ($perfil['cargo'] ?: 'Não informado');
$email     = $tipo_perfil === 'admin' ? '—'                      : $perfil['email'];
$status    = $tipo_perfil === 'admin' ? 'admin'                  : $perfil['status'];
$criado_em = $tipo_perfil === 'admin' ? null                     : $perfil['criado_em'];
$inicial   = strtoupper(substr($nome, 0, 1));

// Cor do avatar por status
$avatar_cores = [
    'admin'    => ['bg'=>'#1D3557','fg'=>'#008CFF'],
    'ativo'    => ['bg'=>'#D1FAE5','fg'=>'#065F46'],
    'pendente' => ['bg'=>'#EFF8FF','fg'=>'#008CFF'],
    'bloqueado'=> ['bg'=>'#FEE2E2','fg'=>'#991B1B'],
];
$cor = $avatar_cores[$status] ?? $avatar_cores['ativo'];

// Permissões para exibir
$perms_info = [];
if ($tipo_perfil === 'admin') {
    $perms_info = [
        ['icone'=>'dashboard', 'label'=>'Dashboard',         'ativo'=>true],
        ['icone'=>'leads',     'label'=>'Leads',             'ativo'=>true],
        ['icone'=>'fin',       'label'=>'Financeiro',        'ativo'=>true],
        ['icone'=>'edit',      'label'=>'Editar Leads',      'ativo'=>true],
        ['icone'=>'despesa',   'label'=>'Add Despesas',      'ativo'=>true],
        ['icone'=>'colab',     'label'=>'Gerenciar Colabs',  'ativo'=>true],
    ];
} else {
    $perms_info = [
        ['icone'=>'dashboard', 'label'=>'Dashboard',         'ativo'=>(bool)$perfil['ver_dashboard']],
        ['icone'=>'leads',     'label'=>'Leads',             'ativo'=>(bool)$perfil['ver_leads']],
        ['icone'=>'fin',       'label'=>'Financeiro',        'ativo'=>(bool)$perfil['ver_financeiro']],
        ['icone'=>'edit',      'label'=>'Editar Leads',      'ativo'=>(bool)$perfil['editar_leads']],
        ['icone'=>'despesa',   'label'=>'Add Despesas',      'ativo'=>(bool)$perfil['editar_financeiro']],
        ['icone'=>'colab',     'label'=>'Gerenciar Colabs',  'ativo'=>(bool)$perfil['gerenciar_colab']],
    ];
}

$total_perms   = count(array_filter($perms_info, fn($p) => $p['ativo']));
$total_possiveis = count($perms_info);

// ── Métricas de leads ──
// Verifica se a coluna criado_por_tipo existe antes de consultar
$col_existe = mysqli_query($conexao,
    "SHOW COLUMNS FROM leads LIKE 'criado_por_tipo'"
);
$tem_coluna_criador = $col_existe && mysqli_num_rows($col_existe) > 0;

$stats = ['total'=>0,'fechados'=>0,'perdidos'=>0,'em_andamento'=>0,'faturamento'=>0,'taxa'=>0];

if ($tem_coluna_criador) {
    if ($tipo_perfil === 'admin') {
        $r_stats = mysqli_query($conexao,
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status='fechado' THEN 1 ELSE 0 END) as fechados,
                SUM(CASE WHEN status='perdido' THEN 1 ELSE 0 END) as perdidos,
                COALESCE(SUM(CASE WHEN status='fechado' THEN valor ELSE 0 END),0) as faturamento
             FROM leads
             WHERE criado_por_tipo='admin' OR criado_por_tipo IS NULL"
        );
    } else {
        $pid = (int)$perfil['id'];
        $r_stats = mysqli_query($conexao,
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status='fechado' THEN 1 ELSE 0 END) as fechados,
                SUM(CASE WHEN status='perdido' THEN 1 ELSE 0 END) as perdidos,
                COALESCE(SUM(CASE WHEN status='fechado' THEN valor ELSE 0 END),0) as faturamento
             FROM leads
             WHERE criado_por_tipo='colaborador' AND criado_por_id=$pid"
        );
    }
    if ($r_stats) {
        $row = mysqli_fetch_assoc($r_stats);
        $stats['total']       = (int)($row['total']      ?? 0);
        $stats['fechados']    = (int)($row['fechados']   ?? 0);
        $stats['perdidos']    = (int)($row['perdidos']   ?? 0);
        $stats['faturamento'] = (float)($row['faturamento'] ?? 0);
        $stats['em_andamento']= max(0, $stats['total'] - $stats['fechados'] - $stats['perdidos']);
        $stats['taxa']        = $stats['total'] > 0
            ? round($stats['fechados'] / $stats['total'] * 100)
            : 0;
    }
}

// Cor da taxa de conversão
$cor_taxa = $stats['taxa'] >= 60 ? 'var(--verde)'
          : ($stats['taxa'] >= 30 ? 'var(--amarelo)' : 'var(--vermelho)');

// ── Metas do colaborador ──
$meta_fechados  = null; // meta de leads fechados no mês
$meta_faturamento = null; // meta de faturamento no mês
$tem_metas = false;

if ($tipo_perfil === 'colaborador' && isset($perfil['id'])) {
    $pid_meta = (int)$perfil['id'];
    $mes_atual = date('Y-m-01');

    // Verifica se a tabela metas_colaborador existe
    $tm = mysqli_query($conexao, "SHOW TABLES LIKE 'metas_colaborador'");
    if ($tm && mysqli_num_rows($tm) > 0) {
        $rm = mysqli_query($conexao,
            "SELECT * FROM metas_colaborador
             WHERE colaborador_id = $pid_meta AND mes = '$mes_atual'"
        );
        if ($rm && mysqli_num_rows($rm) > 0) {
            $meta_row = mysqli_fetch_assoc($rm);
            $meta_fechados    = $meta_row['meta_fechados']    > 0 ? (int)$meta_row['meta_fechados']    : null;
            $meta_faturamento = $meta_row['meta_faturamento'] > 0 ? (float)$meta_row['meta_faturamento'] : null;
            $tem_metas = $meta_fechados !== null || $meta_faturamento !== null;
        }
    }
}

// Mês atual para o formulário de meta (admin)
$mes_label = date('F Y'); // ex: March 2026
$meses_pt  = ['January'=>'Janeiro','February'=>'Fevereiro','March'=>'Março',
               'April'=>'Abril','May'=>'Maio','June'=>'Junho','July'=>'Julho',
               'August'=>'Agosto','September'=>'Setembro','October'=>'Outubro',
               'November'=>'Novembro','December'=>'Dezembro'];
$mes_label_pt = $meses_pt[date('F')] . ' de ' . date('Y');

// Voltar para: colaboradores se estava vendo outro, senão dashboard/leads
$voltar_url = $ver_id ? 'colaboradores.php' : (tem_permissao('ver_dashboard') ? 'dashboard.php' : 'leads.php');
?>
<!DOCTYPE html>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Perfil de <?php echo htmlspecialchars($nome); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Layout geral ── */
        .perfil-wrap { width: 100%; }

        /* Grid de duas colunas: esquerda maior, direita menor */
        .perfil-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 16px;
            align-items: flex-start;
        }
        .perfil-col-esq { display: flex; flex-direction: column; gap: 16px; }
        .perfil-col-dir { display: flex; flex-direction: column; gap: 12px; }

        /* ── Cabeçalho do perfil ── */
        .perfil-header {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px 22px;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .perfil-avatar {
            width: 68px; height: 68px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; font-weight: 800; flex-shrink: 0;
        }
        .perfil-info { flex: 1; min-width: 0; }
        .perfil-nome {
            font-size: 20px; font-weight: 800;
            color: var(--text-1); letter-spacing: -0.3px; margin-bottom: 3px;
        }
        .perfil-cargo { font-size: 13px; color: var(--text-3); font-weight: 500; margin-bottom: 7px; }
        .perfil-badges { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .badge-id {
            font-family: monospace; font-size: 13px; font-weight: 700;
            color: var(--azul); background: var(--azul-light);
            border: 1px solid var(--azul-mid); padding: 3px 10px; border-radius: 20px;
        }
        .badge-status { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
        .bs-admin    { background: #1D3557; color: #60A5FA; }
        .bs-ativo    { background: var(--verde-light); color: var(--verde-text); }
        .bs-pendente { background: var(--azul-light);  color: #0055B3; }
        .bs-bloqueado{ background: var(--vermelho-light); color: var(--vermelho-text); }
        .perfil-acoes { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; flex-shrink: 0; }

        /* ── Cards de informação (coluna direita) ── */
        .info-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 14px 16px;
        }
        .info-label {
            font-size: 10px; font-weight: 700; color: var(--text-3);
            text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px;
        }
        .info-valor { font-size: 14px; font-weight: 600; color: var(--text-1); }
        .info-sub   { font-size: 11px; color: var(--text-3); margin-top: 2px; }

        /* ── Card de métricas ── */
        .metricas-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
        }
        .metricas-titulo {
            font-size: 12px; font-weight: 700; color: var(--text-3);
            text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 14px;
        }
        .metricas-pills {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }
        .metrica-pill {
            background: var(--surface-2); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 12px 10px; text-align: center;
        }
        .metrica-pill-val { font-size: 26px; font-weight: 800; line-height: 1; }
        .metrica-pill-lbl { font-size: 10px; color: var(--text-3); margin-top: 4px; font-weight: 600; }

        /* ── Permissões ── */
        .perms-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 20px 22px;
        }
        .perms-titulo {
            font-size: 13px; font-weight: 700; color: var(--text-1); margin-bottom: 4px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .perms-sub { font-size: 12px; color: var(--text-3); margin-bottom: 16px; }
        .perms-grade {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .perm-chip {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px; border-radius: var(--radius);
            font-size: 12px; font-weight: 600; transition: all 0.15s;
        }
        .perm-chip.on {
            background: var(--azul-light);
            border: 1px solid var(--azul-mid);
            color: var(--azul);
        }
        .perm-chip.off {
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text-3);
            opacity: 0.6;
        }
        .perm-chip-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .perm-chip.on  .perm-chip-dot { background: var(--azul); }
        .perm-chip.off .perm-chip-dot { background: var(--text-3); }

        /* Barra de nível de acesso */
        .nivel-wrap {
            margin-top: 14px; padding-top: 14px;
            border-top: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }
        .nivel-label { font-size: 11px; color: var(--text-3); white-space: nowrap; }
        .nivel-barra-bg {
            flex: 1; height: 6px;
            background: var(--border); border-radius: 10px; overflow: hidden;
        }
        .nivel-barra {
            height: 100%; border-radius: 10px;
            background: var(--azul);
            transition: width 0.8s ease;
        }
        .nivel-pct { font-size: 12px; font-weight: 700; color: var(--azul); white-space: nowrap; }

        /* Destaque superusuário */
        .super-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: linear-gradient(135deg, #1D3557, #0055B3);
            color: #60A5FA; font-size: 11px; font-weight: 700;
            padding: 3px 10px; border-radius: 20px;
            letter-spacing: 0.3px;
        }

        /* ── Responsividade ── */
        @media (max-width: 960px) {
            .perfil-layout { grid-template-columns: 1fr; }
            .perfil-col-dir { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        }
        @media (max-width: 600px) {
            .perfil-col-dir { grid-template-columns: 1fr; }
            .metricas-pills { grid-template-columns: repeat(2, 1fr); }
            .perms-grade    { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">Perfil</span>
        <div class="topbar-acoes">
            <a href="<?php echo $voltar_url; ?>" class="btn btn-secondary btn-sm">← Voltar</a>
        </div>
    </div>

    <div class="page-content perfil-wrap">

        <!-- Cabeçalho — fora do grid, largura total -->
        <?php
        $bs = ['ativo'=>'bs-ativo','pendente'=>'bs-pendente','bloqueado'=>'bs-bloqueado'];
        $st = ['ativo'=>'Ativo','pendente'=>'Pendente','bloqueado'=>'Bloqueado'];
        ?>
        <div class="perfil-header" style="margin-bottom:16px;">
            <div class="perfil-avatar" style="background:<?php echo $cor['bg']; ?>;color:<?php echo $cor['fg']; ?>;">
                <?php echo $inicial; ?>
            </div>
            <div class="perfil-info">
                <div class="perfil-nome"><?php echo htmlspecialchars($nome); ?></div>
                <div class="perfil-cargo"><?php echo htmlspecialchars($cargo); ?></div>
                <div class="perfil-badges">
                    <span class="badge-id"><?php echo htmlspecialchars($id_str); ?></span>
                    <?php if ($tipo_perfil === 'admin'): ?>
                        <span class="badge-status bs-admin">★ Admin</span>
                        <span class="super-badge">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Superusuário
                        </span>
                    <?php else: ?>
                        <span class="badge-status <?php echo $bs[$status]??'bs-ativo'; ?>">
                            <?php echo $st[$status] ?? $status; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($ver_id && ($eh_admin || tem_permissao('gerenciar_colab'))): ?>
            <div class="perfil-acoes">
                <a href="colaboradores.php" class="btn btn-primary btn-sm">✏️ Editar permissões</a>
                <?php if ($status === 'ativo'): ?>
                    <a href="colaboradores.php?bloquear=<?php echo $ver_id; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Bloquear este colaborador?')">Bloquear</a>
                <?php elseif ($status === 'bloqueado'): ?>
                    <a href="colaboradores.php?ativar=<?php echo $ver_id; ?>"
                       class="btn btn-secondary btn-sm">Reativar</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Grade de duas colunas -->
        <div class="perfil-layout">

            <!-- COLUNA ESQUERDA: métricas + permissões -->
            <div class="perfil-col-esq">
                <?php if ($tem_coluna_criador): ?>
                <div class="metricas-card">
                    <div class="metricas-titulo">Performance em leads</div>
                    <div class="metricas-pills">
                        <?php
                        $pills = [
                            [$stats['total'],        'Total',        'var(--text-1)'],
                            [$stats['em_andamento'], 'Em andamento', 'var(--azul)'],
                            [$stats['fechados'],     'Fechados',     'var(--verde)'],
                            [$stats['perdidos'],     'Perdidos',     'var(--vermelho)'],
                        ];
                        foreach ($pills as [$val, $lbl, $cor]):
                        ?>
                        <div class="metrica-pill">
                            <div class="metrica-pill-val" style="color:<?php echo $cor; ?>;"><?php echo $val; ?></div>
                            <div class="metrica-pill-lbl"><?php echo $lbl; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Barra de conversão -->
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:<?php echo $stats['faturamento']>0?'14':'0'; ?>px;">
                        <span style="font-size:11px;color:var(--text-3);white-space:nowrap;">Taxa de conversão</span>
                        <div style="flex:1;height:6px;background:var(--border);border-radius:10px;overflow:hidden;">
                            <div style="height:100%;border-radius:10px;background:<?php echo $cor_taxa; ?>;width:<?php echo $stats['taxa']; ?>%;transition:width 0.8s ease;"></div>
                        </div>
                        <span style="font-size:14px;font-weight:800;color:<?php echo $cor_taxa; ?>;white-space:nowrap;"><?php echo $stats['taxa']; ?>%</span>
                    </div>
                    <?php if ($stats['faturamento'] > 0): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0 0;border-top:1px solid var(--border);">
                        <span style="font-size:12px;color:var(--text-3);">💰 Faturamento gerado</span>
                        <span style="font-size:18px;font-weight:800;color:var(--verde);">R$ <?php echo number_format($stats['faturamento'],2,',','.'); ?></span>
                    </div>
                    <?php elseif ($stats['total'] === 0): ?>
                    <div style="text-align:center;padding:6px 0;font-size:12px;color:var(--text-3);">Nenhum lead criado ainda</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- ── Metas do mês ── -->
                <?php if ($tipo_perfil === 'colaborador'): ?>
                <div class="metricas-card" style="margin-top:14px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                        <div class="metricas-titulo" style="margin-bottom:0;">Meta de <?php echo $mes_label_pt; ?></div>
                        <?php if ($pode_gerir): ?>
                        <button onclick="document.getElementById('modal-meta').style.display='flex'"
                            style="font-size:11px;font-weight:600;color:var(--azul);background:var(--azul-light);
                                   border:none;padding:4px 10px;border-radius:20px;cursor:pointer;">
                            <?php echo $tem_metas ? '✏️ Editar' : '+ Definir meta'; ?>
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if (!$tem_metas): ?>
                    <div style="text-align:center;padding:14px 0;font-size:12px;color:var(--text-3);">
                        <?php echo $pode_gerir ? 'Nenhuma meta definida para este mês.' : 'Nenhuma meta definida pelo admin ainda.'; ?>
                    </div>

                    <?php else: ?>

                    <?php if ($meta_fechados !== null):
                        $prog_fech = min(100, $stats['fechados'] > 0 ? round($stats['fechados'] / $meta_fechados * 100) : 0);
                        $cor_fech  = $prog_fech >= 100 ? 'var(--verde)' : ($prog_fech >= 60 ? 'var(--azul)' : 'var(--amarelo)');
                    ?>
                    <div style="margin-bottom:14px;">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">
                            <span style="font-size:12px;font-weight:600;color:var(--text-2);">Leads fechados</span>
                            <span style="font-size:12px;color:var(--text-3);">
                                <strong style="color:<?php echo $cor_fech; ?>;"><?php echo $stats['fechados']; ?></strong>
                                / <?php echo $meta_fechados; ?>
                            </span>
                        </div>
                        <div style="height:8px;background:var(--border);border-radius:10px;overflow:hidden;">
                            <div style="height:100%;border-radius:10px;background:<?php echo $cor_fech; ?>;
                                        width:<?php echo $prog_fech; ?>%;transition:width 0.8s ease;"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:4px;">
                            <span style="font-size:10px;color:var(--text-3);"><?php echo $prog_fech; ?>% concluído</span>
                            <?php if ($prog_fech >= 100): ?>
                            <span style="font-size:10px;font-weight:700;color:var(--verde);">✓ Meta atingida!</span>
                            <?php else: ?>
                            <span style="font-size:10px;color:var(--text-3);"><?php echo $meta_fechados - $stats['fechados']; ?> faltando</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($meta_faturamento !== null):
                        $prog_fat = min(100, $stats['faturamento'] > 0 ? round($stats['faturamento'] / $meta_faturamento * 100) : 0);
                        $cor_fat  = $prog_fat >= 100 ? 'var(--verde)' : ($prog_fat >= 60 ? 'var(--azul)' : 'var(--amarelo)');
                    ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">
                            <span style="font-size:12px;font-weight:600;color:var(--text-2);">Faturamento</span>
                            <span style="font-size:12px;color:var(--text-3);">
                                <strong style="color:<?php echo $cor_fat; ?>;">R$ <?php echo number_format($stats['faturamento'],2,',','.'); ?></strong>
                                / R$ <?php echo number_format($meta_faturamento,2,',','.'); ?>
                            </span>
                        </div>
                        <div style="height:8px;background:var(--border);border-radius:10px;overflow:hidden;">
                            <div style="height:100%;border-radius:10px;background:<?php echo $cor_fat; ?>;
                                        width:<?php echo $prog_fat; ?>%;transition:width 0.8s ease;"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-top:4px;">
                            <span style="font-size:10px;color:var(--text-3);"><?php echo $prog_fat; ?>% concluído</span>
                            <?php if ($prog_fat >= 100): ?>
                            <span style="font-size:10px;font-weight:700;color:var(--verde);">✓ Meta atingida!</span>
                            <?php else: ?>
                            <span style="font-size:10px;color:var(--text-3);">
                                R$ <?php echo number_format($meta_faturamento - $stats['faturamento'],2,',','.'); ?> faltando
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php endif; // tem_metas ?>
                </div>

                <!-- Modal de definir meta (admin) -->
                <?php if ($pode_gerir && isset($perfil['id'])): ?>
                <?php if (!empty($_GET['meta_salva'])): ?>
                <div style="background:var(--verde-light);color:var(--verde-text);padding:10px 14px;
                            border-radius:var(--radius);font-size:12px;font-weight:600;margin-top:10px;">
                    ✓ Meta salva com sucesso!
                </div>
                <?php endif; ?>
                <div id="modal-meta" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
                     z-index:1000;align-items:center;justify-content:center;">
                    <div style="background:var(--surface);border-radius:var(--radius-lg);padding:24px;
                                width:100%;max-width:380px;box-shadow:var(--shadow-md);margin:16px;">
                        <div style="font-size:15px;font-weight:700;color:var(--text-1);margin-bottom:4px;">
                            Definir meta — <?php echo $mes_label_pt; ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-3);margin-bottom:18px;">
                            Para <?php echo htmlspecialchars($nome); ?>
                        </div>
                        <form method="post">
                            <input type="hidden" name="salvar_meta" value="1">
                            <input type="hidden" name="colab_id" value="<?php echo $perfil['id']; ?>">

                            <div style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--text-2);display:block;margin-bottom:6px;">
                                    Leads fechados no mês
                                </label>
                                <input type="number" name="meta_fechados" min="0" step="1"
                                    value="<?php echo $meta_fechados ?? ''; ?>"
                                    placeholder="Ex: 10"
                                    style="width:100%;padding:8px 12px;border:1px solid var(--border-2);
                                           border-radius:var(--radius);background:var(--surface-2);
                                           color:var(--text-1);font-size:13px;font-family:inherit;">
                            </div>
                            <div style="margin-bottom:20px;">
                                <label style="font-size:12px;font-weight:600;color:var(--text-2);display:block;margin-bottom:6px;">
                                    Meta de faturamento (R$)
                                </label>
                                <input type="number" name="meta_faturamento" min="0" step="0.01"
                                    value="<?php echo $meta_faturamento ?? ''; ?>"
                                    placeholder="Ex: 50000"
                                    style="width:100%;padding:8px 12px;border:1px solid var(--border-2);
                                           border-radius:var(--radius);background:var(--surface-2);
                                           color:var(--text-1);font-size:13px;font-family:inherit;">
                                <div style="font-size:11px;color:var(--text-3);margin-top:4px;">Deixe em branco para não definir</div>
                            </div>
                            <div style="display:flex;gap:8px;">
                                <button type="submit" style="flex:1;padding:9px;background:var(--azul);color:white;
                                    border:none;border-radius:var(--radius);font-size:13px;font-weight:700;cursor:pointer;">
                                    Salvar meta
                                </button>
                                <button type="button" onclick="document.getElementById('modal-meta').style.display='none'"
                                    style="padding:9px 16px;background:var(--surface-2);color:var(--text-2);
                                    border:1px solid var(--border-2);border-radius:var(--radius);font-size:13px;cursor:pointer;">
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; // tipo_perfil colaborador ?>

                <!-- Permissões -->
                <div class="perms-card">
                    <div class="perms-titulo">
                        <span>Permissões de acesso</span>
                        <?php if ($tipo_perfil === 'admin'): ?>
                            <span class="super-badge">Acesso total</span>
                        <?php else: ?>
                            <span style="font-size:12px;color:var(--text-3);"><?php echo $total_perms; ?> de <?php echo $total_possiveis; ?> permissões</span>
                        <?php endif; ?>
                    </div>
                    <div class="perms-sub">
                        <?php if ($tipo_perfil === 'admin'): ?>Superusuário — acesso irrestrito a todos os módulos
                        <?php elseif ($total_perms === 0): ?>Nenhuma permissão concedida ainda
                        <?php else: ?>Módulos que este colaborador pode acessar<?php endif; ?>
                    </div>
                    <div class="perms-grade">
                        <?php foreach ($perms_info as $perm): ?>
                        <div class="perm-chip <?php echo $perm['ativo'] ? 'on' : 'off'; ?>">
                            <div class="perm-chip-dot"></div>
                            <?php echo $perm['label']; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($tipo_perfil !== 'admin'): ?>
                    <div class="nivel-wrap">
                        <span class="nivel-label">Nível de acesso</span>
                        <div class="nivel-barra-bg">
                            <div class="nivel-barra" style="width:<?php echo ($total_possiveis>0 ? round($total_perms/$total_possiveis*100) : 0); ?>%"></div>
                        </div>
                        <span class="nivel-pct"><?php echo $total_possiveis>0 ? round($total_perms/$total_possiveis*100).'%' : '0%'; ?></span>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- fim col-esq -->

            <!-- ═══════════════════════════════
                 COLUNA DIREITA — info cards
                 ═══════════════════════════════ -->
            <div class="perfil-col-dir">

                <div class="info-card">
                    <div class="info-label">E-mail</div>
                    <div class="info-valor" style="font-size:13px;word-break:break-all;"><?php echo htmlspecialchars($email); ?></div>
                </div>

                <div class="info-card">
                    <div class="info-label">Cargo</div>
                    <div class="info-valor"><?php echo htmlspecialchars($cargo); ?></div>
                </div>

                <div class="info-card">
                    <div class="info-label">ID de acesso</div>
                    <div class="info-valor" style="font-family:monospace;color:var(--azul);font-size:16px;"><?php echo htmlspecialchars($id_str); ?></div>
                    <div class="info-sub">Usado para fazer login</div>
                </div>

                <div class="info-card">
                    <div class="info-label">Membro desde</div>
                    <div class="info-valor">
                        <?php echo $criado_em ? date('d/m/Y', strtotime($criado_em)) : 'Fundador'; ?>
                    </div>
                    <?php if ($criado_em): ?>
                    <div class="info-sub">
                        <?php
                        $dias = (int)((time() - strtotime($criado_em)) / 86400);
                        echo $dias === 0 ? 'Cadastrado hoje' : ($dias === 1 ? 'Há 1 dia' : "Há $dias dias");
                        ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- fim col-dir -->

        </div><!-- fim perfil-layout -->
    </div>
</div>
</body>
</html>