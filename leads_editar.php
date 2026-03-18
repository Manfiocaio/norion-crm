<?php
// ============================================================
// ARQUIVO: leads_editar.php
// O QUE FAZ: Edita lead + histórico de contatos + documentos
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
require_once 'config/log.php';
// Ver leads é necessário para abrir; editar leads para salvar alterações
requer_permissao('ver_leads');
$pode_editar_leads = tem_permissao('editar_leads');
$pagina_atual = 'leads';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header("Location: leads.php"); exit(); }
$id = (int)$_GET['id'];

$resultado = mysqli_query($conexao, "SELECT * FROM leads WHERE id = $id LIMIT 1");
if (mysqli_num_rows($resultado) === 0) { header("Location: leads.php"); exit(); }
$lead = mysqli_fetch_assoc($resultado);
$lead['valor'] = $lead['valor'] ?? 0;

$mensagem = ""; $tipo = "";

// ============================================================
// AÇÃO: Upload de documento
// ============================================================
// $_FILES é o array especial do PHP que contém os arquivos enviados
// via formulário com enctype="multipart/form-data"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_doc'])) {
    if (!$pode_editar_leads) { $mensagem = "Sem permissão para adicionar documentos."; $tipo = "error"; }
    elseif (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        $mensagem = "Erro no envio do arquivo. Tente novamente.";
        $tipo = "error";
    } else {

        $arquivo = $_FILES['arquivo'];
        // $_FILES['arquivo'] contém:
        // ['name']     = nome original do arquivo
        // ['size']     = tamanho em bytes
        // ['tmp_name'] = caminho temporário onde o PHP salvou
        // ['type']     = tipo MIME (ex: application/pdf)
        // ['error']    = código de erro (0 = sem erro)

        $nome_original = $arquivo['name'];
        $tamanho       = $arquivo['size'];
        $tipo_mime     = $arquivo['type'];
        $tmp           = $arquivo['tmp_name'];

        // Limite de 10MB (10 * 1024 * 1024 bytes)
        $limite = 10 * 1024 * 1024;
        if ($tamanho > $limite) {
            $mensagem = "Arquivo muito grande. Limite: 10MB.";
            $tipo = "error";
        } else {
            // Extensões permitidas
            $extensao = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
            // pathinfo() extrai partes de um caminho de arquivo
            // PATHINFO_EXTENSION = só a extensão (ex: 'pdf', 'docx', 'jpg')
            // strtolower() converte para minúsculo para comparar com segurança

            $extensoes_ok = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','txt','zip'];

            if (!in_array($extensao, $extensoes_ok)) {
                $mensagem = "Tipo de arquivo não permitido.";
                $tipo = "error";
            } else {
                // Gerando um nome único para o arquivo no servidor
                // Isso evita que dois arquivos com o mesmo nome se sobreescrevam
                // uniqid() = gera um ID único baseado no tempo atual
                // Ex: 'doc_6641f2a3c8e4b_proposta.pdf'
                $nome_unico = 'doc_' . uniqid() . '_' . preg_replace('/[^a-z0-9_.-]/i', '_', $nome_original);
                // preg_replace() remove caracteres especiais do nome
                // Deixa só letras, números, underscore, ponto e hífen

                $pasta   = __DIR__ . '/uploads/';
                $destino = $pasta . $nome_unico;

                // is_dir() verifica se a pasta existe
                if (!is_dir($pasta)) {
                    mkdir($pasta, 0755, true);
                    // mkdir() cria a pasta
                    // 0755 = permissões (leitura/escrita para o dono, leitura para outros)
                    // true = cria pastas intermediárias se necessário
                }

                // move_uploaded_file() move o arquivo da pasta temporária para o destino
                // É mais seguro que copy() para uploads porque valida que o arquivo
                // realmente veio de um upload HTTP
                if (move_uploaded_file($tmp, $destino)) {
                    $nome_e = mysqli_real_escape_string($conexao, $nome_original);
                    $unico_e = mysqli_real_escape_string($conexao, $nome_unico);
                    $mime_e  = mysqli_real_escape_string($conexao, $tipo_mime);
                    mysqli_query($conexao,
                        "INSERT INTO documentos (lead_id, nome_original, nome_arquivo, tamanho, tipo_mime)
                         VALUES ($id, '$nome_e', '$unico_e', $tamanho, '$mime_e')"
                    );
                    registrar_log($conexao, $id, 'arquivo_enviado',
                        "\"$nome_original\" (" . round($tamanho / 1024) . " KB)");
                    header("Location: leads_editar.php?id=$id#documentos");
                    exit();
                } else {
                    $mensagem = "Erro ao salvar o arquivo no servidor.";
                    $tipo = "error";
                }
            }
        }
    }
}

// ============================================================
// AÇÃO: Salvar histórico
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_historico'])) {
    if (!$pode_editar_leads) { header("Location: leads_editar.php?id=$id&aba=historico"); exit(); }
    $tipo_c    = mysqli_real_escape_string($conexao, $_POST['tipo_contato']);
    $anotacao  = mysqli_real_escape_string($conexao, trim($_POST['anotacao']));
    $proximo   = mysqli_real_escape_string($conexao, trim($_POST['proximo']));
    $data_hora = $_POST['data_hora'];

    if (!empty($tipo_c) && !empty($data_hora)) {
        mysqli_query($conexao,
            "INSERT INTO historico_contatos (lead_id, tipo, anotacao, proximo, data_hora)
             VALUES ($id, '$tipo_c', '$anotacao', '$proximo', '$data_hora')"
        );
    }
    header("Location: leads_editar.php?id=$id&aba=historico");
    exit();
}

