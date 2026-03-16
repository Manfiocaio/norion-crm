<?php
// ============================================================
// ARQUIVO: lead_dados.php
// O QUE FAZ: Retorna os dados de um lead em formato JSON
// COMO FUNCIONA: O JavaScript chama este arquivo via fetch()
// e recebe os dados para montar o painel lateral
// ============================================================
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    http_response_code(401);
    // http_response_code() define o código HTTP da resposta
    // 401 = não autorizado
    echo json_encode(['erro' => 'Não autorizado']);
    exit();
}

require_once 'config/db.php';

// Lendo o ID da URL: lead_dados.php?id=5
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400); // 400 = requisição inválida
    echo json_encode(['erro' => 'ID inválido']);
    exit();
}

// ── Dados do lead ──
$r = mysqli_query($conexao, "SELECT * FROM leads WHERE id = $id LIMIT 1");
if (mysqli_num_rows($r) === 0) {
    http_response_code(404); // 404 = não encontrado
    echo json_encode(['erro' => 'Lead não encontrado']);
    exit();
}
$lead = mysqli_fetch_assoc($r);

// ── Histórico de contatos ──
$r2 = mysqli_query($conexao,
    "SELECT * FROM historico_contatos WHERE lead_id = $id ORDER BY data_hora DESC"
);
$historico = [];
while ($h = mysqli_fetch_assoc($r2)) {
    $historico[] = $h;
}

// ── Documentos ──
$r3 = mysqli_query($conexao,
    "SELECT * FROM documentos WHERE lead_id = $id ORDER BY criado_em DESC"
);
$documentos = [];
while ($d = mysqli_fetch_assoc($r3)) {
    // Calculando tamanho legível aqui no PHP para não precisar fazer no JS
    $bytes = (int)$d['tamanho'];
    if ($bytes >= 1048576)     $tam = round($bytes / 1048576, 1) . ' MB';
    elseif ($bytes >= 1024)    $tam = round($bytes / 1024, 1)    . ' KB';
    else                       $tam = $bytes . ' B';

    $ext = strtolower(pathinfo($d['nome_original'], PATHINFO_EXTENSION));

    $documentos[] = [
        'id'            => $d['id'],
        'nome_original' => $d['nome_original'],
        'nome_arquivo'  => $d['nome_arquivo'],
        'tamanho_fmt'   => $tam,
        'extensao'      => $ext,
        'criado_em'     => date('d/m/Y', strtotime($d['criado_em'])),
    ];
}

// ── Montando a resposta ──
// header() define o tipo de conteúdo da resposta
// application/json diz ao JS que vai receber JSON
header('Content-Type: application/json; charset=UTF-8');

// json_encode() converte o array PHP em string JSON
echo json_encode([
    'lead'      => $lead,
    'historico' => $historico,
    'documentos'=> $documentos,
], JSON_UNESCAPED_UNICODE);
// JSON_UNESCAPED_UNICODE = mantém acentos como são (ã, é, ç)
// sem isso, vira \u00e3, \u00e9 etc.

exit();