<?php
// ============================================================
// ARQUIVO: sem_permissao.php
// ESTADOS:
//   ?motivo=recem_cadastrado  → acabou de criar conta
//   ?motivo=bloqueado         → conta bloqueada
//   ?motivo=sem_permissao     → tentou acessar algo sem permissão
//   (padrão quando status=pendente) → aguardando aprovação
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';

if (!esta_logado())  { header("Location: index.php");    exit(); }
if (eh_admin())      { header("Location: dashboard.php"); exit(); }

$status_colab = $_SESSION['colab_status'] ?? 'pendente';
$nome         = nome_usuario();
$colab_id     = $_SESSION['colab_id_str'] ?? '';

// Determina o motivo da tela
// Prioridade: bloqueado > motivo GET > status pendente
if ($status_colab === 'bloqueado') {
    $motivo = 'bloqueado';
} elseif (isset($_GET['motivo'])) {
    $motivo = $_GET['motivo'];
} elseif ($status_colab === 'pendente') {
    $motivo = 'pendente';
} else {
    $motivo = 'sem_permissao';
}

// Textos e configurações por motivo
$configs = [
    'recem_cadastrado' => [
        'titulo'   => 'Conta criada com sucesso! 🎉',
        'sub'      => "Sua conta foi criada e está aguardando as <span class=\"destaque\">devidas permissões</span> do administrador.\nVocê receberá um e-mail quando o acesso for liberado.",
        'icone'    => 'relogio',
        'cor'      => 'azul',
        'mostrar_passos' => true,
        'mostrar_id'     => true,
        'mostrar_email'  => true,
    ],
    'pendente' => [
        'titulo'   => 'Aguardando aprovação',
        'sub'      => "Sua conta ainda não foi aprovada pelo administrador.\nVocê receberá um e-mail quando o acesso for liberado.",
        'icone'    => 'relogio',
        'cor'      => 'azul',
        'mostrar_passos' => true,
        'mostrar_id'     => true,
        'mostrar_email'  => true,
    ],
    'bloqueado' => [
        'titulo'   => 'Acesso bloqueado',
        'sub'      => "Sua conta foi bloqueada pelo administrador.\nEntre em contato com a Norion Systems para mais informações.",
        'icone'    => 'bloqueado',
        'cor'      => 'vermelho',
        'mostrar_passos' => false,
        'mostrar_id'     => true,
        'mostrar_email'  => false,
    ],
    'sem_permissao' => [
        'titulo'   => 'Acesso não autorizado',
        'sub'      => "Você não tem permissão para acessar esta página ou executar esta ação.\nSe precisar de acesso, entre em contato com o administrador.",
        'icone'    => 'cadeado',
        'cor'      => 'amarelo',
        'mostrar_passos' => false,
        'mostrar_id'     => false,
        'mostrar_email'  => false,
    ],
];

$cfg = $configs[$motivo] ?? $configs['sem_permissao'];

