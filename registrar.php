<?php
// ============================================================
// ARQUIVO: registrar.php
// O QUE FAZ: Cadastro de novo colaborador
// ============================================================
session_start();

if (isset($_SESSION['usuario_logado']) || isset($_SESSION['colab_logado'])) {
    header("Location: dashboard.php"); exit();
}

require_once 'config/db.php';

$erro    = "";
$sucesso = false;
$id_gerado = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim($_POST['nome_completo']);
    // Remove tudo que não for dígito do CPF para validar
    $cpf   = preg_replace('/\D/', '', trim($_POST['cpf']));
    $email = trim($_POST['email']);
    $cargo = trim($_POST['cargo']);
    $senha = $_POST['senha'];
    $conf  = $_POST['confirmar_senha'];

    if (empty($nome) || empty($cpf) || empty($email) || empty($senha)) {
        $erro = "Preencha todos os campos obrigatórios.";
    } elseif (strlen($cpf) !== 11) {
        $erro = "CPF inválido. Digite os 11 dígitos.";
    } elseif ($senha !== $conf) {
        $erro = "As senhas não coincidem.";
    } elseif (strlen($senha) < 6) {
        $erro = "A senha deve ter pelo menos 6 caracteres.";
    } else {
        $cpf_e   = mysqli_real_escape_string($conexao, $cpf);
        $email_e = mysqli_real_escape_string($conexao, $email);

        $verifica = mysqli_query($conexao,
            "SELECT id FROM colaboradores WHERE cpf='$cpf_e' OR email='$email_e'"
        );
        if (mysqli_num_rows($verifica) > 0) {
            $erro = "CPF ou e-mail já cadastrado.";
        } else {
            // ── Gerar ID sequencial N-0001, N-0002, N-0003... ──
            // Busca o maior número já usado para incrementar
            $r_max = mysqli_query($conexao,
                "SELECT colaborador_id FROM colaboradores
                 ORDER BY id DESC
                 LIMIT 1"
            );
            if (mysqli_num_rows($r_max) > 0) {
                $ultimo = mysqli_fetch_assoc($r_max)['colaborador_id'];
                // Extrai só os dígitos do final: 'N-0003' → '0003' → 3
                $numero = (int)preg_replace('/\D/', '', $ultimo) + 1;
            } else {
                $numero = 1; // Primeiro colaborador
            }
            // str_pad formata com 4 dígitos com zeros à esquerda
            // Ex: 1 → '0001' | 12 → '0012' | 100 → '0100'
            $colab_id = 'N-' . str_pad($numero, 4, '0', STR_PAD_LEFT);

            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            $nome_e     = mysqli_real_escape_string($conexao, $nome);
            $cargo_e    = mysqli_real_escape_string($conexao, $cargo);
            $colab_id_e = mysqli_real_escape_string($conexao, $colab_id);

            $sql = "INSERT INTO colaboradores
                    (nome_completo, cpf, email, cargo, senha, colaborador_id, status)
                    VALUES ('$nome_e','$cpf_e','$email_e','$cargo_e','$senha_hash','$colab_id_e','pendente')";

            if (mysqli_query($conexao, $sql)) {
                $novo_id = mysqli_insert_id($conexao);
                mysqli_query($conexao,
                    "INSERT INTO permissoes (colaborador_id_fk) VALUES ($novo_id)"
                );
                $sucesso   = true;
                $id_gerado = $colab_id;

                // Salva na sessão para redirecionar para sem_permissao
                $_SESSION['colab_logado']   = true;
                $_SESSION['colab_id']       = $novo_id;
                $_SESSION['colab_id_str']   = $colab_id;
                $_SESSION['colab_nome']     = $nome;
                $_SESSION['colab_status']   = 'pendente';
                $_SESSION['permissoes']     = [];
            } else {
                $erro = "Erro ao cadastrar: " . mysqli_error($conexao);
            }
        }
    }
}

