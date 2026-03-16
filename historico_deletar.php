<?php
// ============================================================
// ARQUIVO: historico_deletar.php
// O QUE FAZ: Exclui um registro do histórico e volta ao lead
// ============================================================
// Este arquivo é acessado via URL:
// historico_deletar.php?id=3&lead_id=1
// O JS vai pedir confirmação antes de acessar este link

session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_permissao('editar_leads');

// Lendo os parâmetros da URL
$id      = isset($_GET['id'])      && is_numeric($_GET['id'])      ? (int)$_GET['id']      : 0;
$lead_id = isset($_GET['lead_id']) && is_numeric($_GET['lead_id']) ? (int)$_GET['lead_id'] : 0;

// Validação: os dois IDs precisam ser válidos
if ($id <= 0 || $lead_id <= 0) {
    header("Location: leads.php");
    exit();
}

// DELETE FROM remove o registro
// O AND lead_id = $lead_id é uma segurança extra:
// garante que só deletamos um histórico que realmente pertence a este lead
mysqli_query($conexao, "DELETE FROM historico_contatos WHERE id = $id AND lead_id = $lead_id");

// Volta para a página do lead com âncora #historico
// O #historico faz o navegador rolar direto para aquela seção da página
header("Location: leads_editar.php?id=$lead_id#historico");
exit();
?>