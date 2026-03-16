<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) { header("Location: index.php"); exit(); }
require_once 'config/db.php';
$pagina_atual = 'kanban';

// AÇÃO: Atualizar status via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_status'])) {
    $lead_id     = (int)$_POST['lead_id'];
    $novo_status = mysqli_real_escape_string($conexao, $_POST['novo_status']);
    $valor_venda = str_replace(',', '.', trim($_POST['valor_venda'] ?? '0'));
    $valor_venda = (is_numeric($valor_venda) && $valor_venda > 0) ? (float)$valor_venda : 0;

    $status_validos = ['novo', 'em_contato', 'fechado', 'perdido'];
    if (!in_array($novo_status, $status_validos)) {
        http_response_code(400);
        echo json_encode(['erro' => 'Status inválido']);
        exit();
    }

    // Atualiza o status do lead
    mysqli_query($conexao, "UPDATE leads SET status='$novo_status' WHERE id=$lead_id");

    if ($novo_status === 'fechado' && $valor_venda > 0) {
        // Atualiza o valor no lead
        mysqli_query($conexao, "UPDATE leads SET valor=$valor_venda WHERE id=$lead_id");

        // Registra ou atualiza a venda no financeiro
        $v = mysqli_query($conexao, "SELECT id FROM vendas WHERE lead_id=$lead_id");
        if (mysqli_num_rows($v) === 0) {
            // Busca o nome do lead para a descrição
            $lr   = mysqli_query($conexao, "SELECT nome, tipo_proposta FROM leads WHERE id=$lead_id");
            $lead = mysqli_fetch_assoc($lr);
            $desc = mysqli_real_escape_string($conexao,
                ($lead['tipo_proposta'] ? $lead['tipo_proposta'] . ' — ' : 'Venda — ') . $lead['nome']
            );
            $hoje = date('Y-m-d');
            mysqli_query($conexao,
                "INSERT INTO vendas (lead_id,valor,descricao,data_venda)
                 VALUES ($lead_id,$valor_venda,'$desc','$hoje')"
            );
        } else {
            mysqli_query($conexao, "UPDATE vendas SET valor=$valor_venda WHERE lead_id=$lead_id");
        }
    } elseif ($novo_status !== 'fechado') {
        // Saiu de fechado — remove a venda
        mysqli_query($conexao, "DELETE FROM vendas WHERE lead_id=$lead_id");
        mysqli_query($conexao, "UPDATE leads SET valor=0 WHERE id=$lead_id");
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit();
}

// Buscando leads agrupados por status
$colunas = [
    'novo'       => ['titulo' => 'Novo',       'cor' => '#008CFF', 'leads' => []],
    'em_contato' => ['titulo' => 'Em contato', 'cor' => '#F59E0B', 'leads' => []],
    'fechado'    => ['titulo' => 'Fechado',     'cor' => '#10B981', 'leads' => []],
    'perdido'    => ['titulo' => 'Perdido',     'cor' => '#EF4444', 'leads' => []],
];

