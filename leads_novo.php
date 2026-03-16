<?php
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_permissao('editar_leads');
$pagina_atual = 'leads';

$mensagem = ""; $tipo = ""; $fase = 1; $novo_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') { unset($_SESSION['lead_novo_id']); }

if (!empty($_SESSION['lead_novo_id'])) {
    $fase = 2; $novo_id = (int)$_SESSION['lead_novo_id'];
}

// AÇÃO 1: Salvar lead
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_lead'])) {
    $nome          = trim($_POST['nome']);
    $telefone      = mysqli_real_escape_string($conexao, trim($_POST['telefone']));
    $email         = mysqli_real_escape_string($conexao, trim($_POST['email']));
    $origem        = $_POST['origem'];
    $status        = $_POST['status'];
    $tipo_proposta = mysqli_real_escape_string($conexao, $_POST['tipo_proposta']);
    $observacoes   = mysqli_real_escape_string($conexao, trim($_POST['observacoes']));
    $etiqueta      = mysqli_real_escape_string($conexao, $_POST['etiqueta'] ?? '');
    $possivel_raw  = str_replace(',', '.', trim($_POST['possivel_ganho'] ?? '0'));
    $possivel_ganho = (is_numeric($possivel_raw) && $possivel_raw > 0) ? (float)$possivel_raw : 0;

    if (empty($nome)) {
        $mensagem = "O nome é obrigatório."; $tipo = "error"; $fase = 1;
    } else {
        $nome_e = mysqli_real_escape_string($conexao, $nome);
        $sql = "INSERT INTO leads (nome, telefone, email, origem, status, tipo_proposta, possivel_ganho, etiqueta, observacoes)
                VALUES ('$nome_e','$telefone','$email','$origem','$status','$tipo_proposta',$possivel_ganho,'$etiqueta','$observacoes')";
        if (mysqli_query($conexao, $sql)) {
            $novo_id = mysqli_insert_id($conexao);
            $_SESSION['lead_novo_id'] = $novo_id;
            $mensagem = "Lead cadastrado! Registre o primeiro contato ou clique em Concluir.";
            $tipo = "success"; $fase = 2;
        } else {
            $mensagem = "Erro: " . mysqli_error($conexao); $tipo = "error"; $fase = 1;
        }
    }
}

// AÇÃO 2: Salvar histórico
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_historico'])) {
    $hid       = (int)$_POST['lead_id_historico'];
    $tipo_c    = mysqli_real_escape_string($conexao, $_POST['tipo_contato'] ?? '');
    $anotacao  = mysqli_real_escape_string($conexao, trim($_POST['anotacao'] ?? ''));
    $proximo   = mysqli_real_escape_string($conexao, trim($_POST['proximo'] ?? ''));
    $data_hora = $_POST['data_hora'] ?? '';
    if ($hid > 0 && !empty($tipo_c) && !empty($data_hora)) {
        mysqli_query($conexao, "INSERT INTO historico_contatos (lead_id,tipo,anotacao,proximo,data_hora) VALUES ($hid,'$tipo_c','$anotacao','$proximo','$data_hora')");
    }
    $_SESSION['flash_msg']  = "Lead cadastrado e contato registrado!";
    $_SESSION['flash_tipo'] = "success";
    unset($_SESSION['lead_novo_id']);
    header("Location: leads.php"); exit();
}

// AÇÃO 3: Concluir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['concluir'])) {
    $_SESSION['flash_msg']  = "Lead cadastrado com sucesso!";
    $_SESSION['flash_tipo'] = "success";
    unset($_SESSION['lead_novo_id']);
    header("Location: leads.php"); exit();
}

$tipos_proposta = ['Site', 'Software sob medida', 'Fluxo de IA', 'Agente de IA', 'Landing Page', 'Outro'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Novo Lead</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .page-wrap { max-width: 700px; }
        .secao-titulo { font-size:13px; font-weight:700; color:var(--text-1); margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid var(--border); }
        .fase-2 { animation: slideDown 0.25s ease; }
        @keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
        .steps { display:flex; align-items:center; gap:10px; margin-bottom:20px; }
        .step { display:flex; align-items:center; gap:6px; }
        .step-circle { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; }
        .step-done  { background:var(--verde); }
        .step-atual { background:var(--azul); }
        .step-wait  { background:var(--surface-2); border:1px solid var(--border-2); }
        .step-line  { flex:1; max-width:40px; height:1px; background:var(--border); }

        /* Grid de tipos de proposta — botões visuais */
        .proposta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 6px;
        }
        .proposta-btn {
            padding: 10px 8px;
            border: 1px solid var(--border-2);
            border-radius: var(--radius);
            background: var(--surface);
            font-family: 'Manrope', sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            cursor: pointer;
            text-align: center;
            transition: all 0.15s;
        }
        .proposta-btn:hover { border-color: var(--azul); color: var(--azul); background: var(--azul-light); }
        .proposta-btn.selecionado { border-color: var(--azul); background: var(--azul); color: white; }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>