// Página de retorno (para o botão "Voltar")
$pagina_anterior = $_SERVER['HTTP_REFERER'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion Systems — <?php echo $cfg['titulo']; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Manrope',sans-serif;background:#0D1117;min-height:100vh;display:flex;align-items:center;justify-content:center;}
        body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,140,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(0,140,255,0.03) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;}
        .card{background:#161B22;border:1px solid #21262D;border-radius:20px;padding:48px 40px;text-align:center;max-width:440px;width:100%;margin:20px;}
        .logo{margin-bottom:32px;}
        .logo img{height:32px;}
        .logo-fallback{display:inline-flex;flex-direction:column;align-items:flex-start;}
        .logo-fallback .n{font-size:26px;font-weight:800;color:#fff;line-height:1;}
        .logo-fallback .s{font-size:9px;font-weight:700;color:#008CFF;letter-spacing:3px;text-transform:uppercase;margin-top:2px;}

        /* Ícone */
        .icone-wrap{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;}
        .icone-wrap.azul{background:rgba(0,140,255,0.1);border:2px solid rgba(0,140,255,0.2);animation:pulse 2s ease-in-out infinite;}
        .icone-wrap.vermelho{background:rgba(239,68,68,0.1);border:2px solid rgba(239,68,68,0.2);}
        .icone-wrap.amarelo{background:rgba(245,158,11,0.1);border:2px solid rgba(245,158,11,0.2);}
        .icone-wrap.azul svg{stroke:#008CFF;}
        .icone-wrap.vermelho svg{stroke:#EF4444;}
        .icone-wrap.amarelo svg{stroke:#F59E0B;}
        .icone-wrap svg{width:32px;height:32px;}
        @keyframes pulse{0%,100%{transform:scale(1);opacity:1;}50%{transform:scale(1.05);opacity:0.8;}}

        h1{font-size:20px;font-weight:800;color:#fff;margin-bottom:10px;}
        .sub{font-size:14px;color:#6E7681;line-height:1.7;margin-bottom:24px;white-space:pre-line;}
        .sub .destaque{color:#008CFF;font-weight:700;}

        /* ID badge */
        .id-badge{display:inline-flex;align-items:center;gap:10px;background:#0D1117;border:1px solid #30363D;border-radius:12px;padding:12px 20px;margin-bottom:24px;}
        .id-label{font-size:11px;color:#6E7681;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;}
        .id-valor{font-size:20px;font-weight:800;color:#008CFF;font-family:monospace;letter-spacing:1px;}

        /* Aviso e-mail */
        .email-aviso{background:rgba(0,140,255,0.05);border:1px solid rgba(0,140,255,0.15);border-radius:10px;padding:12px 16px;margin-bottom:24px;font-size:12px;color:#6E7681;line-height:1.5;display:flex;align-items:flex-start;gap:8px;text-align:left;}
        .email-aviso svg{width:14px;height:14px;stroke:#008CFF;flex-shrink:0;margin-top:1px;}

        /* Passos */
        .passos{display:flex;justify-content:center;gap:0;margin-bottom:28px;}
        .passo{display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;max-width:100px;position:relative;}
        .passo::after{content:'';position:absolute;top:14px;left:60%;width:80%;height:1px;background:#21262D;}
        .passo:last-child::after{display:none;}
        .passo-circulo{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;z-index:1;}
        .passo-ok{background:#10B981;color:#fff;}
        .passo-atual{background:#008CFF;color:#fff;animation:pulse 2s ease-in-out infinite;}
        .passo-wait{background:#21262D;color:#484F58;}
        .passo-texto{font-size:10px;color:#484F58;font-weight:600;text-align:center;line-height:1.3;}

        /* Botões */
        .acoes{display:flex;flex-direction:column;align-items:center;gap:10px;}
        .btn-voltar{display:inline-flex;align-items:center;gap:6px;padding:10px 24px;background:#008CFF;border:none;border-radius:8px;color:#fff;text-decoration:none;font-size:13px;font-family:'Manrope',sans-serif;font-weight:700;cursor:pointer;transition:background 0.15s;}
        .btn-voltar:hover{background:#0070CC;}
        .btn-sair{display:inline-block;padding:10px 28px;border:1px solid #30363D;border-radius:8px;color:#6E7681;text-decoration:none;font-size:13px;font-family:'Manrope',sans-serif;font-weight:600;transition:all 0.15s;}
        .btn-sair:hover{border-color:#6E7681;color:#E6EDF3;}
        .refresh{font-size:12px;color:#484F58;margin-top:14px;}
        .refresh a{color:#008CFF;text-decoration:none;}
        .refresh a:hover{text-decoration:underline;}
    </style>
</head>
<body>
<div class="card">

    <div class="logo">
        <img src="Logo1.svg" alt="Norion"
             onerror="this.style.display='none';document.getElementById('lf').style.display='inline-flex';">
        <div id="lf" class="logo-fallback" style="display:none;">
            <span class="n">Norion</span><span class="s">Systems</span>
        </div>
    </div>

    <!-- Ícone conforme o motivo -->
    <div class="icone-wrap <?php echo $cfg['cor']; ?>">
        <?php if ($cfg['icone'] === 'relogio'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
        <?php elseif ($cfg['icone'] === 'bloqueado'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
        </svg>
        <?php elseif ($cfg['icone'] === 'cadeado'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <?php endif; ?>
    </div>

    <h1><?php echo $cfg['titulo']; ?></h1>

    <p class="sub"><?php
        // Substitui o nome de forma segura
        $sub = str_replace(
            '{nome}',
            '<strong>' . htmlspecialchars(explode(' ', $nome)[0]) . '</strong>',
            $cfg['sub']
        );
        echo $sub;
    ?></p>

    <!-- ID de acesso -->
    <?php if ($cfg['mostrar_id'] && $colab_id): ?>
    <div class="id-badge">
        <div>
            <div class="id-label">Seu ID de acesso</div>
            <div class="id-valor"><?php echo htmlspecialchars($colab_id); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Aviso de e-mail -->
    <?php if ($cfg['mostrar_email']): ?>
    <div class="email-aviso">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
        </svg>
        <span>Quando o administrador liberar seu acesso, você receberá um <strong style="color:#E6EDF3;">e-mail de confirmação</strong> com as permissões concedidas.</span>
    </div>
    <?php endif; ?>

    <!-- Passos visuais -->
    <?php if ($cfg['mostrar_passos']): ?>
    <div class="passos">
        <div class="passo">
            <div class="passo-circulo passo-ok">✓</div>
            <div class="passo-texto">Conta<br>criada</div>
        </div>
        <div class="passo">
            <div class="passo-circulo passo-atual">2</div>
            <div class="passo-texto" style="color:#008CFF;">Aguardando<br>aprovação</div>
        </div>
        <div class="passo">
            <div class="passo-circulo passo-wait">3</div>
            <div class="passo-texto">Acesso<br>liberado</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Botões de ação -->
    <div class="acoes">
        <?php if ($motivo === 'sem_permissao'): ?>
            <!-- Sem permissão: botão de voltar para a página anterior -->
            <a href="<?php echo !empty($pagina_anterior) ? htmlspecialchars($pagina_anterior) : 'dashboard.php'; ?>"
               class="btn-voltar">
                ← Voltar
            </a>
            <a href="logout.php" class="btn-sair">Sair da conta</a>
        <?php else: ?>
            <a href="logout.php" class="btn-sair">Sair da conta</a>
            <?php if ($motivo !== 'bloqueado'): ?>
            <div class="refresh">
                Já foi aprovado? <a href="index.php">Fazer login novamente</a>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>