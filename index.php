<?php
session_start();
// Se já logado, redireciona
if (isset($_SESSION['usuario_logado'])) { header("Location: dashboard.php"); exit(); }
if (isset($_SESSION['colab_logado']))   { header("Location: dashboard.php"); exit(); }

$erro = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/db.php';

    $usuario_id = trim($_POST['usuario_id']);
    $senha      = $_POST['senha'];

    if (empty($usuario_id) || empty($senha)) {
        $erro = "Preencha o ID e a senha.";
    } else {

        // ── Tentativa 1: login como admin ──
        $uid_e = mysqli_real_escape_string($conexao, $usuario_id);
        $r = mysqli_query($conexao,
            "SELECT id, usuario_id, senha, nome FROM usuarios WHERE usuario_id = '$uid_e'"
        );
        if (mysqli_num_rows($r) === 1) {
            $u = mysqli_fetch_assoc($r);
            if (password_verify($senha, $u['senha'])) {
                $_SESSION['usuario_logado'] = true;
                $_SESSION['usuario_nome']   = $u['nome'];
                header("Location: dashboard.php");
                exit();
            } else {
                $erro = "ID ou senha incorretos.";
            }
        } else {
            // ── Tentativa 2: login como colaborador ──
            // O colaborador usa colaborador_id (ex: N-910) + senha
            $r2 = mysqli_query($conexao,
                "SELECT c.*, p.ver_dashboard, p.ver_leads, p.ver_financeiro,
                        p.editar_leads, p.editar_financeiro, p.gerenciar_colab
                 FROM colaboradores c
                 LEFT JOIN permissoes p ON p.colaborador_id_fk = c.id
                 WHERE c.colaborador_id = '$uid_e'"
            );

            if (mysqli_num_rows($r2) === 1) {
                $colab = mysqli_fetch_assoc($r2);

                if (password_verify($senha, $colab['senha'])) {

                    if ($colab['status'] === 'bloqueado') {
                        $erro = "Sua conta está bloqueada. Entre em contato com o administrador.";
                    } else {
                        // Login bem-sucedido — salva dados na sessão
                        $_SESSION['colab_logado']   = true;
                        $_SESSION['colab_id']       = $colab['id'];
                        $_SESSION['colab_id_str']   = $colab['colaborador_id'];
                        $_SESSION['colab_nome']     = $colab['nome_completo'];
                        $_SESSION['colab_status']   = $colab['status'];

                        // Salva as permissões na sessão para acesso rápido
                        // Sem precisar consultar o banco em cada página
                        $_SESSION['permissoes'] = [
                            'ver_dashboard'     => (int)$colab['ver_dashboard'],
                            'ver_leads'         => (int)$colab['ver_leads'],
                            'ver_financeiro'    => (int)$colab['ver_financeiro'],
                            'editar_leads'      => (int)$colab['editar_leads'],
                            'editar_financeiro' => (int)$colab['editar_financeiro'],
                            'gerenciar_colab'   => (int)$colab['gerenciar_colab'],
                        ];

                        // Se ainda pendente, vai para a tela de espera
                        if ($colab['status'] === 'pendente') {
                            header("Location: sem_permissao.php?motivo=pendente");
                        } else {
                            // Redireciona para a primeira página que tem acesso
                            if ($colab['ver_dashboard'])  { header("Location: dashboard.php"); }
                            elseif ($colab['ver_leads'])  { header("Location: leads.php"); }
                            elseif ($colab['ver_financeiro']) { header("Location: financeiro.php"); }
                            else { header("Location: sem_permissao.php"); }
                        }
                        exit();
                    }
                } else {
                    $erro = "ID ou senha incorretos.";
                }
            } else {
                $erro = "ID ou senha incorretos.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion Systems — Acesso</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Manrope',sans-serif;background:#0D1117;min-height:100vh;display:flex;align-items:center;justify-content:center;}
        body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,140,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(0,140,255,0.03) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;}
        .login-wrap{width:100%;max-width:400px;padding:20px;}
        .login-logo{text-align:center;margin-bottom:32px;}
        .login-logo img{height:36px;width:auto;}
        .login-logo-text{display:inline-flex;flex-direction:column;align-items:flex-start;}
        .login-logo-text .nome{font-size:28px;font-weight:800;color:#fff;letter-spacing:-0.5px;line-height:1;}
        .login-logo-text .sub{font-size:10px;font-weight:700;color:#008CFF;letter-spacing:3px;text-transform:uppercase;margin-top:2px;}
        .login-card{background:#161B22;border:1px solid #21262D;border-radius:16px;padding:32px 28px;}
        .login-card h1{font-size:18px;font-weight:800;color:#fff;margin-bottom:4px;}
        .login-card p{font-size:13px;color:#6E7681;margin-bottom:24px;}
        .form-group{margin-bottom:14px;}
        label{display:block;font-size:11px;font-weight:700;color:#6E7681;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:6px;}
        input{width:100%;height:42px;background:#0D1117;border:1px solid #30363D;border-radius:8px;padding:0 14px;font-size:14px;font-family:'Manrope',sans-serif;color:#E6EDF3;outline:none;transition:border-color 0.15s,box-shadow 0.15s;}
        input::placeholder{color:#484F58;}
        input:focus{border-color:#008CFF;box-shadow:0 0 0 3px rgba(0,140,255,0.15);}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;padding:10px 14px;font-size:13px;color:#FCA5A5;margin-bottom:14px;}
        .btn-login{width:100%;height:44px;background:#008CFF;color:#fff;border:none;border-radius:8px;font-family:'Manrope',sans-serif;font-weight:700;font-size:14px;cursor:pointer;margin-top:6px;transition:background 0.15s;}
        .btn-login:hover{background:#0070CC;}
        .divisor{display:flex;align-items:center;gap:10px;margin:18px 0;color:#484F58;font-size:12px;}
        .divisor::before,.divisor::after{content:'';flex:1;height:1px;background:#21262D;}
        .btn-registrar{display:block;width:100%;height:42px;border:1px solid #30363D;border-radius:8px;background:none;color:#6E7681;font-family:'Manrope',sans-serif;font-size:13px;font-weight:600;cursor:pointer;text-align:center;line-height:42px;text-decoration:none;transition:all 0.15s;}
        .btn-registrar:hover{border-color:#6E7681;color:#E6EDF3;}
        .login-hint{margin-top:20px;background:#0D1117;border:1px solid #21262D;border-radius:10px;padding:12px 14px;}
        .hint-title{font-size:10px;font-weight:700;color:#484F58;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:8px;}
        .hint-row{display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;}
        .hint-key{color:#6E7681;}
        .hint-val{color:#008CFF;font-family:monospace;font-weight:600;}
    </style>
</head>
<body>
<div class="login-wrap">

    <div class="login-logo">
        <img src="Logo1.svg" alt="Norion Systems"
             onerror="this.style.display='none';document.getElementById('lf').style.display='inline-flex';">
        <div id="lf" class="login-logo-text" style="display:none;">
            <span class="nome">Norion</span><span class="sub">Systems</span>
        </div>
    </div>

    <div class="login-card">
        <h1>Bem-vindo de volta</h1>
        <p>Acesse o painel interno da Norion</p>

        <?php if ($erro): ?>
            <div class="alert-error"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <form action="" method="post">
            <div class="form-group">
                <label>ID do usuário</label>
                <input type="text" name="usuario_id" placeholder="Ex: norion01 ou N-910"
                    value="<?php echo isset($_POST['usuario_id'])?htmlspecialchars($_POST['usuario_id']):''; ?>">
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" placeholder="••••••••">
            </div>
            <button type="submit" class="btn-login">Entrar no painel</button>
        </form>

        <div class="divisor">ou</div>

        <!-- Link para cadastro de colaborador -->
        <a href="registrar.php" class="btn-registrar">Criar conta de colaborador</a>
    </div>

    <div class="login-hint">
        <div class="hint-title">Credenciais de administrador</div>
        <div class="hint-row"><span class="hint-key">ID</span><span class="hint-val">norion01</span></div>
        <div class="hint-row"><span class="hint-key">Senha</span><span class="hint-val">norion@2025</span></div>
    </div>

</div>
</body>
</html>