// ============================================================
// AÇÃO: Salvar alterações do lead
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_lead'])) {
    if (!$pode_editar_leads) { $mensagem = "Sem permissão para editar leads."; $tipo = "error"; }
    elseif (isset($_POST['excluir'])) {
        mysqli_query($conexao, "DELETE FROM vendas WHERE lead_id = $id");
        mysqli_query($conexao, "DELETE FROM historico_contatos WHERE lead_id = $id");
        $docs = mysqli_query($conexao, "SELECT nome_arquivo FROM documentos WHERE lead_id = $id");
        while ($d = mysqli_fetch_assoc($docs)) {
            $caminho = __DIR__ . '/uploads/' . $d['nome_arquivo'];
            if (file_exists($caminho)) unlink($caminho);
        }
        mysqli_query($conexao, "DELETE FROM documentos WHERE lead_id = $id");
        mysqli_query($conexao, "DELETE FROM leads WHERE id = $id");
        $_SESSION['flash_msg']  = "Lead excluído.";
        $_SESSION['flash_tipo'] = "success";
        header("Location: leads.php");
        exit();
    } else {
    $nome        = trim($_POST['nome']);
    $telefone    = trim($_POST['telefone']);
    $email       = trim($_POST['email']);
    $origem        = $_POST['origem'];
    $status        = $_POST['status'];
    $observacoes   = trim($_POST['observacoes']);
    $tipo_proposta  = mysqli_real_escape_string($conexao, $_POST['tipo_proposta'] ?? '');
    $etiqueta       = mysqli_real_escape_string($conexao, $_POST['etiqueta'] ?? '');
    $possivel_raw   = str_replace(',', '.', trim($_POST['possivel_ganho'] ?? '0'));
    $possivel_ganho = (is_numeric($possivel_raw) && $possivel_raw > 0) ? (float)$possivel_raw : 0;
    $valor_raw   = str_replace(',', '.', trim($_POST['valor'] ?? '0'));
    $valor       = (is_numeric($valor_raw) && $valor_raw > 0) ? (float)$valor_raw : 0;

    if (empty($nome)) {
        $mensagem = "O nome é obrigatório."; $tipo = "error";
    } else {
        // Busca dados ANTES de salvar para comparar o que mudou
        $antes = mysqli_fetch_assoc(mysqli_query($conexao, "SELECT status, valor, nome FROM leads WHERE id=$id"));
        $status_antes = $antes['status'] ?? '';
        $valor_antes  = (float)($antes['valor'] ?? 0);

        $n  = mysqli_real_escape_string($conexao, $nome);
        $t  = mysqli_real_escape_string($conexao, $telefone);
        $e  = mysqli_real_escape_string($conexao, $email);
        $oe = mysqli_real_escape_string($conexao, $observacoes);

        // Salva atribuição se a coluna existir e for admin
        $col_atr2 = mysqli_query($conexao, "SHOW COLUMNS FROM leads LIKE 'atribuido_para'");
        $tem_atr2 = $col_atr2 && mysqli_num_rows($col_atr2) > 0;
        $atrib_id = isset($_POST['atribuido_para']) && is_numeric($_POST['atribuido_para']) && (int)$_POST['atribuido_para'] > 0
            ? (int)$_POST['atribuido_para'] : 'NULL';
        $set_atrib = ($tem_atr2 && eh_admin()) ? ", atribuido_para=$atrib_id" : '';

        mysqli_query($conexao,
            "UPDATE leads SET nome='$n',telefone='$t',email='$e',
             origem='$origem',status='$status',observacoes='$oe',valor=$valor,
             tipo_proposta='$tipo_proposta',possivel_ganho=$possivel_ganho,etiqueta='$etiqueta'
             $set_atrib
             WHERE id=$id"
        );

        // ── Log do que foi alterado ──
        $labels_status = [
            'novo'=>'Novo','em_contato'=>'Em contato','proposta_enviada'=>'Proposta enviada',
            'negociacao'=>'Negociação','fechado'=>'Fechado','perdido'=>'Perdido',
        ];

        if ($status !== $status_antes) {
            $de_txt  = $labels_status[$status_antes] ?? $status_antes;
            $para_txt = $labels_status[$status] ?? $status;
            if ($status === 'fechado') {
                registrar_log($conexao, $id, 'fechou',
                    "Negócio fechado" . ($valor > 0 ? " por R$ " . number_format($valor, 2, ',', '.') : ''));
            } else {
                registrar_log($conexao, $id, 'status_alterado',
                    "Status: \"$de_txt\" → \"$para_txt\"");
            }
        } elseif ($valor > 0 && $valor !== $valor_antes) {
            $de_fmt   = number_format($valor_antes, 2, ',', '.');
            $para_fmt = number_format($valor, 2, ',', '.');
            registrar_log($conexao, $id, 'valor_alterado',
                "Valor: R$ $de_fmt → R$ $para_fmt");
        } else {
            registrar_log($conexao, $id, 'editou', null);
        }

        if ($status === 'fechado' && $valor > 0) {
            $v = mysqli_query($conexao, "SELECT id FROM vendas WHERE lead_id=$id");
            if (mysqli_num_rows($v) === 0) {
                $hoje = date('Y-m-d');
                $desc = mysqli_real_escape_string($conexao, "Venda de site — $nome");
                mysqli_query($conexao,
                    "INSERT INTO vendas (lead_id,valor,descricao,data_venda)
                     VALUES ($id,$valor,'$desc','$hoje')"
                );
                $_SESSION['flash_msg'] = "Lead fechado! Venda de R$ ".number_format($valor,2,',','.')." registrada.";
            } else {
                mysqli_query($conexao, "UPDATE vendas SET valor=$valor WHERE lead_id=$id");
                $_SESSION['flash_msg'] = "Alterações salvas!";
            }
        } elseif ($status !== 'fechado') {
            mysqli_query($conexao, "DELETE FROM vendas WHERE lead_id=$id");
            $_SESSION['flash_msg'] = "Alterações salvas!";
        } else {
            $_SESSION['flash_msg'] = "Salvo. Adicione um valor para registrar a venda.";
        }

        $_SESSION['flash_tipo'] = "success";
        header("Location: leads.php");
        exit();
    }
    } // fim else (não é excluir)
}

// ============================================================
// BUSCANDO DADOS PARA EXIBIÇÃO
// ============================================================

// Histórico de contatos
$historico       = mysqli_query($conexao, "SELECT * FROM historico_contatos WHERE lead_id = $id ORDER BY data_hora DESC");
$total_historico = mysqli_num_rows($historico);