$r = mysqli_query($conexao,
    "SELECT id, nome, telefone, email, origem, etiqueta, valor,
            tipo_proposta, possivel_ganho, status, criado_em
     FROM leads ORDER BY criado_em DESC"
);
while ($l = mysqli_fetch_assoc($r)) {
    $s = $l['status'] ?? 'novo';
    if (isset($colunas[$s])) $colunas[$s]['leads'][] = $l;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Kanban</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .kanban-wrap { display:flex; gap:14px; overflow-x:auto; padding-bottom:16px; align-items:flex-start; }
        .kanban-coluna { flex-shrink:0; width:260px; display:flex; flex-direction:column; gap:8px; }
        .coluna-header { display:flex; align-items:center; gap:8px; padding:10px 12px; background:var(--surface); border-radius:var(--radius-lg); border:1px solid var(--border); position:sticky; top:0; }
        .coluna-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .coluna-titulo { font-size:13px; font-weight:700; color:var(--text-1); flex:1; }
        .coluna-count { font-size:11px; font-weight:600; color:var(--text-3); background:var(--surface-2); border:1px solid var(--border); border-radius:20px; padding:1px 7px; min-width:22px; text-align:center; }
        .coluna-corpo { min-height:120px; display:flex; flex-direction:column; gap:8px; padding:4px 0; border-radius:var(--radius-lg); transition:background 0.15s; }
        .coluna-corpo.drag-over { background:var(--azul-light); outline:2px dashed var(--azul-mid); outline-offset:2px; }
        .kanban-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:12px 14px; cursor:grab; transition:box-shadow 0.15s,transform 0.15s,opacity 0.15s; user-select:none; }
        .kanban-card:hover { border-color:var(--border-2); box-shadow:0 2px 8px rgba(0,0,0,0.08); }
        .kanban-card:active { cursor:grabbing; }
        .kanban-card.dragging { opacity:0.4; transform:scale(0.98); }
        .kanban-card-linha { height:3px; border-radius:2px; margin-bottom:10px; }
        .card-nome { font-size:13px; font-weight:700; color:var(--text-1); margin-bottom:4px; line-height:1.3; }

        /* Badge de tipo de proposta — destaque principal */
        .proposta-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 8px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text-2);
        }
        /* Cores por tipo */
        .prop-Site              { background:#EFF8FF; border-color:#B8DCFF; color:#0055B3; }
        .prop-Software-sob-medida { background:#EDE9FE; border-color:#C4B5FD; color:#4C1D95; }
        .prop-Fluxo-de-IA       { background:#D1FAE5; border-color:#6EE7B7; color:#065F46; }
        .prop-Agente-de-IA      { background:#FEF3C7; border-color:#FCD34D; color:#92400E; }
        .prop-Landing-Page      { background:#FEE2E2; border-color:#FCA5A5; color:#991B1B; }
        .prop-Outro             { background:var(--cinza-light); border-color:var(--border-2); color:var(--cinza-text); }

        .card-info { display:flex; flex-direction:column; gap:3px; margin-bottom:8px; }
        .card-info-linha { display:flex; align-items:center; gap:5px; font-size:11px; color:var(--text-3); }
        .card-info-linha svg { width:11px; height:11px; stroke:var(--text-3); flex-shrink:0; }
        .card-footer { display:flex; align-items:center; justify-content:space-between; margin-top:8px; padding-top:8px; border-top:1px solid var(--border); }
        .etiqueta-badge { display:inline-block; padding:2px 7px; border-radius:20px; font-size:10px; font-weight:700; letter-spacing:0.3px; text-transform:uppercase; }
        .etq-VIP{background:#FEF3C7;color:#92400E} .etq-Urgente{background:#FEE2E2;color:#991B1B}
        .etq-Retornar{background:#EDE9FE;color:#4C1D95} .etq-Proposta-enviada{background:#D1FAE5;color:#065F46}
        .etq-Aguardando{background:#F3F4F6;color:#374151} .etq-Frio{background:#E0F2FE;color:#0C4A6E}
        .coluna-vazia { text-align:center; padding:20px 10px; font-size:12px; color:var(--text-3); border:1px dashed var(--border); border-radius:var(--radius-lg); }
        .toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px); background:var(--text-1); color:var(--surface); padding:10px 20px; border-radius:var(--radius-lg); font-size:13px; font-weight:600; z-index:300; transition:transform 0.3s cubic-bezier(0.32,0.72,0,1); white-space:nowrap; }
        .toast.visivel { transform:translateX(-50%) translateY(0); }

        /* ── Modal de valor ao fechar ── */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:200; display:none; align-items:center; justify-content:center; }
        .modal-overlay.aberto { display:flex; }
        .modal-box { background:var(--surface); border-radius:var(--radius-lg); padding:28px; width:100%; max-width:380px; border:1px solid var(--border); box-shadow:0 8px 32px rgba(0,0,0,0.16); }
        .modal-titulo { font-size:16px; font-weight:800; color:var(--text-1); margin-bottom:4px; }
        .modal-sub { font-size:13px; color:var(--text-3); margin-bottom:20px; }
        .modal-acoes { display:flex; gap:10px; margin-top:20px; }
        .modal-acoes .btn { flex:1; justify-content:center; }

        /* Pill de possível ganho */
        .possivel-ganho-pill {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; font-weight: 600;
            color: var(--verde); background: var(--verde-light);
            padding: 2px 7px; border-radius: 20px;
        }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">Kanban de Leads</span>
        <div class="topbar-acoes">
            <a href="leads.php" class="btn btn-secondary btn-sm">Ver lista</a>
            <a href="leads_novo.php" class="btn btn-primary btn-sm">+ Novo lead</a>
        </div>
    </div>

    <div class="page-content">
        <div class="kanban-wrap" id="kanban">
            <?php foreach ($colunas as $status_key => $coluna): ?>
            <div class="kanban-coluna" id="col-<?php echo $status_key; ?>">
                <div class="coluna-header">
                    <div class="coluna-dot" style="background:<?php echo $coluna['cor']; ?>;"></div>
                    <span class="coluna-titulo"><?php echo $coluna['titulo']; ?></span>
                    <span class="coluna-count" id="count-<?php echo $status_key; ?>"><?php echo count($coluna['leads']); ?></span>
                </div>
                <div class="coluna-corpo" id="corpo-<?php echo $status_key; ?>" data-status="<?php echo $status_key; ?>">
                    <?php if (empty($coluna['leads'])): ?>
                        <div class="coluna-vazia">Nenhum lead aqui</div>
                    <?php endif; ?>
                    <?php foreach ($coluna['leads'] as $lead):
                        $prop_css = 'prop-' . str_replace(' ', '-', $lead['tipo_proposta'] ?? '');
                    ?>
                    <div class="kanban-card"
                         data-id="<?php echo $lead['id']; ?>"
                         data-possivel="<?php echo $lead['possivel_ganho'] ?? 0; ?>"
                         data-nome="<?php echo htmlspecialchars($lead['nome']); ?>"
                         draggable="true">
                        <div class="kanban-card-linha" style="background:<?php echo $coluna['cor']; ?>;"></div>
                        <div class="card-nome"><?php echo htmlspecialchars($lead['nome']); ?></div>

                        <!-- Tipo de proposta como badge principal -->
                        <?php if (!empty($lead['tipo_proposta'])): ?>
                            <div><span class="proposta-tag <?php echo $prop_css; ?>">
                                <?php echo htmlspecialchars($lead['tipo_proposta']); ?>
                            </span></div>
                        <?php endif; ?>

                        <div class="card-info">
                            <?php if ($lead['telefone']): ?>
                            <div class="card-info-linha">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91A16 16 0 0 0 13 14.85l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16.92z"/></svg>
                                <?php echo htmlspecialchars($lead['telefone']); ?>
                            </div>
                            <?php endif; ?>

                            <?php if (($lead['possivel_ganho'] ?? 0) > 0): ?>
                            <div class="card-info-linha">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/></svg>
                                <span>Possível: R$ <?php echo number_format($lead['possivel_ganho'],2,',','.'); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($lead['valor'] > 0): ?>
                            <div class="card-info-linha" style="color:var(--verde);font-weight:600;">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" style="stroke:var(--verde);"><polyline points="20 6 9 17 4 12"/></svg>
                                Fechado: R$ <?php echo number_format($lead['valor'],2,',','.'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-footer">
                            <div>
                                <?php if (!empty($lead['etiqueta'])):
                                    $etq_css = 'etq-'.str_replace(' ','-',$lead['etiqueta']); ?>
                                    <span class="etiqueta-badge <?php echo $etq_css; ?>"><?php echo htmlspecialchars($lead['etiqueta']); ?></span>
                                <?php else: ?>
                                    <span style="font-size:11px;color:var(--text-3);"><?php echo date('d/m', strtotime($lead['criado_em'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="leads_editar.php?id=<?php echo $lead['id']; ?>" class="btn btn-secondary btn-sm" style="font-size:11px;padding:3px 8px;" onclick="event.stopPropagation()">Editar</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<!-- ============================================================
     MODAL: Registrar valor ao mover para Fechado
     ============================================================ -->
<div class="modal-overlay" id="modal-fechado">
    <div class="modal-box">
        <div class="modal-titulo">🎉 Lead fechado!</div>
        <div class="modal-sub" id="modal-fechado-sub">Qual foi o valor acordado com o cliente?</div>

        <!-- Sugestão do possível ganho -->
        <div id="sugestao-wrap" style="display:none;margin-bottom:14px;">
            <div style="font-size:11px;color:var(--text-3);margin-bottom:6px;">Possível ganho estimado:</div>
            <button type="button" id="btn-usar-sugestao" class="btn btn-secondary btn-sm"
                onclick="usarSugestao()" style="font-size:12px;">
                Usar R$ <span id="sugestao-valor"></span>
            </button>
        </div>

        <div class="form-group">
            <label class="form-label">Valor acordado <span class="req">*</span></label>
            <div class="input-group">
                <span class="input-prefix">R$</span>
                <input class="form-control" type="number" id="modal-valor"
                    placeholder="0.00" step="0.01" min="0">
            </div>
        </div>

        <div class="modal-acoes">
            <button class="btn btn-secondary" onclick="cancelarFechamento()">Cancelar</button>
            <button class="btn btn-primary" onclick="confirmarFechamento()">💰 Registrar venda</button>
        </div>
    </div>
</div>

<script>
var cardArrastando   = null;
var dadosFechamento  = null; // guarda os dados do arraste para Fechado

// ── Inicializa eventos nos cards ──
function inicializarCards() {
    document.querySelectorAll('.kanban-card').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            cardArrastando = card;
            card.classList.add('dragging');
            e.dataTransfer.setData('text/plain', card.dataset.id);
            e.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', function() {
            card.classList.remove('dragging');
            document.querySelectorAll('.coluna-corpo').forEach(function(c) { c.classList.remove('drag-over'); });
            cardArrastando = null;
        });
    });
}

// ── Eventos nas colunas ──
document.querySelectorAll('.coluna-corpo').forEach(function(coluna) {
    coluna.addEventListener('dragover', function(e) {
        e.preventDefault();
        coluna.classList.add('drag-over');
        e.dataTransfer.dropEffect = 'move';
    });
    coluna.addEventListener('dragleave', function(e) {
        if (!coluna.contains(e.relatedTarget)) coluna.classList.remove('drag-over');
    });
    coluna.addEventListener('drop', function(e) {
        e.preventDefault();
        coluna.classList.remove('drag-over');
        if (!cardArrastando) return;

        var novoStatus  = coluna.dataset.status;
        var colunaAtual = cardArrastando.closest('.coluna-corpo');
        var statusAtual = colunaAtual ? colunaAtual.dataset.status : null;

        if (statusAtual === novoStatus) return;

        if (novoStatus === 'fechado') {
            // Não move visualmente ainda — espera o modal confirmar
            // Guarda os dados para usar após confirmação
            var possivel = parseFloat(cardArrastando.dataset.possivel) || 0;
            dadosFechamento = {
                card:        cardArrastando,
                colunaDestino: coluna,
                colunaOrigem:  colunaAtual,
                leadId:      cardArrastando.dataset.id,
                leadNome:    cardArrastando.dataset.nome,
                possivel:    possivel,
            };
            abrirModalFechado(dadosFechamento);
        } else {
            // Para outras colunas, move normalmente
            moverCard(cardArrastando, coluna, colunaAtual, novoStatus, 0);
        }
    });
});

// Abre o modal de valor ao mover para Fechado
function abrirModalFechado(dados) {
    document.getElementById('modal-fechado-sub').textContent =
        'Qual foi o valor acordado com ' + dados.leadNome + '?';

    // Se tem possível ganho, sugere como atalho
    if (dados.possivel > 0) {
        var fmt = dados.possivel.toLocaleString('pt-BR', {minimumFractionDigits:2});
        document.getElementById('sugestao-valor').textContent = fmt;
        document.getElementById('sugestao-wrap').style.display = 'block';
        // Preenche o campo com o possível ganho como sugestão
        document.getElementById('modal-valor').value = dados.possivel;
    } else {
        document.getElementById('sugestao-wrap').style.display = 'none';
        document.getElementById('modal-valor').value = '';
    }

    document.getElementById('modal-fechado').classList.add('aberto');
    // Foca no campo de valor para facilitar a digitação
    setTimeout(function() {
        document.getElementById('modal-valor').focus();
        document.getElementById('modal-valor').select();
    }, 100);
}

// Usa o possível ganho como valor
function usarSugestao() {
    if (dadosFechamento) {
        document.getElementById('modal-valor').value = dadosFechamento.possivel;
        document.getElementById('modal-valor').focus();
    }
}

// Confirma o fechamento com o valor digitado
function confirmarFechamento() {
    var valor = parseFloat(document.getElementById('modal-valor').value) || 0;
    if (valor <= 0) {
        document.getElementById('modal-valor').focus();
        mostrarToast('Digite o valor acordado');
        return;
    }
    document.getElementById('modal-fechado').classList.remove('aberto');
    moverCard(
        dadosFechamento.card,
        dadosFechamento.colunaDestino,
        dadosFechamento.colunaOrigem,
        'fechado',
        valor
    );
    dadosFechamento = null;
}

// Cancela — não move o card
function cancelarFechamento() {
    document.getElementById('modal-fechado').classList.remove('aberto');
    dadosFechamento = null;
}

// Move o card visualmente e salva no banco
function moverCard(card, colunaDestino, colunaOrigem, novoStatus, valorVenda) {
    var statusOrigem = colunaOrigem ? colunaOrigem.dataset.status : null;

    // Remove "Nenhum lead aqui" da destino
    var vazia = colunaDestino.querySelector('.coluna-vazia');
    if (vazia) vazia.remove();

    // Move o card
    colunaDestino.appendChild(card);

    // Atualiza a linha colorida do card
    var cor = document.querySelector('#col-' + novoStatus + ' .coluna-dot').style.background;
    card.querySelector('.kanban-card-linha').style.background = cor;

    // Atualiza contadores
    if (statusOrigem) atualizarContador(statusOrigem, -1);
    atualizarContador(novoStatus, +1);

    // Verifica se a origem ficou vazia
    if (colunaOrigem && colunaOrigem.querySelectorAll('.kanban-card').length === 0) {
        var div = document.createElement('div');
        div.className = 'coluna-vazia';
        div.textContent = 'Nenhum lead aqui';
        colunaOrigem.appendChild(div);
    }

    // Atualiza os dados visuais do card se fechado
    if (novoStatus === 'fechado' && valorVenda > 0) {
        // Atualiza o data-attribute para futuras referências
        card.dataset.possivel = '0';
        // Adiciona/atualiza a linha de valor no card
        var infoDiv = card.querySelector('.card-info');
        // Remove linha de valor existente se houver
        var linhaValorAntiga = card.querySelector('.linha-valor-fechado');
        if (linhaValorAntiga) linhaValorAntiga.remove();

        var linhaValor = document.createElement('div');
        linhaValor.className = 'card-info-linha linha-valor-fechado';
        linhaValor.style.cssText = 'color:var(--verde);font-weight:600;';
        var fmt = valorVenda.toLocaleString('pt-BR', {minimumFractionDigits:2});
        linhaValor.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" style="stroke:var(--verde);width:11px;height:11px;"><polyline points="20 6 9 17 4 12"/></svg> Fechado: R$ ' + fmt;
        infoDiv.appendChild(linhaValor);
    }

    // Salva no banco
    salvarStatus(card.dataset.id, novoStatus, valorVenda);
}

function atualizarContador(status, delta) {
    var el = document.getElementById('count-' + status);
    if (!el) return;
    el.textContent = Math.max(0, (parseInt(el.textContent)||0) + delta);
}

function salvarStatus(leadId, novoStatus, valorVenda) {
    fetch('kanban.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'atualizar_status=1&lead_id=' + leadId +
              '&novo_status=' + novoStatus +
              '&valor_venda=' + (valorVenda || 0)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        mostrarToast(data.ok ? '✓ Salvo!' : 'Erro ao salvar');
    })
    .catch(function() { mostrarToast('Erro de conexão'); });
}

var toastTimer = null;
function mostrarToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('visivel');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function() { t.classList.remove('visivel'); }, 2500);
}

// Fecha modal ao clicar fora
document.getElementById('modal-fechado').addEventListener('click', function(e) {
    if (e.target === this) cancelarFechamento();
});

// Confirma com Enter no campo de valor
document.getElementById('modal-valor').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') confirmarFechamento();
});

inicializarCards();
</script>

</body>
</html>