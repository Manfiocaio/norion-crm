<?php
// ============================================================
// ARQUIVO: includes/sidebar.php
// O QUE FAZ: Menu lateral — exibe links conforme permissões
// ============================================================
require_once __DIR__ . '/../config/permissoes.php';

$nome_usuario = nome_usuario();
$inicial      = inicial_usuario();
$is_admin     = eh_admin();
?>
<aside class="sidebar">
    <div class="sidebar-logo">
        <img src="Logo1.svg" alt="Norion" class="logo-img"
             onerror="this.style.display='none';document.getElementById('logoFallback').style.display='flex';">
        <div id="logoFallback" class="logo-fallback" style="display:none;">
            <span class="logo-nome">Norion</span>
            <span class="logo-sub">Systems</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Menu</div>

        <!-- Dashboard — visível para admin ou quem tem ver_dashboard -->
        <?php if (tem_permissao('ver_dashboard')): ?>
        <a href="dashboard.php" class="nav-link <?php echo $pagina_atual==='dashboard'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            Painel
        </a>
        <?php endif; ?>

        <!-- Leads — visível para admin ou quem tem ver_leads -->
        <?php if (tem_permissao('ver_leads')): ?>
        <a href="leads.php" class="nav-link <?php echo $pagina_atual==='leads'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Leads
        </a>
        <?php endif; ?>

        <!-- Financeiro — visível para admin ou quem tem ver_financeiro -->
        <?php if (tem_permissao('ver_financeiro')): ?>
        <a href="financeiro.php" class="nav-link <?php echo $pagina_atual==='financeiro'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/>
            </svg>
            Financeiro
        </a>
        <?php endif; ?>

        <!-- Colaboradores — só para admin ou quem tem gerenciar_colab -->
        <?php if (tem_permissao('gerenciar_colab')): ?>
        <a href="colaboradores.php" class="nav-link <?php echo $pagina_atual==='colaboradores'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                <line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
            </svg>
            Colaboradores
            <?php
            // Conta colaboradores pendentes para mostrar badge de notificação
            if ($is_admin || tem_permissao('gerenciar_colab')):
                $pendentes_r = mysqli_query($conexao,
                    "SELECT COUNT(*) as t FROM colaboradores WHERE status='pendente'"
                );
                $pendentes = (int)(mysqli_fetch_assoc($pendentes_r)['t'] ?? 0);
                if ($pendentes > 0):
            ?>
                <span style="margin-left:auto;background:#EF4444;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;min-width:18px;text-align:center;">
                    <?php echo $pendentes; ?>
                </span>
            <?php endif; endif; ?>
        </a>
        <?php endif; ?>

        <div class="nav-section-label">Conta</div>

        <a href="perfil.php" class="nav-link <?php echo $pagina_atual==='perfil'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            Meu perfil
        </a>

        <a href="logout.php" class="nav-link">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Sair
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?php echo $inicial; ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <div class="sidebar-user-role">
                    <?php echo $is_admin ? 'Administrador' : 'Colaborador'; ?>
                </div>
            </div>
        </div>
    </div>

</aside>

<!-- Overlay escuro atrás da sidebar no mobile -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="fecharSidebar()"></div>

<script>
function abrirSidebar() {
    document.querySelector('.sidebar').classList.add('aberta');
    document.getElementById('sidebar-overlay').classList.add('ativo');
    document.body.style.overflow = 'hidden';
}
function fecharSidebar() {
    document.querySelector('.sidebar').classList.remove('aberta');
    document.getElementById('sidebar-overlay').classList.remove('ativo');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fecharSidebar();
});

// Injeta o botão hamburguer na topbar automaticamente
// assim não precisamos editar cada arquivo PHP
document.addEventListener('DOMContentLoaded', function() {
    var topbar = document.querySelector('.topbar');
    if (!topbar) return;

    var btn = document.createElement('button');
    btn.className = 'btn-menu';
    btn.setAttribute('onclick', 'abrirSidebar()');
    btn.setAttribute('aria-label', 'Abrir menu');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2">'
        + '<line x1="3" y1="6" x2="21" y2="6"/>'
        + '<line x1="3" y1="12" x2="21" y2="12"/>'
        + '<line x1="3" y1="18" x2="21" y2="18"/>'
        + '</svg>';

    // Insere como primeiro elemento da topbar
    topbar.insertBefore(btn, topbar.firstChild);
});
</script>