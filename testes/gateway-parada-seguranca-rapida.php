<?php
declare(strict_types=1);

// Teste de bancada de 24/11/2026 (carregamentos 142 e 143, v1.0.281): as
// paradas por produto incorreto ou acima da quantidade nascem da leitura e
// esperavam a consulta de comandos de 2 s (reserva 0,2 a 2,0 s depois do
// pedido). A saca seguinte passava antes de a esteira parar e entrava na conta.
// A consulta longa do node-red reserva só a pausa de segurança; sem ela
// pendente, lê sem trava, sem abrir transação nem travar a Dala.

require_once __DIR__ . "/../servidor/src/Aplicacao/ServicoGatewayClp.php";

use App\Aplicacao\ServicoGatewayClp;

$GLOBALS["auditados"] = [];

function record_operational_event(PDO $connection, array $user, string $action, string $entityType, int $entityId, array $payload = []): void
{
    $GLOBALS["auditados"][] = ["action" => $action, "entity_type" => $entityType, "entity_id" => $entityId, "data" => $payload];
}

function enfileirar_evento_sincronizacao(PDO $connection, array $user, string $action, string $entityType, int $entityId, array $payload = []): void
{
}

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** SQLite com o dialeto MySQL do gateway; conta as transações e guarda o SQL. */
final class PdoGatewayRapido extends PDO
{
    public int $transacoes = 0;
    /** @var list<string> */
    public array $consultas = [];

    public function beginTransaction(): bool
    {
        $this->transacoes++;
        return parent::beginTransaction();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->consultas[] = $query;
        $query = str_replace(
            [" FOR UPDATE OF r SKIP LOCKED", " FOR UPDATE SKIP LOCKED", " FOR UPDATE", "DATE_ADD(NOW(3), INTERVAL 2 MINUTE)", "NOW(3)"],
            ["", "", "", "datetime('now', '+2 minutes')", "CURRENT_TIMESTAMP"],
            $query,
        );
        return parent::prepare($query, $options);
    }
}

$pdo = new PdoGatewayRapido("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_carregamento_id INTEGER, state TEXT NOT NULL, stop_reason TEXT, operator_alert TEXT)");
$pdo->exec("CREATE TABLE equipamentos (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, remote_equipment_id INTEGER, equipment_code TEXT)");
$pdo->exec("CREATE TABLE solicitacoes_comandos_clp (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, equipment_id INTEGER NOT NULL, carregamento_id INTEGER NOT NULL, command TEXT NOT NULL, status TEXT NOT NULL, requested_at TEXT NOT NULL, claimed_at TEXT, claimed_by_device_id INTEGER, expires_at TEXT, completed_at TEXT, response_message TEXT, remote_command_id INTEGER)");
$pdo->exec("INSERT INTO equipamentos (id, company_id, remote_equipment_id, equipment_code) VALUES (1, 1, 11, 'DALA-01')");

$gateway = new ServicoGatewayClp($pdo);
$segundo = 0;
$preparar = static function (string $state, ?string $stopReason) use ($pdo): void {
    $pdo->exec("DELETE FROM carregamentos");
    $pdo->exec("DELETE FROM solicitacoes_comandos_clp");
    $pdo->prepare("INSERT INTO carregamentos (id, company_id, remote_carregamento_id, state, stop_reason) VALUES (142, 1, 128, ?, ?)")
        ->execute([$state, $stopReason]);
};
$pedir = static function (string $command, string $status = "PENDENTE", ?string $expiresAt = null) use ($pdo, &$segundo): int {
    $segundo++;
    $pdo->prepare(
        "INSERT INTO solicitacoes_comandos_clp (company_id, equipment_id, carregamento_id, command, status, requested_at, claimed_by_device_id, expires_at)
         VALUES (1, 1, 142, ?, ?, ?, ?, ?)",
    )->execute([$command, $status, sprintf("2026-11-24 19:24:%02d.000", $segundo), $status === "PROCESSANDO" ? 7 : null, $expiresAt]);
    return (int) $pdo->lastInsertId();
};
$status = static function (int $id) use ($pdo): string {
    $statement = $pdo->prepare("SELECT status FROM solicitacoes_comandos_clp WHERE id = ?");
    $statement->execute([$id]);
    return (string) $statement->fetchColumn();
};

// 1) Carga rodando, nada pendente: a consulta rápida não abre transação.
$preparar("CARREGANDO", null);
$antes = $pdo->transacoes;
verificar($gateway->reservarComando(1, 7, null, true) === null, "Sem parada pendente, a consulta rápida responde 204.");
verificar($gateway->aguardarParadaDeSeguranca(1, 7, 0) === null, "Sem parada pendente, a consulta longa responde 204.");
verificar($pdo->transacoes === $antes, "Sem parada pendente, a consulta rápida não pode travar a Dala.");