<div class="main-content">
    <div class="topbar"><span class="topbar-titulo">Novo lead</span></div>
    <div class="page-content page-wrap">
        <a href="leads.php" class="back-link" style="display:inline-flex;margin-bottom:16px;">← Voltar para Leads</a>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo==='error'?'error':'success'; ?>" style="margin-bottom:16px;">
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <?php if ($fase === 1): ?>

        <!-- Indicador de progresso fase 1 -->
        <div class="steps" style="margin-bottom:16px;">
            <div class="step">
                <div class="step-circle step-atual"><span style="color:white;">1</span></div>
                <span style="font-size:12px;font-weight:600;color:var(--azul);">Dados do lead</span>
            </div>
            <div class="step-line"></div>
            <div class="step">
                <div class="step-circle step-wait"><span style="color:var(--text-3);">2</span></div>
                <span style="font-size:12px;font-weight:600;color:var(--text-3);">Primeiro contato</span>
            </div>
        </div>

        <div class="card">
            <form action="" method="post">

                <div class="form-group">
                    <label class="form-label">Nome <span class="req">*</span></label>
                    <input class="form-control" type="text" name="nome" placeholder="Nome do lead"
                        value="<?php echo isset($_POST['nome'])?htmlspecialchars($_POST['nome']):''; ?>">
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Telefone</label>
                        <input class="form-control" type="tel" name="telefone" placeholder="(11) 99999-9999"
                            value="<?php echo isset($_POST['telefone'])?htmlspecialchars($_POST['telefone']):''; ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" placeholder="email@empresa.com"
                            value="<?php echo isset($_POST['email'])?htmlspecialchars($_POST['email']):''; ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Origem</label>
                        <select class="form-control" name="origem">
                            <option value="">Selecione...</option>
                            <?php foreach(['Instagram','Indicação','Site','WhatsApp','Google','Outro'] as $o):
                                $s=(isset($_POST['origem'])&&$_POST['origem']===$o)?'selected':''; ?>
                                <option value="<?php echo $o; ?>" <?php echo $s; ?>><?php echo $o; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status">
                            <?php foreach(['novo'=>'Novo','em_contato'=>'Em contato','fechado'=>'Fechado','perdido'=>'Perdido'] as $v=>$r):
                                $s=(isset($_POST['status'])&&$_POST['status']===$v)?'selected':($v==='novo'?'selected':''); ?>
                                <option value="<?php echo $v; ?>" <?php echo $s; ?>><?php echo $r; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Tipo de proposta — botões visuais -->
                <div class="form-group">
                    <label class="form-label">Tipo de proposta</label>
                    <!-- Input hidden guarda o valor selecionado -->
                    <input type="hidden" name="tipo_proposta" id="tipo_proposta_input"
                        value="<?php echo isset($_POST['tipo_proposta'])?htmlspecialchars($_POST['tipo_proposta']):''; ?>">
                    <div class="proposta-grid">
                        <?php foreach($tipos_proposta as $tp):
                            $sel = (isset($_POST['tipo_proposta']) && $_POST['tipo_proposta']===$tp) ? 'selecionado' : ''; ?>
                            <button type="button" class="proposta-btn <?php echo $sel; ?>"
                                onclick="selecionarProposta(this, '<?php echo $tp; ?>')">
                                <?php echo $tp; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Possível ganho -->
                <div class="form-group">
                    <label class="form-label">Possível ganho</label>
                    <div class="input-group">
                        <span class="input-prefix">R$</span>
                        <input class="form-control" type="number" name="possivel_ganho"
                            placeholder="Valor estimado da proposta"
                            step="0.01" min="0"
                            value="<?php echo isset($_POST['possivel_ganho'])&&$_POST['possivel_ganho']>0?$_POST['possivel_ganho']:''; ?>">
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:4px;">
                        Valor que você pretende cobrar ou tem em mente
                    </div>
                </div>

                <!-- Etiqueta -->
                <div class="form-group">
                    <label class="form-label">Etiqueta</label>
                    <!-- Input hidden guarda o valor — igual ao tipo de proposta -->
                    <input type="hidden" name="etiqueta" id="etiqueta_input"
                        value="<?php echo isset($_POST['etiqueta'])?htmlspecialchars($_POST['etiqueta']):''; ?>">
                    <div class="proposta-grid">
                        <?php
                        $etiquetas = ['VIP','Urgente','Retornar','Proposta enviada','Aguardando','Frio'];
                        foreach($etiquetas as $et):
                            $sel = (isset($_POST['etiqueta']) && $_POST['etiqueta']===$et) ? 'selecionado' : '';
                        ?>
                            <button type="button"
                                class="proposta-btn <?php echo $sel; ?>"
                                onclick="selecionarEtiqueta(this, '<?php echo $et; ?>')">
                                <?php echo $et; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Botão para limpar a etiqueta selecionada -->
                    <button type="button" id="btn-limpar-etiqueta"
                        onclick="limparEtiqueta()"
                        style="display:<?php echo (!empty($_POST['etiqueta']))?'inline-flex':'none'; ?>;margin-top:6px;font-size:11px;color:var(--text-3);background:none;border:none;cursor:pointer;gap:4px;align-items:center;">
                        × Limpar etiqueta
                    </button>
                </div>

                <div class="form-group">
                    <label class="form-label">Observações</label>
                    <textarea class="form-control" name="observacoes"
                        placeholder="Anotações sobre este lead..."><?php echo isset($_POST['observacoes'])?htmlspecialchars($_POST['observacoes']):''; ?></textarea>
                </div>

                <div class="form-footer">
                    <div class="form-footer-left">
                        <button type="submit" name="salvar_lead" class="btn btn-primary">Salvar e continuar →</button>
                        <a href="leads.php" class="btn btn-secondary">Cancelar</a>
                    </div>
                </div>
            </form>
        </div>

        <?php else: ?>

        <!-- Fase 2: histórico -->
        <div class="fase-2">
            <div class="steps">
                <div class="step">
                    <div class="step-circle step-done">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span style="font-size:12px;font-weight:600;color:var(--verde);">Lead cadastrado</span>
                </div>
                <div class="step-line"></div>
                <div class="step">
                    <div class="step-circle step-atual"><span style="color:white;">2</span></div>
                    <span style="font-size:12px;font-weight:600;color:var(--azul);">Primeiro contato</span>
                </div>
            </div>
            <div class="card">
                <div class="secao-titulo">Registrar primeiro contato <span style="font-size:11px;font-weight:400;color:var(--text-3);">— opcional</span></div>
                <form action="" method="post" id="form-historico">
                    <input type="hidden" name="lead_id_historico" value="<?php echo $novo_id; ?>">
                    <input type="hidden" name="salvar_historico" value="1">
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Tipo de contato</label>
                            <select class="form-control" name="tipo_contato">
                                <option value="">Selecione...</option>
                                <option value="ligacao">📞 Ligação</option>
                                <option value="whatsapp">💬 WhatsApp</option>
                                <option value="email">✉️ Email</option>
                                <option value="reuniao">🤝 Reunião</option>
                                <option value="outro">💡 Outro</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Data e hora</label>
                            <input class="form-control" type="datetime-local" name="data_hora" value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Anotação</label>
                        <textarea class="form-control" name="anotacao" rows="2" placeholder="O que foi dito ou combinado..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Próximo passo</label>
                        <input class="form-control" type="text" name="proximo" placeholder="Ex: Ligar na sexta, Enviar proposta...">
                    </div>
                    <div class="form-footer">
                        <div class="form-footer-left">
                            <button type="submit" class="btn btn-primary">Salvar contato e concluir</button>
                        </div>
                        <button type="submit" form="form-concluir" class="btn btn-secondary">Pular e concluir</button>
                    </div>
                </form>
                <form id="form-concluir" action="" method="post" style="display:none;">
                    <input type="hidden" name="concluir" value="1">
                </form>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<script>
