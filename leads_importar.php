<?php
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
require_once 'config/log.php';
requer_permissao('editar_leads');
$pagina_atual = 'leads';

// ── Quem está importando ──
$cp_tipo = eh_admin() ? 'admin' : 'colaborador';
$cp_id   = eh_admin() ? 'NULL' : (int)($_SESSION['colab_id'] ?? 0);

// ── Origens e status válidos ──
$origens_validas = ['Site','Instagram','Indicação','Google','Facebook','WhatsApp','LinkedIn','Email','Outro'];
$status_validos  = ['novo','em_contato','proposta_enviada','negociacao','fechado','perdido'];

$resultado = null; // só existe após o POST

// ============================================================
// AÇÃO: Processar upload do CSV
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {

    $arquivo = $_FILES['arquivo_csv'];
    $erros_upload = [];
    $importados   = 0;
    $linhas_erro  = [];
    $duplicados   = 0;

    // Validação básica do arquivo
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        $erros_upload[] = 'Erro ao receber o arquivo. Tente novamente.';
    } elseif ($arquivo['size'] > 2 * 1024 * 1024) {
        $erros_upload[] = 'Arquivo muito grande. Máximo 2MB.';
    } else {
        $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            $erros_upload[] = 'Formato inválido. Envie um arquivo .csv';
        }
    }

    if (empty($erros_upload)) {
        $handle = fopen($arquivo['tmp_name'], 'r');

        // Detecta delimitador (vírgula ou ponto-e-vírgula)
        $primeira = fgets($handle);
        rewind($handle);
        $delimitador = (substr_count($primeira, ';') >= substr_count($primeira, ',')) ? ';' : ',';

        // Lê cabeçalho
        $cabecalho = fgetcsv($handle, 0, $delimitador);
        if (!$cabecalho) {
            $erros_upload[] = 'Arquivo vazio ou inválido.';
        } else {
            // Normaliza nomes das colunas (lowercase, sem espaço)
            $cols = array_map(fn($c) => strtolower(trim(preg_replace('/\s+/', '_', $c))), $cabecalho);

            // Verifica se tem pelo menos a coluna nome
            if (!in_array('nome', $cols)) {
                $erros_upload[] = 'Coluna "nome" não encontrada. Verifique o arquivo.';
            } else {
                $linha_num = 1;
                while (($linha = fgetcsv($handle, 0, $delimitador)) !== false) {
                    $linha_num++;
                    if (count(array_filter($linha)) === 0) continue; // pula linhas vazias

                    // Monta array associativo com os dados da linha
                    $dado = [];
                    foreach ($cols as $i => $col) {
                        $dado[$col] = isset($linha[$i]) ? trim($linha[$i]) : '';
                    }

                    // ── Validações por linha ──
                    $erros_linha = [];

                    $nome = $dado['nome'] ?? '';
                    if (empty($nome)) {
                        $erros_linha[] = 'Nome obrigatório';
                    }

                    // Campos opcionais com fallback
                    $telefone = preg_replace('/[^0-9]/', '', $dado['telefone'] ?? '');
                    $email    = filter_var($dado['email'] ?? '', FILTER_VALIDATE_EMAIL)
                                ? $dado['email'] : '';
                    $origem   = '';
                    if (!empty($dado['origem'])) {
                        // Tenta casar com origem válida (case insensitive)
                        foreach ($origens_validas as $ov) {
                            if (mb_strtolower($dado['origem']) === mb_strtolower($ov)) {
                                $origem = $ov;
                                break;
                            }
                        }
                        if (empty($origem)) $origem = 'Outro'; // aceita mas marca como Outro
                    }
                    $status = 'novo';
                    if (!empty($dado['status']) && in_array(strtolower($dado['status']), $status_validos)) {
                        $status = strtolower($dado['status']);
                    }
                    $observacoes = $dado['observacoes'] ?? ($dado['obs'] ?? '');
                    $valor_raw   = str_replace(['.',',' ], ['','.'], $dado['valor'] ?? '');
                    $valor       = is_numeric($valor_raw) && $valor_raw > 0 ? (float)$valor_raw : 0;

                    if (!empty($erros_linha)) {
                        $linhas_erro[] = ['linha' => $linha_num, 'nome' => $nome ?: '—', 'erros' => $erros_linha];
                        continue;
                    }

                    // ── Verifica duplicata por telefone ──
                    if (!empty($telefone)) {
                        $tel_e = mysqli_real_escape_string($conexao, $telefone);
                        $dup = mysqli_query($conexao, "SELECT id FROM leads WHERE telefone='$tel_e' LIMIT 1");
                        if ($dup && mysqli_num_rows($dup) > 0) {
                            $duplicados++;
                            $linhas_erro[] = [
                                'linha' => $linha_num,
                                'nome'  => $nome,
                                'erros' => ['Telefone já cadastrado — lead ignorado']
                            ];
                            continue;
                        }
                    }

                    // ── INSERT ──
                    $nome_e  = mysqli_real_escape_string($conexao, $nome);
                    $tel_e   = mysqli_real_escape_string($conexao, $telefone);
                    $email_e = mysqli_real_escape_string($conexao, $email);
                    $orig_e  = mysqli_real_escape_string($conexao, $origem);
                    $obs_e   = mysqli_real_escape_string($conexao, $observacoes);

                    $sql = "INSERT INTO leads
                        (nome, telefone, email, origem, status, possivel_ganho, observacoes, criado_por_tipo, criado_por_id)
                        VALUES ('$nome_e','$tel_e','$email_e','$orig_e','$status',$valor,'$obs_e','$cp_tipo',$cp_id)";

                    if (mysqli_query($conexao, $sql)) {
                        $novo_id = mysqli_insert_id($conexao);
                        registrar_log($conexao, $novo_id, 'criou', "Lead \"$nome\" importado via CSV");
                        $importados++;
                    } else {
                        $linhas_erro[] = ['linha' => $linha_num, 'nome' => $nome, 'erros' => ['Erro ao salvar no banco']];
                    }
                }
            }
        }
        fclose($handle);
    }

    $resultado = [
        'importados'  => $importados,
        'duplicados'  => $duplicados,
        'erros_linha' => $linhas_erro,
        'erros_arq'   => $erros_upload,
    ];
}
?>
<?php $__dark = isset($_COOKIE['norion_tema']) && $_COOKIE['norion_tema'] === 'dark'; ?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?php echo $__dark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norion CRM — Importar Leads</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .import-box {
            border: 2px dashed var(--border-2);
            border-radius: var(--radius-lg);
            padding: 48px 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            background: var(--surface-2);
            position: relative;
        }
        .import-box:hover, .import-box.drag { border-color: var(--azul); background: var(--azul-light); }
        .import-box input[type=file] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        .import-ico {
            width: 48px; height: 48px; margin: 0 auto 16px;
            background: var(--azul-light); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .import-ico svg { width: 22px; height: 22px; stroke: var(--azul); fill: none; stroke-width: 2; }
        .import-titulo { font-size: 15px; font-weight: 700; color: var(--text-1); margin-bottom: 6px; }
        .import-sub    { font-size: 13px; color: var(--text-3); }
        .import-nome   { font-size: 12px; font-weight: 600; color: var(--azul); margin-top: 12px; }

        /* Resultado */
        .result-cards { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 20px; }
        .result-card  { border-radius: var(--radius-lg); padding: 18px 20px; }
        .result-num   { font-size: 32px; font-weight: 800; line-height: 1; }
        .result-label { font-size: 12px; font-weight: 500; margin-top: 4px; }

        /* Tabela de erros */
        .err-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .err-table th { text-align: left; padding: 8px 12px; font-size: 10px; font-weight: 700;
                        letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-3);
                        border-bottom: 1px solid var(--border); }
        .err-table td { padding: 8px 12px; border-bottom: 1px solid var(--border); color: var(--text-2); vertical-align: top; }
        .err-table tr:last-child td { border-bottom: none; }
        .err-badge { display: inline-block; background: var(--vermelho-light); color: var(--vermelho-text);
                     font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 4px; }

        /* Modelo CSV */
        .modelo-col { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
        .modelo-pill { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
                       border: 1px solid var(--border-2); color: var(--text-2); background: var(--surface); }
        .modelo-pill.obrig { background: var(--azul-light); border-color: var(--azul-mid); color: var(--azul); }

        @media(max-width:600px){ .result-cards { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <span class="topbar-titulo">
            <a href="leads.php" style="color:var(--text-3);text-decoration:none;">Leads</a>
            <span style="color:var(--text-3);margin:0 6px;">/</span>
            Importar via CSV
        </span>
        <div class="topbar-acoes">
            <a href="leads.php" class="btn btn-ghost">← Voltar</a>
        </div>
    </div>

    <div class="page-content" style="max-width:760px;">

        <?php if ($resultado !== null): ?>
        <!-- ── RESULTADO DA IMPORTAÇÃO ── -->

        <?php if (!empty($resultado['erros_arq'])): ?>
        <div class="alert alert-error" style="margin-bottom:20px;">
            <?php foreach ($resultado['erros_arq'] as $e): ?>
            <div><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>

        <!-- Cards de resumo -->
        <div class="result-cards">
            <div class="result-card" style="background:var(--verde-light);">
                <div class="result-num" style="color:var(--verde);"><?php echo $resultado['importados']; ?></div>
                <div class="result-label" style="color:var(--verde-text);">Importados com sucesso</div>
            </div>
            <div class="result-card" style="background:var(--amarelo-light);">
                <div class="result-num" style="color:var(--amarelo);"><?php echo $resultado['duplicados']; ?></div>
                <div class="result-label" style="color:var(--amarelo-text);">Duplicados ignorados</div>
            </div>
            <div class="result-card" style="background:<?php echo count($resultado['erros_linha']) > 0 ? 'var(--vermelho-light)' : 'var(--cinza-light)'; ?>;">
                <div class="result-num" style="color:<?php echo count($resultado['erros_linha']) > 0 ? 'var(--vermelho)' : 'var(--text-3)'; ?>;">
                    <?php echo count($resultado['erros_linha']); ?>
                </div>
                <div class="result-label" style="color:<?php echo count($resultado['erros_linha']) > 0 ? 'var(--vermelho-text)' : 'var(--text-3)'; ?>;">
                    Linhas com erro
                </div>
            </div>
        </div>

        <!-- Ações pós-importação -->
        <div style="display:flex;gap:10px;margin-bottom:24px;">
            <?php if ($resultado['importados'] > 0): ?>
            <a href="leads.php" class="btn btn-primary">Ver leads importados</a>
            <?php endif; ?>
            <a href="leads_importar.php" class="btn btn-ghost">Importar outro arquivo</a>
        </div>

        <!-- Tabela de erros/avisos -->
        <?php if (!empty($resultado['erros_linha'])): ?>
        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;">
                Linhas com problemas
            </div>
            <div style="overflow-x:auto;">
                <table class="err-table">
                    <thead>
                        <tr>
                            <th>Linha</th>
                            <th>Nome</th>
                            <th>Problema</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($resultado['erros_linha'] as $err): ?>
                    <tr>
                        <td style="color:var(--text-3);">#<?php echo $err['linha']; ?></td>
                        <td style="font-weight:600;color:var(--text-1);"><?php echo htmlspecialchars($err['nome']); ?></td>
                        <td>
                            <?php foreach ($err['erros'] as $e): ?>
                            <span class="err-badge"><?php echo htmlspecialchars($e); ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; // fim erros_arq ?>

        <?php else: ?>
        <!-- ── FORMULÁRIO DE UPLOAD ── -->

        <div class="card" style="margin-bottom:16px;">
            <div style="font-size:13px;font-weight:700;color:var(--text-1);margin-bottom:4px;">Colunas aceitas no CSV</div>
            <div style="font-size:12px;color:var(--text-3);margin-bottom:10px;">
                A coluna <strong>nome</strong> é obrigatória. As demais são opcionais.
                O sistema aceita vírgula (,) ou ponto-e-vírgula (;) como separador.
            </div>
            <div class="modelo-col">
                <span class="modelo-pill obrig">nome *</span>
                <span class="modelo-pill">telefone</span>
                <span class="modelo-pill">email</span>
                <span class="modelo-pill">origem</span>
                <span class="modelo-pill">status</span>
                <span class="modelo-pill">valor</span>
                <span class="modelo-pill">observacoes</span>
            </div>
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:6px;">Exemplo de CSV:</div>
                <pre style="font-size:11px;color:var(--text-3);background:var(--surface-2);padding:10px 14px;border-radius:var(--radius);overflow-x:auto;border:1px solid var(--border);line-height:1.7;">nome,telefone,email,origem,valor
João Silva,11999991234,joao@email.com,Instagram,5000
Maria Souza,11988882345,,Site,
Carlos Lima,11977773456,carlos@empresa.com,Google,12000</pre>
            </div>
            <!-- Download de modelo -->
            <div style="margin-top:10px;">
                <a id="btn-modelo" href="#" onclick="baixarModelo(event)"
                   style="font-size:12px;font-weight:600;color:var(--azul);text-decoration:none;">
                    ↓ Baixar modelo CSV
                </a>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data">
            <div class="import-box" id="drop-zone">
                <input type="file" name="arquivo_csv" accept=".csv,.txt" id="file-input"
                       onchange="mostrarNome(this)">
                <div class="import-ico">
                    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div class="import-titulo">Arraste o arquivo aqui ou clique para selecionar</div>
                <div class="import-sub">Arquivos .csv — máximo 2MB</div>
                <div class="import-nome" id="nome-arquivo"></div>
            </div>

            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                <button type="submit" class="btn btn-primary" id="btn-importar" disabled>
                    Importar leads
                </button>
                <a href="leads.php" class="btn btn-ghost">Cancelar</a>
                <span id="msg-selecione" style="font-size:12px;color:var(--text-3);">
                    Selecione um arquivo para continuar
                </span>
            </div>
        </form>

        <?php endif; ?>

    </div>
</div>

<script>
function mostrarNome(input) {
    var nome = input.files[0] ? input.files[0].name : '';
    document.getElementById('nome-arquivo').textContent = nome;
    document.getElementById('btn-importar').disabled = !nome;
    document.getElementById('msg-selecione').style.display = nome ? 'none' : 'inline';
}

// Drag and drop
var zone = document.getElementById('drop-zone');
if (zone) {
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', function()  { zone.classList.remove('drag'); });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('drag');
        var file = e.dataTransfer.files[0];
        if (!file) return;
        var input = document.getElementById('file-input');
        // Transfere o arquivo para o input
        var dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        mostrarNome(input);
    });
}

// Baixar modelo CSV
function baixarModelo(e) {
    e.preventDefault();
    var csv = 'nome,telefone,email,origem,valor,observacoes\n'
            + 'João Silva,11999991234,joao@email.com,Instagram,5000,Cliente interessado\n'
            + 'Maria Souza,11988882345,,Site,,\n'
            + 'Carlos Lima,11977773456,carlos@empresa.com,Google,12000,Veio pelo blog\n';
    var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = 'modelo_leads_norion.csv';
    a.click(); URL.revokeObjectURL(url);
}
</script>
</body>
</html>