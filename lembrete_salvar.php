<?php
// ============================================================
// ARQUIVO: lembrete_salvar.php
// O QUE FAZ: Cria, conclui ou exclui lembretes via POST
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
require_once 'config/log.php';
requer_permissao('ver_leads');

$acao    = $_POST['acao']    ?? '';
$lead_id = (int)($_POST['lead_id'] ?? 0);

// Quem está criando — admin ou colaborador
$criado_por = eh_admin() ? null : (int)$_SESSION['colab_id'];
$criado_por_sql = $criado_por === null ? 'NULL' : $criado_por;

// ── Criar lembrete ──
if ($acao === 'criar' && $lead_id > 0) {
    $tipo      = mysqli_real_escape_string($conexao, $_POST['tipo'] ?? '');
    $titulo    = mysqli_real_escape_string($conexao, trim($_POST['titulo'] ?? ''));
    $data_hora = mysqli_real_escape_string($conexao, $_POST['data_hora'] ?? '');

    $tipos_validos = ['reuniao','follow_up','enviar_proposta'];
    if (!in_array($tipo, $tipos_validos) || empty($titulo) || empty($data_hora)) {
        $_SESSION['flash_msg']  = "Preencha todos os campos do lembrete.";
        $_SESSION['flash_tipo'] = "error";
    } else {
        mysqli_query($conexao,
            "INSERT INTO lembretes (lead_id, criado_por, tipo, titulo, data_hora)
             VALUES ($lead_id, $criado_por_sql, '$tipo', '$titulo', '$data_hora')"
        );
        registrar_log($conexao, $lead_id, 'lembrete_criado',
            "\"$titulo\" para " . date('d/m/Y H:i', strtotime($data_hora)));
        $_SESSION['flash_msg']  = "Lembrete criado!";
        $_SESSION['flash_tipo'] = "success";
    }
    header("Location: leads_editar.php?id=$lead_id&aba=lembretes");
    exit();
}

// ── Concluir lembrete ──
if ($acao === 'concluir') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        // Só conclui se for o dono ou admin
        $where_perm = eh_admin()
            ? "id = $id"
            : "id = $id AND (criado_por = $criado_por OR criado_por IS NULL)";
        mysqli_query($conexao, "UPDATE lembretes SET concluido = 1 WHERE $where_perm");
    }
    header("Location: leads_editar.php?id=$lead_id&aba=lembretes");
    exit();
}

// ── Excluir lembrete ──
if ($acao === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $where_perm = eh_admin()
            ? "id = $id"
            : "id = $id AND criado_por = $criado_por";
        mysqli_query($conexao, "DELETE FROM lembretes WHERE $where_perm");
    }
    header("Location: leads_editar.php?id=$lead_id&aba=lembretes");
    exit();
}

header("Location: leads.php");
exit();