function selecionarProposta(btn, valor) {
    document.querySelectorAll('.proposta-btn').forEach(function(b) {
        // Só remove de botões do grupo de proposta (não etiqueta)
        if (!b.closest('#grupo-etiqueta')) b.classList.remove('selecionado');
    });
    btn.classList.add('selecionado');
    document.getElementById('tipo_proposta_input').value = valor;
}

function selecionarEtiqueta(btn, valor) {
    // Remove seleção dos botões de etiqueta
    btn.closest('.proposta-grid').querySelectorAll('.proposta-btn').forEach(function(b) {
        b.classList.remove('selecionado');
    });
    btn.classList.add('selecionado');
    document.getElementById('etiqueta_input').value = valor;
    document.getElementById('btn-limpar-etiqueta').style.display = 'inline-flex';
}

function limparEtiqueta() {
    document.querySelectorAll('#etiqueta_input').forEach(function(){});
    document.getElementById('etiqueta_input').value = '';
    // Remove seleção visual — percorre os botões do segundo grid
    var grids = document.querySelectorAll('.proposta-grid');
    if (grids[1]) grids[1].querySelectorAll('.proposta-btn').forEach(function(b){ b.classList.remove('selecionado'); });
    document.getElementById('btn-limpar-etiqueta').style.display = 'none';
}
</script>
</body>
</html>