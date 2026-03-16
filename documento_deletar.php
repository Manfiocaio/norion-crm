<?php
// ============================================================
// ARQUIVO: documento_deletar.php
// O QUE FAZ: Exclui um documento do banco E do servidor
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_permissao('editar_leads');

$id      = isset($_GET['id'])      && is_numeric($_GET['id'])      ? (int)$_GET['id']      : 0;
$lead_id = isset($_GET['lead_id']) && is_numeric($_GET['lead_id']) ? (int)$_GET['lead_id'] : 0;

if ($id <= 0 || $lead_id <= 0) {
    header("Location: leads.php");
    exit();
}

// Busca o nome do arquivo no banco antes de deletar
// Precisamos do nome para poder apagar o arquivo físico também
$r = mysqli_query($conexao,
    "SELECT nome_arquivo FROM documentos WHERE id = $id AND lead_id = $lead_id"
);

if (mysqli_num_rows($r) === 0) {
    header("Location: leads_editar.php?id=$lead_id#documentos");
    exit();
}

$doc = mysqli_fetch_assoc($r);

// Caminho completo do arquivo no servidor
// __DIR__ = diretório onde este arquivo PHP está
$caminho = __DIR__ . '/uploads/' . $doc['nome_arquivo'];

// Apaga o arquivo físico do servidor
// file_exists() verifica se o arquivo existe antes de tentar apagar
if (file_exists($caminho)) {
    unlink($caminho);
    // unlink() = função PHP para deletar um arquivo
}

// Apaga o registro do banco
mysqli_query($conexao,
    "DELETE FROM documentos WHERE id = $id AND lead_id = $lead_id"
);

// Volta para a aba de documentos do lead
header("Location: leads_editar.php?id=$lead_id#documentos");
exit();