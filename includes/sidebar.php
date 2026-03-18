<?php
require_once __DIR__ . '/../config/permissoes.php';
$nome_usuario = nome_usuario();
$inicial      = inicial_usuario();
$is_admin     = eh_admin();
?>
<aside class="sidebar">
    <button class="sidebar-toggle-btn" onclick="NorionSidebar.toggleMini()" title="Recolher menu">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </button>

    <div class="sidebar-logo" style="height:68px;position:relative;overflow:hidden;">
        <!-- Logo normal — visível quando expandida -->
        <img src="Logo1.svg" alt="Norion" class="logo-img-normal"
             style="height:28px;width:auto;"
             onerror="this.style.display='none';">
        <!-- Logo simplificada — visível quando recolhida -->
        <img src="logosimples.svg" alt="N" class="logo-img-simples"
             style="height:32px;width:auto;">
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Menu</div>

        <?php if (tem_permissao('ver_dashboard')): ?>
        <a href="dashboard.php" data-label="Painel" class="nav-link <?php echo $pagina_atual==='dashboard'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            <span>Painel</span>
            <?php
            $fl = eh_admin() ? "" : "AND (criado_por = ".(int)($_SESSION['colab_id']??0)." OR criado_por IS NULL)";
            $ru = mysqli_query($conexao,"SELECT COUNT(*) as t FROM lembretes WHERE concluido=0 AND data_hora<=DATE_ADD(NOW(),INTERVAL 1 DAY) $fl");
            $tu = $ru ? (int)(mysqli_fetch_assoc($ru)['t']??0) : 0;
            if($tu>0): ?>
            <span style="margin-left:auto;background:#F59E0B;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;"><?php echo $tu; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (tem_permissao('ver_leads')): ?>
        <a href="leads.php" data-label="Leads" class="nav-link <?php echo $pagina_atual==='leads'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span>Leads</span>
        </a>
        <a href="funil.php" data-label="Funil" class="nav-link <?php echo $pagina_atual==='funil'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
            </svg>
            <span>Funil</span>
        </a>
        <?php endif; ?>

        <?php if (tem_permissao('ver_financeiro')): ?>
        <a href="financeiro.php" data-label="Financeiro" class="nav-link <?php echo $pagina_atual==='financeiro'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/>
            </svg>
            <span>Financeiro</span>
        </a>
        <?php endif; ?>

        <?php if (tem_permissao('gerenciar_colab')): ?>
        <a href="colaboradores.php" data-label="Colaboradores" class="nav-link <?php echo $pagina_atual==='colaboradores'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                <line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
            </svg>
            <span>Colaboradores</span>
            <?php
            $pr = mysqli_query($conexao,"SELECT COUNT(*) as t FROM colaboradores WHERE status='pendente'");
            $pn = (int)(mysqli_fetch_assoc($pr)['t']??0);
            if($pn>0): ?>
            <span style="margin-left:auto;background:#EF4444;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;"><?php echo $pn; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="nav-section-label">Conta</div>

        <a href="perfil.php" data-label="Meu perfil" class="nav-link <?php echo $pagina_atual==='perfil'?'ativo':''; ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <span>Meu perfil</span>
        </a>

        <a href="logout.php" data-label="Sair" class="nav-link">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            <span>Sair</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?php echo $inicial; ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <div class="sidebar-user-role"><?php echo $is_admin ? 'Administrador' : 'Colaborador'; ?></div>
            </div>
            <button onclick="NorionTema.alternar()" id="btn-tema-sb" title="Alternar tema"
                style="background:none;border:none;cursor:pointer;padding:5px;border-radius:6px;
                       display:flex;align-items:center;justify-content:center;
                       opacity:0.5;transition:opacity 0.15s;flex-shrink:0;"
                onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.5'">
            </button>
        </div>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebar-overlay" onclick="NorionSidebar.fechar()"></div>

<script>
var ICO_LUA = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8A90A0" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
var ICO_SOL = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8A90A0" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
var ICO_LUA_TB = ICO_LUA.replace(/#8A90A0/g,'#6B7280');
var ICO_SOL_TB = ICO_SOL.replace(/#8A90A0/g,'#6B7280');

var NorionTema = {
    atualizarIcones: function(dark) {
        var sb = document.getElementById('btn-tema-sb');
        if (sb) sb.innerHTML = dark ? ICO_SOL : ICO_LUA;
        var tb = document.getElementById('btn-tema-tb');
        if (tb) { tb.innerHTML = dark ? ICO_SOL_TB : ICO_LUA_TB; tb.title = dark ? 'Modo claro' : 'Modo escuro'; }
    },
    alternar: function() {
        var dark = !document.documentElement.classList.contains('dark');
        document.documentElement.classList.toggle('dark', dark);
        var e = new Date(); e.setFullYear(e.getFullYear()+1);
        document.cookie = 'norion_tema='+(dark?'dark':'light')+'; expires='+e.toUTCString()+'; path=/; SameSite=Lax';
        this.atualizarIcones(dark);
    }
};

var NorionSidebar = {
    abrir: function() {
        document.querySelector('.sidebar').classList.add('aberta');
        document.getElementById('sidebar-overlay').classList.add('ativo');
        document.body.style.overflow = 'hidden';
    },
    fechar: function() {
        document.querySelector('.sidebar').classList.remove('aberta');
        document.getElementById('sidebar-overlay').classList.remove('ativo');
        document.body.style.overflow = '';
    },
    toggleMini: function() {
        if (window.innerWidth < 769) return;
        var mini = document.documentElement.classList.toggle('sidebar-mini');
        localStorage.setItem('norion_sidebar_mini', mini ? '1' : '0');
    }
};

document.addEventListener('keydown', function(e) { if(e.key==='Escape') NorionSidebar.fechar(); });

document.addEventListener('DOMContentLoaded', function() {
    // Restaura estado mini (só desktop)
    if (window.innerWidth >= 769 && localStorage.getItem('norion_sidebar_mini') === '1') {
        document.documentElement.classList.add('sidebar-mini');
    }

    // Atualiza ícone de tema
    var dark = document.documentElement.classList.contains('dark');
    NorionTema.atualizarIcones(dark);

    // Hamburguer na topbar
    var topbar = document.querySelector('.topbar');
    if (!topbar) return;

    var bm = document.createElement('button');
    bm.className = 'btn-menu';
    bm.onclick = NorionSidebar.abrir;
    bm.setAttribute('aria-label','Menu');
    bm.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
    topbar.insertBefore(bm, topbar.firstChild);

    // Botão de tema na topbar
    var acoes = topbar.querySelector('.topbar-acoes');
    if (!acoes) { acoes = document.createElement('div'); acoes.className='topbar-acoes'; topbar.appendChild(acoes); }
    var bt = document.createElement('button');
    bt.id = 'btn-tema-tb';
    bt.onclick = function(){ NorionTema.alternar(); };
    bt.style.cssText = 'background:none;border:none;cursor:pointer;padding:4px 6px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;';
    bt.onmouseover = function(){ this.style.background='var(--surface-2)'; };
    bt.onmouseout  = function(){ this.style.background='none'; };
    acoes.insertBefore(bt, acoes.firstChild);
    NorionTema.atualizarIcones(dark);
});
</script>