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

// ── Quem está sendo visualizado? ──
// Se não passou ?id=, mostra o próprio perfil
$ver_id   = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
$eh_admin = eh_admin();

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

// Voltar para: colaboradores se estava vendo outro, senão dashboard/leads
$voltar_url = $ver_id ? 'colaboradores.php' : (tem_permissao('ver_dashboard') ? 'dashboard.php' : 'leads.php');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Perfil de <?php echo htmlspecialchars($nome); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .perfil-wrap {
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* ── Cabeçalho do perfil ── */
        .perfil-header {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 16px;
        }

        /* Avatar grande */
        .perfil-avatar {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 800;
            flex-shrink: 0;
        }

        .perfil-info { flex: 1; min-width: 0; }
        .perfil-nome {
            font-size: 22px; font-weight: 800;
            color: var(--text-1); letter-spacing: -0.3px;
            margin-bottom: 4px;
        }
        .perfil-cargo {
            font-size: 13px; color: var(--text-3);
            font-weight: 500; margin-bottom: 8px;
        }
        .perfil-badges { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

        .badge-id {
            font-family: monospace; font-size: 13px; font-weight: 700;
            color: var(--azul); background: var(--azul-light);
            border: 1px solid var(--azul-mid);
            padding: 3px 10px; border-radius: 20px;
        }
        .badge-status {
            font-size: 11px; font-weight: 700;
            padding: 3px 10px; border-radius: 20px;
        }
        .bs-admin    { background: #1D3557; color: #60A5FA; }
        .bs-ativo    { background: var(--verde-light); color: var(--verde-text); }
        .bs-pendente { background: var(--azul-light);  color: #0055B3; }
        .bs-bloqueado{ background: var(--vermelho-light); color: var(--vermelho-text); }

        /* Botão de editar permissões (só para admin/gerente) */
        .perfil-acoes { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; flex-shrink: 0; }

        /* ── Cards de informações ── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .info-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
        }
        .info-label {
            font-size: 11px; font-weight: 700;
            color: var(--text-3); text-transform: uppercase;
            letter-spacing: 0.6px; margin-bottom: 4px;
        }
        .info-valor {
            font-size: 14px; font-weight: 600; color: var(--text-1);
        }
        .info-sub {
            font-size: 11px; color: var(--text-3); margin-top: 2px;
        }

        /* ── Permissões ── */
        .perms-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            margin-bottom: 16px;
        }
        .perms-titulo {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            margin-bottom: 4px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .perms-sub {
            font-size: 12px; color: var(--text-3);
            margin-bottom: 16px;
        }
        .perms-grade {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .perm-chip {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px;
            border-radius: var(--radius);
            font-size: 12px; font-weight: 600;
            transition: all 0.15s;
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

        <!-- ── Cabeçalho do perfil ── -->
        <div class="perfil-header">
            <!-- Avatar colorido com inicial -->
            <div class="perfil-avatar"
                 style="background:<?php echo $cor['bg']; ?>;color:<?php echo $cor['fg']; ?>;">
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
                        <?php
                        $bs = ['ativo'=>'bs-ativo','pendente'=>'bs-pendente','bloqueado'=>'bs-bloqueado'];
                        $st = ['ativo'=>'Ativo','pendente'=>'Pendente','bloqueado'=>'Bloqueado'];
                        ?>
                        <span class="badge-status <?php echo $bs[$status]??'bs-ativo'; ?>">
                            <?php echo $st[$status] ?? $status; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ações (admin/gerente vendo outro perfil) -->
            <?php if ($ver_id && ($eh_admin || tem_permissao('gerenciar_colab'))): ?>
            <div class="perfil-acoes">
                <a href="colaboradores.php" class="btn btn-primary btn-sm">
                    ✏️ Editar permissões
                </a>
                <?php if ($status === 'ativo'): ?>
                    <a href="colaboradores.php?bloquear=<?php echo $ver_id; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Bloquear este colaborador?')">
                       Bloquear
                    </a>
                <?php elseif ($status === 'bloqueado'): ?>
                    <a href="colaboradores.php?ativar=<?php echo $ver_id; ?>"
                       class="btn btn-secondary btn-sm">
                       Reativar
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Informações básicas ── -->
        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">E-mail</div>
                <div class="info-valor" style="font-size:13px;"><?php echo htmlspecialchars($email); ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Cargo</div>
                <div class="info-valor"><?php echo htmlspecialchars($cargo); ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">ID de acesso</div>
                <div class="info-valor" style="font-family:monospace;color:var(--azul);">
                    <?php echo htmlspecialchars($id_str); ?>
                </div>
                <div class="info-sub">Usado para fazer login</div>
            </div>
            <div class="info-card">
                <div class="info-label">Membro desde</div>
                <div class="info-valor">
                    <?php echo $criado_em
                        ? date('d/m/Y', strtotime($criado_em))
                        : 'Fundador'; ?>
                </div>
                <?php if ($criado_em): ?>
                <div class="info-sub">
                    <?php
                    $dias = (int)((time() - strtotime($criado_em)) / 86400);
                    echo $dias === 0 ? 'Cadastrado hoje'
                       : ($dias === 1 ? 'Há 1 dia'
                       : "Há $dias dias");
                    ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Permissões de acesso ── -->
        <div class="perms-card">
            <div class="perms-titulo">
                <span>Permissões de acesso</span>
                <?php if ($tipo_perfil === 'admin'): ?>
                    <span class="super-badge">Acesso total</span>
                <?php else: ?>
                    <span style="font-size:12px;color:var(--text-3);">
                        <?php echo $total_perms; ?> de <?php echo $total_possiveis; ?> permissões
                    </span>
                <?php endif; ?>
            </div>
            <div class="perms-sub">
                <?php if ($tipo_perfil === 'admin'): ?>
                    Superusuário — acesso irrestrito a todos os módulos
                <?php elseif ($total_perms === 0): ?>
                    Nenhuma permissão concedida ainda
                <?php else: ?>
                    Módulos que este colaborador pode acessar
                <?php endif; ?>
            </div>

            <div class="perms-grade">
                <?php foreach ($perms_info as $perm): ?>
                <div class="perm-chip <?php echo $perm['ativo'] ? 'on' : 'off'; ?>">
                    <div class="perm-chip-dot"></div>
                    <?php echo $perm['label']; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Barra de nível de acesso -->
            <?php if ($tipo_perfil !== 'admin'): ?>
            <div class="nivel-wrap">
                <span class="nivel-label">Nível de acesso</span>
                <div class="nivel-barra-bg">
                    <div class="nivel-barra"
                         style="width:<?php echo ($total_possiveis > 0 ? round($total_perms / $total_possiveis * 100) : 0); ?>%">
                    </div>
                </div>
                <span class="nivel-pct">
                    <?php echo $total_possiveis > 0
                        ? round($total_perms / $total_possiveis * 100) . '%'
                        : '0%'; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>
</body>
</html>