// 2) Ligar a esteira e a parada manual ficam com a consulta normal de 2 s.
$iniciar = $pedir("INICIAR_CARREGAMENTO");
verificar($gateway->aguardarParadaDeSeguranca(1, 7, 0) === null, "A consulta rápida não pode ligar a esteira.");
verificar($status($iniciar) === "PENDENTE", "O INICIAR continua pendente para a consulta normal.");
$pdo->exec("DELETE FROM solicitacoes_comandos_clp");
$manual = $pedir("PAUSAR_CARREGAMENTO");
verificar($gateway->aguardarParadaDeSeguranca(1, 7, 0) === null, "A parada manual segue na consulta normal.");
verificar($status($manual) === "PENDENTE", "A parada manual continua pendente.");
verificar($gateway->reservarComando(1, 7)["id"] === $manual, "A consulta normal continua reservando a parada manual.");

// 3) Parada da leitura (produto acima da quantidade) e da meta: reservadas pela consulta rápida.
foreach (["PRODUTO_INCORRETO", "COTA_ROMANEIO_ATINGIDA", "COTA_PRODUTO_ATINGIDA", "EXCESSO"] as $motivo) {
    $preparar("PAUSADO", $motivo);
    $parada = $pedir("PAUSAR_CARREGAMENTO");
    $reservado = $gateway->aguardarParadaDeSeguranca(1, 7, 0);
    verificar(($reservado["id"] ?? null) === $parada, "A pausa por {$motivo} deve sair pela consulta rápida.");
    verificar(($reservado["command"] ?? null) === "PAUSAR_CARREGAMENTO", "A consulta rápida só entrega pausa.");
    verificar(($reservado["loading_state"] ?? null) === "PAUSADO" && ($reservado["stop_reason"] ?? null) === $motivo, "O portão do node-red recebe estado e motivo.");
    verificar($status($parada) === "PROCESSANDO", "A pausa por {$motivo} fica reservada para a escrita.");
    // Com a escrita em andamento, nenhuma consulta reserva de novo nem abre transação.
    $antes = $pdo->transacoes;
    verificar($gateway->aguardarParadaDeSeguranca(1, 7, 0) === null, "A pausa em escrita não é reservada duas vezes.");
    verificar($pdo->transacoes === $antes, "Com comando em escrita, a consulta rápida não trava a Dala.");
}

// 4) Pausa de segurança à frente de um INICIAR antigo ainda pendente.
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$iniciarAntigo = $pedir("INICIAR_CARREGAMENTO");
$parada = $pedir("PAUSAR_CARREGAMENTO");
verificar(($gateway->aguardarParadaDeSeguranca(1, 7, 0)["id"] ?? null) === $parada, "A pausa de segurança passa à frente do INICIAR antigo.");
verificar($status($iniciarAntigo) === "PENDENTE", "O INICIAR antigo não é entregue pela consulta rápida.");

// 5) A reserva rápida não espera outras travas: aposentados e vencidos ficam com a consulta de 2 s.
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$aposentado = $pedir("EMERGENCIA");
$parada = $pedir("PAUSAR_CARREGAMENTO");
$pdo->consultas = [];
verificar(($gateway->aguardarParadaDeSeguranca(1, 7, 0)["id"] ?? null) === $parada, "A pausa sai mesmo com comando aposentado na fila.");
verificar($status($aposentado) === "PENDENTE", "A reserva rápida não roda o UPDATE dos aposentados.");
$sqlRapido = implode("\n", $pdo->consultas);
verificar(!str_contains($sqlRapido, "status = 'REJEITADO'"), "Nenhum UPDATE bloqueante depois de travar a Dala.");
verificar(!str_contains($sqlRapido, "expires_at < "), "A varredura de vencidos fica fora da reserva rápida.");
verificar(str_contains($sqlRapido, "FOR UPDATE OF r SKIP LOCKED"), "A reserva rápida trava só a solicitação.");
verificar($gateway->reservarComando(1, 7) === null && $status($aposentado) === "REJEITADO", "A consulta normal continua aposentando o comando removido.");