// Documentos
$documentos      = mysqli_query($conexao, "SELECT * FROM documentos WHERE lead_id = $id ORDER BY criado_em DESC");
$total_docs      = mysqli_num_rows($documentos);

// Lembretes do lead — filtrado por permissão
$criado_por_filtro = eh_admin() ? "" : (
    "AND (criado_por = " . (int)$_SESSION['colab_id'] . " OR criado_por IS NULL)"
);
$lembretes = mysqli_query($conexao,
    "SELECT l.*, ld.nome as lead_nome
     FROM lembretes l
     JOIN leads ld ON ld.id = l.lead_id
     WHERE l.lead_id = $id $criado_por_filtro
     ORDER BY l.concluido ASC, l.data_hora ASC"
);
$total_lembretes = mysqli_num_rows($lembretes);

// Log de atividades do lead
$log_atividades = mysqli_query($conexao,
    "SELECT * FROM log_atividades
     WHERE lead_id = $id
     ORDER BY criado_em DESC
     LIMIT 50"
);
$total_log = mysqli_num_rows($log_atividades);

// ABA ATIVA
// ============================================================
// Não dá para ler âncoras (#) no PHP (elas ficam no navegador)
// Então usamos um parâmetro GET: ?aba=documentos
$aba_ativa = $_GET['aba'] ?? 'dados';
// Padrão = aba de dados do lead
?>
<!DOCTYPE html>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — <?php echo htmlspecialchars($lead['nome']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .page-wrap { width: 100%; }

        /* ── Botões visuais de proposta e etiqueta ── */
        .proposta-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
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
        .proposta-btn:hover { border-color:var(--azul); color:var(--azul); background:var(--azul-light); }
        .proposta-btn.selecionado { border-color:var(--azul); background:var(--azul); color:white; }

        /* ── Lembretes ── */
        .lembrete-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
        }
        .lembrete-item:last-child { border-bottom: none; }
        .lembrete-item.concluido { opacity: 0.5; }

        .lembrete-ico {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 16px;
        }
        .ico-reuniao        { background: #EDE9FE; }
        .ico-follow_up      { background: #E0F2FE; }
        .ico-enviar_proposta{ background: #D1FAE5; }

        .lembrete-corpo { flex: 1; min-width: 0; }
        .lembrete-titulo {
            font-size: 13px; font-weight: 600;
            color: var(--text-1); margin-bottom: 3px;
        }
        .lembrete-item.concluido .lembrete-titulo {
            text-decoration: line-through;
        }
        .lembrete-meta {
            font-size: 11px; color: var(--text-3);
            display: flex; align-items: center; gap: 8px;
        }
        .lembrete-prazo {
            font-size: 11px; font-weight: 600;
            padding: 2px 8px; border-radius: 20px;
        }
        .prazo-ok      { background: var(--surface-2); color: var(--text-3); }
        .prazo-hoje    { background: var(--amarelo-light); color: var(--amarelo-text); }
        .prazo-amanha  { background: #FEF3C7; color: #92400E; }
        .prazo-vencido { background: var(--vermelho-light); color: var(--vermelho-text); }
        .prazo-concluido { background: var(--verde-light); color: var(--verde-text); }

        .lembrete-acoes { display: flex; gap: 6px; flex-shrink: 0; }

        /* ── Sistema de abas ── */
        .abas-wrap {
            display: flex;
            gap: 2px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .aba-btn {
            padding: 9px 16px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-3);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            /* margin-bottom negativo faz a borda da aba sobrepor a do container */
            transition: color 0.15s, border-color 0.15s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .aba-btn:hover { color: var(--text-1); }
        .aba-btn.ativa {
            color: var(--azul);
            border-bottom-color: var(--azul);
        }
        /* Badge de contagem dentro da aba */
        .aba-count {
            font-size: 10px;
            font-weight: 700;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 1px 6px;
            color: var(--text-3);
        }
        .aba-btn.ativa .aba-count {
            background: var(--azul-light);
            border-color: var(--azul-mid);
            color: var(--azul);
        }

        /* ── Log de atividades ── */
        .log-lista { display: flex; flex-direction: column; }
        .log-item {
            display: flex; align-items: flex-start;
            gap: 12px; padding: 12px 0;
            border-bottom: 1px solid var(--border);
            position: relative;
        }
        .log-item:last-child { border-bottom: none; }
        .log-item::before {
            content: ''; position: absolute;
            left: 16px; top: 46px; bottom: -1px;
            width: 1px; background: var(--border);
        }
        .log-item:last-child::before { display: none; }
        .log-ico {
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; z-index: 1;
        }
        .log-ico svg { width: 14px; height: 14px; }
        .log-corpo { flex: 1; min-width: 0; padding-top: 2px; }
        .log-linha-1 { font-size: 13px; color: var(--text-1); margin-bottom: 2px; line-height: 1.4; }
        .log-linha-1 strong { font-weight: 700; }
        .log-detalhe {
            font-size: 11px; color: var(--text-3);
            background: var(--surface-2); border: 1px solid var(--border);
            border-radius: 6px; padding: 3px 8px;
            display: inline-block; margin-top: 4px;
        }
        .log-data { font-size: 11px; color: var(--text-3); white-space: nowrap; padding-top: 2px; flex-shrink: 0; }

        /* ── Zona de upload ── */
        .upload-zona {
            border: 2px dashed var(--border-2);
            border-radius: var(--radius-lg);
            padding: 28px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s;
            background: var(--surface-2);
        }
        .upload-zona:hover,
        .upload-zona.dragover {
            border-color: var(--azul);
            background: var(--azul-light);
        }
        .upload-zona svg {
            width: 32px; height: 32px;
            stroke: var(--text-3);
            margin-bottom: 8px;
        }
        .upload-zona:hover svg,
        .upload-zona.dragover svg { stroke: var(--azul); }
        .upload-titulo {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-2);
            margin-bottom: 4px;
        }
        .upload-subtitulo {
            font-size: 12px;
            color: var(--text-3);
        }
        /* Input file fica invisível — clicamos na zona por cima */
        #input-arquivo { display: none; }

        /* ── Lista de documentos ── */
        .doc-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .doc-item:last-child { border-bottom: none; }

        /* Ícone colorido por tipo de arquivo */
        .doc-icone {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }
        .icone-pdf   { background: #FEE2E2; color: #991B1B; }
        .icone-word  { background: #DBEAFE; color: #1E40AF; }
        .icone-img   { background: #D1FAE5; color: #065F46; }
        .icone-outro { background: var(--cinza-light); color: var(--cinza-text); }

        .doc-info { flex: 1; min-width: 0; }
        .doc-nome {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            /* ellipsis = corta o texto com "..." se for muito longo */
        }
        .doc-meta {
            font-size: 11px;
            color: var(--text-3);
            margin-top: 2px;
        }
        .doc-acoes { display: flex; gap: 6px; flex-shrink: 0; }

        /* ── Timeline (igual ao histórico) ── */
        .timeline { position: relative; padding-left: 24px; }
        .timeline::before { content:''; position:absolute; left:7px; top:6px; bottom:6px; width:1px; background:var(--border); }
        .timeline-item { position:relative; margin-bottom:16px; }
        .timeline-item:last-child { margin-bottom:0; }
        .timeline-dot { position:absolute; left:-20px; top:14px; width:10px; height:10px; border-radius:50%; border:2px solid var(--surface); }
        .dot-ligacao  { background:var(--azul); }
        .dot-whatsapp { background:#25D366; }
        .dot-email    { background:var(--amarelo); }
        .dot-reuniao  { background:#7C3AED; }
        .dot-outro    { background:var(--text-3); }
        .timeline-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:14px 16px; }
        .timeline-header { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
        .badge-tipo { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; }
        .badge-tipo svg { width:11px; height:11px; }
        .tipo-ligacao  { background:var(--azul-light);   color:#0055B3; }
        .tipo-whatsapp { background:#DCFCE7;              color:#166534; }
        .tipo-email    { background:var(--amarelo-light); color:var(--amarelo-text); }
        .tipo-reuniao  { background:#EDE9FE;              color:#4C1D95; }
        .tipo-outro    { background:var(--cinza-light);   color:var(--cinza-text); }
        .timeline-data { font-size:11px; color:var(--text-3); margin-left:auto; }
        .timeline-anotacao { font-size:13px; color:var(--text-1); line-height:1.5; margin-bottom:6px; }
        .timeline-proximo { display:flex; align-items:flex-start; gap:6px; background:var(--surface-2); border-radius:7px; padding:7px 10px; font-size:12px; color:var(--text-2); margin-top:8px; }
        .timeline-proximo svg { width:13px; height:13px; stroke:var(--azul); flex-shrink:0; margin-top:1px; }
        .timeline-proximo strong { color:var(--azul); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; display:block; margin-bottom:1px; }
        .btn-del { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:6px; border:none; background:none; cursor:pointer; opacity:0; transition:opacity 0.15s, background 0.15s; text-decoration:none; }
        .btn-del svg { width:13px; height:13px; stroke:var(--vermelho); }
        .timeline-card:hover .btn-del { opacity:1; }
        .btn-del:hover { background:var(--vermelho-light); }

        .secao-titulo { font-size:13px; font-weight:700; color:var(--text-1); margin-bottom:14px; display:flex; align-items:center; gap:8px; }
        .secao-titulo .count { font-size:11px; font-weight:600; color:var(--text-3); background:var(--surface-2); border:1px solid var(--border); border-radius:20px; padding:1px 8px; }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">Editar lead</span>
    </div>

    <div class="page-content page-wrap">

        <a href="leads.php" class="back-link" style="display:inline-flex;margin-bottom:8px;">← Voltar para Leads</a>

        <div style="margin-bottom:16px;">
            <div style="font-size:20px;font-weight:800;color:var(--text-1);letter-spacing:-0.3px;">
                <?php echo htmlspecialchars($lead['nome']); ?>
            </div>
            <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
                Cadastrado em <?php echo date('d/m/Y \à\s H:i', strtotime($lead['criado_em'])); ?>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo === 'error' ? 'error' : 'success'; ?>"
                 style="margin-bottom:16px;">
                <?php echo $mensagem; ?>
            </div>
        <?php endif; ?>

        <!-- ── Sistema de abas ── -->
        <!-- Cada aba é um link para ?aba=X — o PHP lê e exibe o conteúdo correto -->
        <div class="abas-wrap">
            <a href="?id=<?php echo $id; ?>&aba=dados"
               class="aba-btn <?php echo $aba_ativa === 'dados' ? 'ativa' : ''; ?>">
                Dados do lead
            </a>
            <a href="?id=<?php echo $id; ?>&aba=historico"
               class="aba-btn <?php echo $aba_ativa === 'historico' ? 'ativa' : ''; ?>">
                Histórico
                <span class="aba-count"><?php echo $total_historico; ?></span>
            </a>
            <a href="?id=<?php echo $id; ?>&aba=documentos"
               class="aba-btn <?php echo $aba_ativa === 'documentos' ? 'ativa' : ''; ?>">
                Documentos
                <span class="aba-count"><?php echo $total_docs; ?></span>
            </a>
            <a href="?id=<?php echo $id; ?>&aba=lembretes"
               class="aba-btn <?php echo $aba_ativa === 'lembretes' ? 'ativa' : ''; ?>">
                Lembretes
                <?php if ($total_lembretes > 0): ?>
                <span class="aba-count"><?php echo $total_lembretes; ?></span>
                <?php endif; ?>
            </a>
            <a href="?id=<?php echo $id; ?>&aba=log"
               class="aba-btn <?php echo $aba_ativa === 'log' ? 'ativa' : ''; ?>">
                Log
                <?php if ($total_log > 0): ?>
                <span class="aba-count"><?php echo $total_log; ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- ============================================================
             ABA 1: DADOS DO LEAD
             ============================================================ -->
        <?php if ($aba_ativa === 'dados'): ?>
        <div class="card">
            <?php if (!$pode_editar_leads): ?>
            <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--text-3);display:flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Você tem permissão apenas de visualização — não é possível editar este lead.
            </div>
            <?php endif; ?>
            <form action="?id=<?php echo $id; ?>&aba=dados" method="post">
            <?php if (!$pode_editar_leads): ?><fieldset disabled style="border:none;padding:0;margin:0;"><?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Nome <span class="req">*</span></label>
                    <input class="form-control" type="text" name="nome"
                        value="<?php echo htmlspecialchars($lead['nome']); ?>">
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Telefone</label>
                        <input class="form-control" type="tel" name="telefone"
                            value="<?php echo htmlspecialchars($lead['telefone']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email"
                            value="<?php echo htmlspecialchars($lead['email']); ?>">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Origem</label>
                        <select class="form-control" name="origem">
                            <option value="">Selecione...</option>
                            <?php foreach(['Instagram','Indicação','Site','WhatsApp','Google','Outro'] as $o):
                                $s = ($lead['origem']===$o)?'selected':''; ?>
                                <option value="<?php echo $o; ?>" <?php echo $s; ?>><?php echo $o; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status" onchange="toggleVenda(this.value)">
                            <?php foreach(['novo'=>'Novo','em_contato'=>'Em contato','proposta_enviada'=>'Proposta enviada','negociacao'=>'Negociação','fechado'=>'Fechado','perdido'=>'Perdido'] as $v=>$r):
                                $s = ($lead['status']===$v)?'selected':''; ?>
                                <option value="<?php echo $v; ?>" <?php echo $s; ?>><?php echo $r; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Tipo de proposta — botões visuais -->
                <div class="form-group">
                    <label class="form-label">Tipo de proposta</label>
                    <input type="hidden" name="tipo_proposta" id="tipo_proposta_input"
                        value="<?php echo htmlspecialchars($lead['tipo_proposta'] ?? ''); ?>">
                    <div class="proposta-grid">
                        <?php foreach(['Site','Software sob medida','Fluxo de IA','Agente de IA','Landing Page','Outro'] as $tp):
                            $sel = (($lead['tipo_proposta'] ?? '') === $tp) ? 'selecionado' : ''; ?>
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
                            value="<?php echo ($lead['possivel_ganho'] ?? 0) > 0 ? $lead['possivel_ganho'] : ''; ?>">
                    </div>
                    <div style="font-size:11px;color:var(--text-3);margin-top:4px;">
                        Valor que você pretende cobrar ou tem em mente
                    </div>
                </div>

                <!-- Etiqueta — botões visuais -->
                <div class="form-group">
                    <label class="form-label">Etiqueta</label>
                    <input type="hidden" name="etiqueta" id="etiqueta_input"
                        value="<?php echo htmlspecialchars($lead['etiqueta'] ?? ''); ?>">
                    <div class="proposta-grid">
                        <?php foreach(['VIP','Urgente','Retornar','Proposta enviada','Aguardando','Frio'] as $et):
                            $sel = (($lead['etiqueta'] ?? '') === $et) ? 'selecionado' : ''; ?>
                            <button type="button" class="proposta-btn <?php echo $sel; ?>"
                                onclick="selecionarEtiqueta(this, '<?php echo $et; ?>')">
                                <?php echo $et; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                <?php
                // Campo de atribuição — só aparece se a coluna existir e for admin
                $col_atr = mysqli_query($conexao, "SHOW COLUMNS FROM leads LIKE 'atribuido_para'");
                if ($col_atr && mysqli_num_rows($col_atr) > 0 && eh_admin()):
                    $colabs_r = mysqli_query($conexao, "SELECT id, nome_completo, colaborador_id FROM colaboradores WHERE status='ativo' ORDER BY nome_completo");
                    $colabs_lista = [];
                    while ($cr = mysqli_fetch_assoc($colabs_r)) $colabs_lista[] = $cr;
                    if (!empty($colabs_lista)):
                ?>
                <div class="form-group" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                    <label class="form-label">Responsável pelo lead</label>
                    <select name="atribuido_para" class="form-control">
                        <option value="">Sem responsável (todos veem)</option>
                        <?php foreach($colabs_lista as $cl):
                            $sel = (($lead['atribuido_para'] ?? 0) == $cl['id']) ? 'selected' : ''; ?>
                        <option value="<?php echo $cl['id']; ?>" <?php echo $sel; ?>>
                            <?php echo htmlspecialchars($cl['nome_completo']); ?>
                            <span style="color:var(--text-3);">(<?php echo $cl['colaborador_id']; ?>)</span>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px;color:var(--text-3);margin-top:4px;">
                        Quando atribuído, apenas esse colaborador verá o lead na lista dele
                    </div>
                </div>
                <?php endif; endif; ?>
                    </div>
                    <button type="button" id="btn-limpar-etiqueta"
                        onclick="limparEtiqueta()"
                        style="display:<?php echo !empty($lead['etiqueta'])?'inline-flex':'none'; ?>;margin-top:6px;font-size:11px;color:var(--text-3);background:none;border:none;cursor:pointer;gap:4px;align-items:center;">
                        × Limpar etiqueta
                    </button>
                </div>

                <div class="sale-block" id="bloco-venda"
                     style="<?php echo $lead['status']!=='fechado'?'display:none':''; ?>">
                    <div class="sale-block-title">💰 Registrar venda</div>
                    <div class="sale-block-desc">Ao salvar com "Fechado", o valor vai para o financeiro.</div>
                    <label class="form-label">Valor da venda</label>
                    <div class="input-group">
                        <span class="input-prefix">R$</span>
                        <input class="form-control" type="number" name="valor"
                            placeholder="0.00" step="0.01" min="0"
                            value="<?php echo $lead['valor']>0?$lead['valor']:''; ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-top:12px;">
                    <label class="form-label">Observações</label>
                    <textarea class="form-control" name="observacoes"><?php echo htmlspecialchars($lead['observacoes']); ?></textarea>
                </div>

                <div class="form-footer">
                    <div class="form-footer-left">
                        <?php if ($pode_editar_leads): ?>
                        <button type="submit" name="salvar_lead" class="btn btn-primary">Salvar alterações</button>
                        <?php endif; ?>
                        <a href="leads.php" class="btn btn-secondary">Voltar</a>
                    </div>
                    <?php if ($pode_editar_leads): ?>
                    <button type="submit" name="excluir" class="btn btn-danger"
                        onclick="return confirm('Excluir este lead e todos os dados vinculados?')">
                        Excluir lead
                    </button>
                    <?php endif; ?>
                </div>
            <?php if (!$pode_editar_leads): ?></fieldset><?php endif; ?>
            </form>
        </div>
             ============================================================ -->
        <?php elseif ($aba_ativa === 'historico'): ?>

        <!-- Formulário de novo contato — só para quem pode editar -->
        <?php if ($pode_editar_leads): ?>
        <div class="card" style="margin-bottom:16px;">
            <div style="font-size:13px;font-weight:600;color:var(--text-2);margin-bottom:14px;">
                Registrar novo contato
            </div>
            <form action="?id=<?php echo $id; ?>&aba=historico" method="post">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Tipo <span class="req">*</span></label>
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
                        <label class="form-label">Data e hora <span class="req">*</span></label>
                        <input class="form-control" type="datetime-local" name="data_hora"
                            value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Anotação</label>
                    <textarea class="form-control" name="anotacao" rows="2"
                        placeholder="O que foi dito ou combinado..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Próximo passo</label>
                    <input class="form-control" type="text" name="proximo"
                        placeholder="Ex: Ligar na sexta, Enviar proposta...">
                </div>
                <button type="submit" name="salvar_historico" class="btn btn-primary btn-sm">
                    Registrar contato
                </button>
            </form>
        </div>
        <?php endif; // fim if pode_editar_leads ?>

        <!-- Timeline do histórico -->
        <div class="secao-titulo">
            Histórico <span class="count"><?php echo $total_historico; ?></span>
        </div>

        <?php if ($total_historico === 0): ?>
            <div class="card">
                <div class="empty-state">
                    <strong>Nenhum contato registrado</strong>
                    <?php echo $pode_editar_leads ? 'Use o formulário acima para registrar o primeiro' : 'Nenhum contato registrado ainda'; ?>
                </div>
            </div>
        <?php else: ?>
            <?php
            $icones = [
                'ligacao'  => '<svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91A16 16 0 0 0 13 14.85l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21 16.92z"/></svg>',
                'whatsapp' => '<svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
                'email'    => '<svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
                'reuniao'  => '<svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                'outro'    => '<svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            ];
            $labels_tipo = ['ligacao'=>'Ligação','whatsapp'=>'WhatsApp','email'=>'Email','reuniao'=>'Reunião','outro'=>'Outro'];
            ?>
            <div class="timeline">
                <?php while ($h = mysqli_fetch_assoc($historico)):
                    $t_h    = $h['tipo'];
                    $icone  = $icones[$t_h]    ?? $icones['outro'];
                    $label  = $labels_tipo[$t_h] ?? 'Outro';
                    $css_b  = 'tipo-'  . $t_h;
                    $css_d  = 'dot-'   . $t_h;
                    $data_f = date('d/m/Y \à\s H:i', strtotime($h['data_hora']));
                ?>
                <div class="timeline-item">
                    <div class="timeline-dot <?php echo $css_d; ?>"></div>
                    <div class="timeline-card">
                        <div class="timeline-header">
                            <span class="badge-tipo <?php echo $css_b; ?>"><?php echo $icone; ?><?php echo $label; ?></span>
                            <span class="timeline-data"><?php echo $data_f; ?></span>
                            <?php if ($pode_editar_leads): ?>
                            <a href="historico_deletar.php?id=<?php echo $h['id']; ?>&lead_id=<?php echo $id; ?>"
                               class="btn-del" onclick="return confirm('Excluir este registro?')">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($h['anotacao'])): ?>
                            <div class="timeline-anotacao"><?php echo nl2br(htmlspecialchars($h['anotacao'])); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($h['proximo'])): ?>
                            <div class="timeline-proximo">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                                <div><strong>Próximo passo</strong><?php echo htmlspecialchars($h['proximo']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>

        <!-- ============================================================
             ABA 3: DOCUMENTOS
             ============================================================ -->
        <?php elseif ($aba_ativa === 'documentos'): ?>

        <!-- Zona de upload — só para quem pode editar -->
        <?php if ($pode_editar_leads): ?>
        <div class="card" style="margin-bottom:16px;">
            <div style="font-size:13px;font-weight:600;color:var(--text-2);margin-bottom:14px;">
                Enviar documento
            </div>
            <form action="?id=<?php echo $id; ?>&aba=documentos" method="post"
                  enctype="multipart/form-data" id="form-upload">
                <div class="upload-zona" id="upload-zona" onclick="document.getElementById('input-arquivo').click()">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <div class="upload-titulo" id="upload-titulo">
                        Clique para selecionar ou arraste o arquivo aqui
                    </div>
                    <div class="upload-subtitulo">PDF, Word, Imagens e outros — máximo 10MB</div>
                </div>
                <input type="file" id="input-arquivo" name="arquivo"
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.zip">
                <div style="margin-top:12px;display:none;" id="btn-enviar-wrap">
                    <button type="submit" name="upload_doc" class="btn btn-primary">Enviar documento</button>
                    <button type="button" class="btn btn-secondary" onclick="cancelarUpload()">Cancelar</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Lista de documentos -->
        <div class="secao-titulo">
            Documentos <span class="count"><?php echo $total_docs; ?></span>
        </div>

        <?php if ($total_docs === 0): ?>
            <div class="card">
                <div class="empty-state">
                    <strong>Nenhum documento ainda</strong>
                    Envie propostas, contratos ou qualquer arquivo relacionado a este lead
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <?php while ($doc = mysqli_fetch_assoc($documentos)):
                    $ext = strtolower(pathinfo($doc['nome_original'], PATHINFO_EXTENSION));

                    // Definindo ícone e classe por tipo de arquivo
                    if ($ext === 'pdf') {
                        $icone_txt = 'PDF'; $icone_cls = 'icone-pdf';
                    } elseif (in_array($ext, ['doc','docx'])) {
                        $icone_txt = 'DOC'; $icone_cls = 'icone-word';
                    } elseif (in_array($ext, ['jpg','jpeg','png','gif'])) {
                        $icone_txt = 'IMG'; $icone_cls = 'icone-img';
                    } else {
                        $icone_txt = strtoupper($ext); $icone_cls = 'icone-outro';
                    }

                    // Formatando o tamanho do arquivo de bytes para legível
                    $bytes = $doc['tamanho'];
                    if ($bytes >= 1048576) {
                        $tam = round($bytes / 1048576, 1) . ' MB';
                    } elseif ($bytes >= 1024) {
                        $tam = round($bytes / 1024, 1) . ' KB';
                    } else {
                        $tam = $bytes . ' B';
                    }

                    $data_doc = date('d/m/Y', strtotime($doc['criado_em']));
                ?>
                <div class="doc-item">
                    <!-- Ícone colorido com a extensão -->
                    <div class="doc-icone <?php echo $icone_cls; ?>">
                        <?php echo $icone_txt; ?>
                    </div>

                    <div class="doc-info">
                        <div class="doc-nome">
                            <?php echo htmlspecialchars($doc['nome_original']); ?>
                        </div>
                        <div class="doc-meta">
                            <?php echo $tam; ?> · <?php echo $data_doc; ?>
                        </div>
                    </div>

                    <div class="doc-acoes">
                        <a href="uploads/<?php echo urlencode($doc['nome_arquivo']); ?>"
                           target="_blank" class="btn btn-secondary btn-sm"
                           download="<?php echo htmlspecialchars($doc['nome_original']); ?>">
                            ↓ Baixar
                        </a>
                        <?php if ($pode_editar_leads): ?>
                        <a href="documento_deletar.php?id=<?php echo $doc['id']; ?>&lead_id=<?php echo $id; ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Excluir este documento permanentemente?')">
                            Excluir
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>

        <!-- ============================================================
             ABA 4: LEMBRETES
             ============================================================ -->
        <?php elseif ($aba_ativa === 'lembretes'): ?>

        <?php
        // Ícones e labels por tipo
        $tipos_info = [
            'reuniao'         => ['ico'=>'🤝', 'label'=>'Reunião',         'cls'=>'ico-reuniao'],
            'follow_up'       => ['ico'=>'📞', 'label'=>'Follow-up',       'cls'=>'ico-follow_up'],
            'enviar_proposta' => ['ico'=>'📄', 'label'=>'Enviar proposta', 'cls'=>'ico-enviar_proposta'],
        ];
        ?>

        <!-- Formulário de novo lembrete -->
        <?php if ($pode_editar_leads): ?>
        <div class="card" style="margin-bottom:16px;">
            <div style="font-size:13px;font-weight:600;color:var(--text-2);margin-bottom:14px;">
                Novo lembrete
            </div>
            <form action="lembrete_salvar.php" method="post">
                <input type="hidden" name="acao" value="criar">
                <input type="hidden" name="lead_id" value="<?php echo $id; ?>">

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Tipo <span class="req">*</span></label>
                        <select class="form-control" name="tipo">
                            <option value="">Selecione...</option>
                            <?php foreach($tipos_info as $k => $t): ?>
                            <option value="<?php echo $k; ?>"><?php echo $t['ico'].' '.$t['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Data e hora <span class="req">*</span></label>
                        <input class="form-control" type="datetime-local" name="data_hora"
                            value="<?php echo date('Y-m-d\T09:00'); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Título <span class="req">*</span></label>
                    <input class="form-control" type="text" name="titulo"
                        placeholder="Ex: Ligar para confirmar reunião, Enviar proposta revisada...">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">+ Criar lembrete</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Lista de lembretes -->
        <div class="secao-titulo">
            Lembretes <span class="count"><?php echo $total_lembretes; ?></span>
        </div>

        <?php if ($total_lembretes === 0): ?>
            <div class="card">
                <div class="empty-state">
                    <strong>Nenhum lembrete</strong>
                    Crie um lembrete para não esquecer de nenhum follow-up
                </div>
            </div>
        <?php else: ?>
        <div class="card">
            <?php while ($lem = mysqli_fetch_assoc($lembretes)):
                $info      = $tipos_info[$lem['tipo']] ?? ['ico'=>'📌','label'=>'Outro','cls'=>''];
                $concluido = (bool)$lem['concluido'];
                $dt        = new DateTime($lem['data_hora']);
                $agora     = new DateTime();
                $diff_dias = (int)$agora->diff($dt)->format('%r%a');

                // Define classe e texto do prazo
                if ($concluido) {
                    $prazo_cls = 'prazo-concluido';
                    $prazo_txt = '✓ Concluído';
                } elseif ($diff_dias < 0) {
                    $prazo_cls = 'prazo-vencido';
                    $prazo_txt = 'Vencido ' . abs($diff_dias) . 'd atrás';
                } elseif ($diff_dias === 0) {
                    $prazo_cls = 'prazo-hoje';
                    $prazo_txt = 'Hoje às ' . $dt->format('H:i');
                } elseif ($diff_dias === 1) {
                    $prazo_cls = 'prazo-amanha';
                    $prazo_txt = 'Amanhã às ' . $dt->format('H:i');
                } else {
                    $prazo_cls = 'prazo-ok';
                    $prazo_txt = $dt->format('d/m/Y \à\s H:i');
                }
            ?>
            <div class="lembrete-item <?php echo $concluido ? 'concluido' : ''; ?>">
                <div class="lembrete-ico <?php echo $info['cls']; ?>"><?php echo $info['ico']; ?></div>
                <div class="lembrete-corpo">
                    <div class="lembrete-titulo"><?php echo htmlspecialchars($lem['titulo']); ?></div>
                    <div class="lembrete-meta">
                        <span><?php echo $info['label']; ?></span>
                        <span class="lembrete-prazo <?php echo $prazo_cls; ?>"><?php echo $prazo_txt; ?></span>
                    </div>
                </div>
                <?php if (!$concluido && $pode_editar_leads): ?>
                <div class="lembrete-acoes">
                    <form action="lembrete_salvar.php" method="post" style="display:inline;">
                        <input type="hidden" name="acao" value="concluir">
                        <input type="hidden" name="id" value="<?php echo $lem['id']; ?>">
                        <input type="hidden" name="lead_id" value="<?php echo $id; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" title="Marcar como concluído">✓</button>
                    </form>
                    <form action="lembrete_salvar.php" method="post" style="display:inline;">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" value="<?php echo $lem['id']; ?>">
                        <input type="hidden" name="lead_id" value="<?php echo $id; ?>">
                        <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Excluir este lembrete?')" title="Excluir">✕</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>

        <?php endif; // fim das abas ?>

        <!-- ============================================================
             ABA 5: LOG DE ATIVIDADES
             ============================================================ -->
        <?php if ($aba_ativa === 'log'): ?>

        <div class="secao-titulo">
            Histórico de atividades <span class="count"><?php echo $total_log; ?></span>
        </div>

        <?php if ($total_log === 0): ?>
            <div class="card">
                <div class="empty-state">
                    <strong>Nenhuma atividade registrada</strong>
                    As ações neste lead aparecerão aqui automaticamente
                </div>
            </div>
        <?php else: ?>
        <div class="card">
            <div class="log-lista">
            <?php while ($entry = mysqli_fetch_assoc($log_atividades)):
                $cor = cor_log($entry['acao']);
                $ico = icone_log($entry['acao']);
                $txt = texto_acao($entry['acao']);

                // Formata a data de forma relativa
                $dt   = new DateTime($entry['criado_em']);
                $ago  = new DateTime();
                $diff = $ago->diff($dt);
                if ($diff->days === 0) {
                    $quando = 'hoje às ' . $dt->format('H:i');
                } elseif ($diff->days === 1) {
                    $quando = 'ontem às ' . $dt->format('H:i');
                } elseif ($diff->days < 7) {
                    $quando = 'há ' . $diff->days . ' dias';
                } else {
                    $quando = $dt->format('d/m/Y \à\s H:i');
                }
            ?>
            <div class="log-item">
                <div class="log-ico" style="background:<?php echo $cor['bg']; ?>;color:<?php echo $cor['fg']; ?>;">
                    <?php echo $ico; ?>
                </div>
                <div class="log-corpo">
                    <div class="log-linha-1">
                        <strong><?php echo htmlspecialchars($entry['usuario']); ?></strong>
                        <?php echo $txt; ?>
                    </div>
                    <?php if (!empty($entry['detalhe'])): ?>
                    <div class="log-detalhe"><?php echo htmlspecialchars($entry['detalhe']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="log-data"><?php echo $quando; ?></div>
            </div>
            <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // fim aba log ?>

    </div>
</div>

<script>
window.onload = function() {

    // ── Toggle do bloco de venda ──
    window.toggleVenda = function(v) {
        var b = document.getElementById('bloco-venda');
        if (b) b.style.display = v === 'fechado' ? 'block' : 'none';
    };

    // ── Seleciona tipo de proposta ──
    window.selecionarProposta = function(btn, valor) {
        var grids = document.querySelectorAll('.proposta-grid');
        if (grids[0]) grids[0].querySelectorAll('.proposta-btn').forEach(function(b){ b.classList.remove('selecionado'); });
        btn.classList.add('selecionado');
        document.getElementById('tipo_proposta_input').value = valor;
    };

    // ── Seleciona etiqueta ──
    window.selecionarEtiqueta = function(btn, valor) {
        var grids = document.querySelectorAll('.proposta-grid');
        if (grids[1]) grids[1].querySelectorAll('.proposta-btn').forEach(function(b){ b.classList.remove('selecionado'); });
        btn.classList.add('selecionado');
        document.getElementById('etiqueta_input').value = valor;
        document.getElementById('btn-limpar-etiqueta').style.display = 'inline-flex';
    };

    // ── Limpa etiqueta ──
    window.limparEtiqueta = function() {
        document.getElementById('etiqueta_input').value = '';
        var grids = document.querySelectorAll('.proposta-grid');
        if (grids[1]) grids[1].querySelectorAll('.proposta-btn').forEach(function(b){ b.classList.remove('selecionado'); });
        document.getElementById('btn-limpar-etiqueta').style.display = 'none';
    };

    // ── Upload: mostra o nome do arquivo ao selecionar ──
    var input = document.getElementById('input-arquivo');
    if (input) {
        input.addEventListener('change', function() {
            if (this.files.length > 0) {
                var nome = this.files[0].name;
                document.getElementById('upload-titulo').textContent = '📄 ' + nome;
                // Mostra o botão de enviar
                document.getElementById('btn-enviar-wrap').style.display = 'block';
            }
        });
    }

    // ── Drag and drop ──
    var zona = document.getElementById('upload-zona');
    if (zona) {
        // Quando arrasta um arquivo sobre a zona
        zona.addEventListener('dragover', function(e) {
            e.preventDefault();
            // e.preventDefault() evita que o navegador abra o arquivo
            zona.classList.add('dragover');
        });

        // Quando sai da zona sem soltar
        zona.addEventListener('dragleave', function() {
            zona.classList.remove('dragover');
        });

        // Quando solta o arquivo na zona
        zona.addEventListener('drop', function(e) {
            e.preventDefault();
            zona.classList.remove('dragover');

            // e.dataTransfer.files = arquivos que foram soltos
            var files = e.dataTransfer.files;
            if (files.length > 0) {
                // Transfere o arquivo para o input hidden
                // Isso permite que o form envie o arquivo normalmente
                var dt = new DataTransfer();
                dt.items.add(files[0]);
                input.files = dt.files;

                document.getElementById('upload-titulo').textContent = '📄 ' + files[0].name;
                document.getElementById('btn-enviar-wrap').style.display = 'block';
            }
        });
    }
};

// Cancela o upload — reseta o formulário
function cancelarUpload() {
    document.getElementById('form-upload').reset();
    document.getElementById('upload-titulo').textContent = 'Clique para selecionar ou arraste o arquivo aqui';
    document.getElementById('btn-enviar-wrap').style.display = 'none';
    document.getElementById('upload-zona').classList.remove('dragover');
}
</script>

</body>
</html>