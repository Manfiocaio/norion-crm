<?php
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
require_once 'config/log.php';

// Retorna JSON — nunca redireciona
header('Content-Type: application/json; charset=UTF-8');

// Verifica login sem redirecionar
if (!esta_logado()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autorizado']);
    exit();
}

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'ID inválido']);
    exit();
}

// ── Lead ──
$r = mysqli_query($conexao, "SELECT * FROM leads WHERE id = $id LIMIT 1");
if (!$r || mysqli_num_rows($r) === 0) {
    http_response_code(404);
    echo json_encode(['erro' => 'Lead não encontrado']);
    exit();
}
$lead = mysqli_fetch_assoc($r);

// ── Histórico de contatos ──
$historico = [];
$r2 = mysqli_query($conexao,
    "SELECT * FROM historico_contatos WHERE lead_id = $id ORDER BY data_hora DESC"
);
while ($h = mysqli_fetch_assoc($r2)) { $historico[] = $h; }

// ── Documentos ──
$documentos = [];
$r3 = mysqli_query($conexao,
    "SELECT * FROM documentos WHERE lead_id = $id ORDER BY criado_em DESC"
);
while ($d = mysqli_fetch_assoc($r3)) {
    $bytes = (int)$d['tamanho'];
    if ($bytes >= 1048576)  $tam = round($bytes/1048576, 1) . ' MB';
    elseif ($bytes >= 1024) $tam = round($bytes/1024, 1)    . ' KB';
    else                    $tam = $bytes . ' B';
    $ext = strtolower(pathinfo($d['nome_original'], PATHINFO_EXTENSION));
    $documentos[] = [
        'id'           => $d['id'],
        'nome_original'=> $d['nome_original'],
        'nome_arquivo' => $d['nome_arquivo'],
        'tamanho_fmt'  => $tam,
        'extensao'     => $ext,
        'criado_em'    => date('d/m/Y', strtotime($d['criado_em'])),
    ];
}

// ── Log de atividades ──
// Verifica se a tabela existe antes de consultar
$log = [];
$tabela_ok = mysqli_query($conexao, "SHOW TABLES LIKE 'log_atividades'");
if ($tabela_ok && mysqli_num_rows($tabela_ok) > 0) {
    $r4 = mysqli_query($conexao,
        "SELECT usuario, acao, detalhe, criado_em FROM log_atividades
         WHERE lead_id = $id ORDER BY criado_em DESC LIMIT 30"
    );
    if ($r4) {
        while ($entry = mysqli_fetch_assoc($r4)) {
            $dt   = new DateTime($entry['criado_em']);
            $ago  = new DateTime();
            $diff = $ago->diff($dt);
            if ($diff->days === 0)     $quando = 'Hoje às '     . $dt->format('H:i');
            elseif ($diff->days === 1) $quando = 'Ontem às '    . $dt->format('H:i');
            elseif ($diff->days < 7)  $quando = 'Há ' . $diff->days . ' dias';
            else                       $quando = $dt->format('d/m/Y H:i');

            $cor = cor_log($entry['acao']);
            $log[] = [
                'usuario' => $entry['usuario'],
                'acao'    => $entry['acao'],
                'texto'   => texto_acao($entry['acao']),
                'detalhe' => $entry['detalhe'],
                'quando'  => $quando,
                'bg'      => $cor['bg'],
                'fg'      => $cor['fg'],
            ];
        }
    }
}

echo json_encode([
    'lead'      => $lead,
    'historico' => $historico,
    'documentos'=> $documentos,
    'log'       => $log,
], JSON_UNESCAPED_UNICODE);
exit();