$preparar("PAUSADO", "PRODUTO_INCORRETO");
$vencido = $pedir("INICIAR_CARREGAMENTO", "PROCESSANDO", "2000-01-01 00:00:00");
$parada = $pedir("PAUSAR_CARREGAMENTO");
verificar($gateway->aguardarParadaDeSeguranca(1, 7, 0) === null, "Com escrita vencida ainda PROCESSANDO, a consulta rápida não reserva.");
verificar($status($vencido) === "PROCESSANDO", "Só a consulta normal expira o comando vencido.");
$reservadoNormal = $gateway->reservarComando(1, 7);
verificar($status($vencido) === "ERRO" && ($reservadoNormal["id"] ?? null) === $parada, "A consulta normal expira o vencido e entrega a pausa.");

// 6) Sem pausa, a consulta longa espera o tempo pedido; com pausa, responde na hora.
$preparar("CARREGANDO", null);
$inicio = hrtime(true);
verificar($gateway->aguardarParadaDeSeguranca(1, 7, 250, 50) === null, "Sem pausa, a consulta longa termina sem reservar.");
$esperaMs = (hrtime(true) - $inicio) / 1_000_000;
verificar($esperaMs >= 240 && $esperaMs < 1500, "A consulta longa espera cerca de 250 ms (esperou {$esperaMs} ms).");
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$parada = $pedir("PAUSAR_CARREGAMENTO");
$inicio = hrtime(true);
verificar(($gateway->aguardarParadaDeSeguranca(1, 7, 1500)["id"] ?? null) === $parada, "Com pausa pendente, a consulta longa reserva.");
verificar((hrtime(true) - $inicio) / 1_000_000 < 200, "Com pausa pendente, a consulta longa não espera.");

// 7) Prazo do node-red vencido (requisição atrasada na fila do PHP, depois de o
// node-red desistir em 3,5 s): não reserva, a pausa fica PENDENTE.
$agora = 1_795_548_274_000;
verificar(ServicoGatewayClp::prazoDoCliente($agora + 1500, $agora) === $agora + 1500, "Prazo normal vale.");
verificar(ServicoGatewayClp::prazoDoCliente($agora - 12_000, $agora) === $agora - 12_000, "Prazo vencido há mais de 10 s continua vencido (fila longa do PHP).");
verificar(ServicoGatewayClp::prazoDoCliente($agora + 10_001, $agora) === null, "Prazo distante no futuro é ignorado.");
verificar(ServicoGatewayClp::prazoDoCliente("abc", $agora) === null && ServicoGatewayClp::prazoDoCliente(null, $agora) === null, "Prazo inválido é ignorado.");
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$parada = $pedir("PAUSAR_CARREGAMENTO");
$agoraMs = (int) round(microtime(true) * 1000);
verificar($gateway->aguardarParadaDeSeguranca(1, 7, 1500, 100, $agoraMs - 1) === null, "Com o prazo do cliente vencido, não reserva.");
verificar($status($parada) === "PENDENTE", "A pausa continua pendente depois do prazo vencido.");
$inicio = hrtime(true);
verificar(($gateway->aguardarParadaDeSeguranca(1, 7, 1500, 100, $agoraMs + 5000)["id"] ?? null) === $parada, "Dentro do prazo, reserva.");
verificar((hrtime(true) - $inicio) / 1_000_000 < 200, "Dentro do prazo, não espera.");
$preparar("CARREGANDO", null);
$inicio = hrtime(true);
$prazo = (int) round(microtime(true) * 1000) + 200;
verificar($gateway->aguardarParadaDeSeguranca(1, 7, 1500, 50, $prazo) === null, "Sem pausa, termina no prazo do cliente.");
$esperaMs = (hrtime(true) - $inicio) / 1_000_000;
verificar($esperaMs >= 150 && $esperaMs < 1000, "O prazo do cliente encurta a espera de 1,5 s (esperou {$esperaMs} ms).");

// 8) A reserva rápida não espera a Dala ocupada; a consulta normal continua esperando.
$preparar("PAUSADO", "PRODUTO_INCORRETO");
$pedir("PAUSAR_CARREGAMENTO");
$pdo->consultas = [];
$gateway->aguardarParadaDeSeguranca(1, 7, 0);
verificar(in_array("SELECT id FROM equipamentos WHERE id = :equipment_id LIMIT 1 FOR UPDATE SKIP LOCKED", $pdo->consultas, true), "A reserva rápida pula a Dala ocupada.");
$pdo->consultas = [];
$gateway->reservarComando(1, 7);
verificar(in_array("SELECT id FROM equipamentos WHERE id = :equipment_id LIMIT 1 FOR UPDATE", $pdo->consultas, true), "A consulta normal continua esperando a Dala.");

echo "gateway-parada-seguranca-rapida: ok\n";