// Se cadastrou com sucesso, redireciona para a tela de aguardando
if ($sucesso) {
    header("Location: sem_permissao.php?motivo=recem_cadastrado");
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion Systems — Criar conta</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Manrope',sans-serif;background:#0D1117;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 0;}
        body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,140,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(0,140,255,0.03) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;}
        .wrap{width:100%;max-width:440px;padding:20px;}
        .logo{text-align:center;margin-bottom:28px;}
        .logo img{height:32px;width:auto;}
        .logo-fallback{display:inline-flex;flex-direction:column;align-items:flex-start;}
        .logo-fallback .n{font-size:26px;font-weight:800;color:#fff;letter-spacing:-0.5px;line-height:1;}
        .logo-fallback .s{font-size:9px;font-weight:700;color:#008CFF;letter-spacing:3px;text-transform:uppercase;margin-top:2px;}
        .card{background:#161B22;border:1px solid #21262D;border-radius:16px;padding:28px 24px;}
        .card h1{font-size:17px;font-weight:800;color:#fff;margin-bottom:4px;}
        .card p{font-size:13px;color:#6E7681;margin-bottom:20px;}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .form-group{margin-bottom:14px;}
        label{display:block;font-size:11px;font-weight:700;color:#6E7681;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:5px;}
        input{width:100%;height:40px;background:#0D1117;border:1px solid #30363D;border-radius:8px;padding:0 12px;font-size:13px;font-family:'Manrope',sans-serif;color:#E6EDF3;outline:none;transition:border-color 0.15s;}
        input::placeholder{color:#484F58;}
        input:focus{border-color:#008CFF;box-shadow:0 0 0 3px rgba(0,140,255,0.15);}

        /* Campo de senha com botão de ver */
        .senha-wrap{position:relative;}
        .senha-wrap input{padding-right:40px;}
        .btn-ver-senha{
            position:absolute;right:10px;top:50%;transform:translateY(-50%);
            background:none;border:none;cursor:pointer;padding:4px;
            color:#484F58;transition:color 0.15s;
        }
        .btn-ver-senha:hover{color:#008CFF;}
        .btn-ver-senha svg{width:16px;height:16px;stroke:currentColor;display:block;}

        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;padding:10px 12px;font-size:13px;color:#FCA5A5;margin-bottom:14px;}
        .btn{width:100%;height:42px;background:#008CFF;color:#fff;border:none;border-radius:8px;font-family:'Manrope',sans-serif;font-weight:700;font-size:14px;cursor:pointer;margin-top:4px;transition:background 0.15s;}
        .btn:hover{background:#0070CC;}
        .link{display:block;text-align:center;margin-top:16px;font-size:13px;color:#6E7681;}
        .link a{color:#008CFF;text-decoration:none;}
        .link a:hover{text-decoration:underline;}
        .req{color:#EF4444;}
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo">
        <img src="Logo1.svg" alt="Norion"
             onerror="this.style.display='none';document.getElementById('lf').style.display='inline-flex';">
        <div id="lf" class="logo-fallback" style="display:none;">
            <span class="n">Norion</span><span class="s">Systems</span>
        </div>
    </div>

    <div class="card">
        <h1>Criar conta de colaborador</h1>
        <p>Após o cadastro, aguarde a aprovação do administrador.</p>

        <?php if ($erro): ?>
            <div class="alert-error"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <form action="" method="post" id="form-registro">

            <div class="form-group">
                <label>Nome completo <span class="req">*</span></label>
                <input type="text" name="nome_completo" placeholder="Seu nome completo"
                    value="<?php echo isset($_POST['nome_completo'])?htmlspecialchars($_POST['nome_completo']):''; ?>"
                    autocomplete="name">
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <!-- CPF com máscara automática via JS -->
                    <label>CPF <span class="req">*</span></label>
                    <input type="text" name="cpf" id="campo-cpf"
                        placeholder="000.000.000-00" maxlength="14"
                        value="<?php echo isset($_POST['cpf'])?htmlspecialchars($_POST['cpf']):''; ?>"
                        autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Cargo</label>
                    <input type="text" name="cargo" placeholder="Ex: Vendedor"
                        value="<?php echo isset($_POST['cargo'])?htmlspecialchars($_POST['cargo']):''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label>E-mail <span class="req">*</span></label>
                <input type="email" name="email" placeholder="seu@email.com"
                    value="<?php echo isset($_POST['email'])?htmlspecialchars($_POST['email']):''; ?>"
                    autocomplete="email">
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Senha <span class="req">*</span></label>
                    <div class="senha-wrap">
                        <input type="password" name="senha" id="campo-senha"
                            placeholder="Mínimo 6 caracteres" autocomplete="new-password">
                        <button type="button" class="btn-ver-senha"
                            onclick="toggleSenha('campo-senha', 'icone-senha')"
                            title="Ver senha">
                            <svg id="icone-senha" viewBox="0 0 24 24" fill="none" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirmar senha <span class="req">*</span></label>
                    <div class="senha-wrap">
                        <input type="password" name="confirmar_senha" id="campo-conf"
                            placeholder="Repita a senha" autocomplete="new-password">
                        <button type="button" class="btn-ver-senha"
                            onclick="toggleSenha('campo-conf', 'icone-conf')"
                            title="Ver senha">
                            <svg id="icone-conf" viewBox="0 0 24 24" fill="none" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn">Criar conta</button>
        </form>
    </div>

    <div class="link">
        Já tem uma conta? <a href="index.php">Fazer login</a>
    </div>
</div>

<script>
// ── Máscara do CPF ──
// Aplica a formatação XXX.XXX.XXX-XX enquanto o usuário digita
document.getElementById('campo-cpf').addEventListener('input', function(e) {
    var v = e.target.value.replace(/\D/g, '');
    // Remove tudo que não for dígito
    // Aplica a máscara progressivamente
    if (v.length > 9) {
        v = v.substring(0, 11);
        v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
    } else if (v.length > 6) {
        v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
    } else if (v.length > 3) {
        v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
    }
    e.target.value = v;
});

// ── Ver/ocultar senha ──
// Alterna entre type="password" e type="text"
// e troca o ícone (olho aberto / olho fechado com risco)
function toggleSenha(campoId, iconeId) {
    var campo  = document.getElementById(campoId);
    var icone  = document.getElementById(iconeId);
    var visivel = campo.type === 'text';

    campo.type = visivel ? 'password' : 'text';

    // Ícone olho aberto = senha visível
    // Ícone olho com risco = senha oculta
    if (visivel) {
        // Volta para oculto — mostra olho normal
        icone.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    } else {
        // Senha visível — mostra olho com risco
        icone.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    }
}
</script>
</body>
</html>