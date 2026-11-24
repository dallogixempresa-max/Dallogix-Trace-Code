<?php
declare(strict_types=1);

// O snapshot do central chega a cada ~1 s dentro de uma transação que também
// pega carregamentos. Escrever na Dala (UPDATE equipamentos) e refazer bcrypt +
// upsert em dispositivos a cada ciclo segurava os locks que as paradas
// automáticas precisam (deadlock). Sem mudança não há escrita; token novo no
// .env, dispositivo inativo ou revogado continuam sendo reprovisionados.

require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioFilaSincronizacao.php";
require_once __DIR__ . "/../servidor/src/Persistencia/RepositorioEquipamentosRemotos.php";
require_once __DIR__ . "/../servidor/src/Aplicacao/ProvisionadorDispositivosLocais.php";

use App\Aplicacao\ProvisionadorDispositivosLocais;
use App\Persistencia\RepositorioEquipamentosRemotos;
use App\Persistencia\RepositorioFilaSincronizacao;

function verificar(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** SQLite com o upsert do MySQL traduzido e contagem das escritas por tabela. */
final class PdoContaEscritas extends PDO
{
    /** @var array<string,int> */
    public array $escritas = [];

    public function __construct()
    {
        parent::__construct("sqlite::memory:", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Como o pdo_mysql com prepares emulados: inteiros chegam como texto.
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [EscritaStatement::class, [$this]]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(
            ["ON DUPLICATE KEY UPDATE", "NOW()"],
            ["ON CONFLICT(device_code) DO UPDATE SET", "CURRENT_TIMESTAMP"],
            $query,
        );
        $query = (string) preg_replace('/\bVALUES\((\w+)\)/', 'excluded.$1', $query);
        return parent::prepare($query, $options);
    }

    public function zerar(): void
    {
        $this->escritas = [];
    }

    public function escritas(string $prefixo): int
    {
        return $this->escritas[$prefixo] ?? 0;
    }
}

class EscritaStatement extends PDOStatement
{
    protected function __construct(private readonly PdoContaEscritas $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        if (preg_match('/\A\s*(UPDATE\s+\w+|INSERT\s+INTO\s+\w+)/i', $this->queryString, $match) === 1) {
            $prefixo = (string) preg_replace('/\s+/', " ", strtoupper($match[1]));
            $this->pdo->escritas[$prefixo] = ($this->pdo->escritas[$prefixo] ?? 0) + 1;
        }
        return parent::execute($params);
    }
}

$pdo = new PdoContaEscritas();
$pdo->exec("CREATE TABLE equipamentos (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, remote_equipment_id INTEGER, equipment_code TEXT NOT NULL, name TEXT NOT NULL, plc_ip TEXT, plc_port INTEGER, external_port INTEGER, plc_protocol TEXT NOT NULL DEFAULT 'MODBUS_TCP')");
$pdo->exec("CREATE TABLE fila_sincronizacao (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, aggregate_type TEXT NOT NULL, aggregate_id INTEGER NOT NULL, status TEXT NOT NULL, payload TEXT NOT NULL DEFAULT '{}')");
$pdo->exec("CREATE TABLE dispositivos (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, equipment_id INTEGER NOT NULL, device_code TEXT NOT NULL UNIQUE, device_type TEXT NOT NULL, token_hash TEXT NOT NULL, token_lookup_hash TEXT UNIQUE, token_id TEXT UNIQUE, token_created_at TEXT, token_last_used_at TEXT, token_expires_at TEXT, token_revoked_at TEXT, active INTEGER NOT NULL DEFAULT 1, last_seen_at TEXT, UNIQUE (equipment_id, device_type))");

$repositorio = new RepositorioEquipamentosRemotos($pdo, new RepositorioFilaSincronizacao($pdo));
$remota = [
    "id" => 40, "equipment_code" => "DALA-01", "name" => "Dala 01",
    "plc_ip" => "192.168.0.10", "plc_port" => 502, "external_port" => null, "plc_protocol" => "MODBUS_TCP",
];
$dala = static fn (): array => $pdo->query("SELECT remote_equipment_id, equipment_code, name, plc_ip, plc_port, external_port, plc_protocol FROM equipamentos")->fetch();

// 1) Primeira sincronização cria a Dala e pede provisionamento.
$primeira = $repositorio->sincronizarEquipamento(7, $remota);
verificar($pdo->escritas("INSERT INTO EQUIPAMENTOS") === 1, "A Dala nova deveria ser inserida.");
verificar($primeira["provisionar"] === true, "Dala nova deve ser provisionada.");
$localId = $primeira["id"];

// 2) Mesmo snapshot: nenhuma escrita (portas chegam do banco como texto).
$pdo->zerar();
$repetida = $repositorio->sincronizarEquipamento(7, $remota);
verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 0, "Snapshot sem mudança não pode escrever na Dala.");
verificar($repetida === ["id" => $localId, "codigo" => "DALA-01", "provisionar" => true], "O provisionamento ainda é conferido a cada ciclo.");

// 3) Diferenças só de forma também não escrevem: porta como texto, IP vazio
// contra NULL, porta externa vazia e protocolo vazio (padrão MODBUS_TCP).
$pdo->exec("UPDATE equipamentos SET plc_ip = NULL");
$pdo->zerar();
$repositorio->sincronizarEquipamento(7, ["plc_port" => "502", "plc_ip" => "", "external_port" => "", "plc_protocol" => ""] + $remota);
verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 0, "Diferença só de forma não pode escrever na Dala.");
$pdo->zerar();
$repositorio->sincronizarEquipamento(7, ["plc_protocol" => "DESCONHECIDO", "plc_ip" => null] + $remota);
verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 0, "Protocolo inválido equivale ao padrão já gravado.");

// 4) Mudança real no central é gravada (e só uma vez).
foreach ([
    ["name" => "Dala 01 - Norte"],
    ["plc_ip" => "192.168.0.11"],
    ["plc_port" => 503],
    ["external_port" => 1502],
    ["plc_protocol" => "MODBUS_RTU"],
] as $mudanca) {
    $pdo->zerar();
    $repositorio->sincronizarEquipamento(7, $mudanca + $remota);
    verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 1, "Mudança em " . array_key_first($mudanca) . " deveria ser gravada.");
    $gravado = $dala()[array_key_first($mudanca)];
    verificar((string) $gravado === (string) reset($mudanca), "O valor de " . array_key_first($mudanca) . " deveria vir do central.");
    $pdo->zerar();
    $repositorio->sincronizarEquipamento(7, $mudanca + $remota);
    verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 0, "Depois de gravada, a mesma mudança não escreve de novo.");
    $repositorio->sincronizarEquipamento(7, $remota);
}
verificar($dala() === ["remote_equipment_id" => "40", "equipment_code" => "DALA-01", "name" => "Dala 01", "plc_ip" => "192.168.0.10", "plc_port" => "502", "external_port" => null, "plc_protocol" => "MODBUS_TCP"], "A Dala deveria voltar à configuração do central.");

// 5) Dala vinculada pelo código (sem remote_equipment_id) recebe o vínculo.
$pdo->exec("UPDATE equipamentos SET remote_equipment_id = NULL");
$pdo->zerar();
$repositorio->sincronizarEquipamento(7, $remota);
verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 1 && $dala()["remote_equipment_id"] === "40", "Dala achada pelo código deve receber o id remoto.");

// 6) Configuração local pendente: não sobrescreve, não provisiona e só grava
// o vínculo remoto quando ele falta.
$pdo->exec("INSERT INTO fila_sincronizacao (company_id, aggregate_type, aggregate_id, status) VALUES (7, 'equipment', {$localId}, 'PENDENTE')");
$pdo->zerar();
$pendente = $repositorio->sincronizarEquipamento(7, ["name" => "Nome do central"] + $remota);
verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 0 && $pendente["provisionar"] === false, "Com edição local pendente e vínculo certo, nada é escrito.");
verificar($dala()["name"] === "Dala 01", "A edição local pendente não pode ser sobrescrita.");
$pdo->exec("UPDATE equipamentos SET remote_equipment_id = NULL");
$pdo->zerar();
$repositorio->sincronizarEquipamento(7, $remota);
verificar($pdo->escritas("UPDATE EQUIPAMENTOS") === 1 && $dala()["remote_equipment_id"] === "40", "Com edição local pendente, só o vínculo remoto que falta é gravado.");
$pdo->exec("DELETE FROM fila_sincronizacao");

// 7) Provisionamento: só reescreve dispositivos quando a credencial muda.
$tokenClp = str_repeat("a1", 32);
$tokenKeyence = str_repeat("b2", 32);
putenv("TRACE_INSTALLATION_MODE=local");
putenv("TRACE_DEVICE_TOKEN={$tokenClp}");
putenv("KEYENCE_ENABLED=1");
putenv("KEYENCE_ARCHIVE_ONLY=0");
putenv("KEYENCE_DEVICE_TOKEN={$tokenKeyence}");
$dispositivo = static function (string $tipo) use ($pdo): array {
    $statement = $pdo->prepare("SELECT * FROM dispositivos WHERE device_type = ?");
    $statement->execute([$tipo]);
    return $statement->fetch() ?: [];
};
// Mesma consulta da API (require_device_token): autentica pelo hash de busca.
$autentica = static function (string $token) use ($pdo): ?string {
    $statement = $pdo->prepare("SELECT device_type FROM dispositivos WHERE active = 1 AND token_lookup_hash = ? AND token_revoked_at IS NULL AND token_expires_at IS NULL");
    $statement->execute([hash("sha256", $token)]);
    $tipo = $statement->fetchColumn();
    return $tipo === false ? null : (string) $tipo;
};

$pdo->zerar();
ProvisionadorDispositivosLocais::garantir($pdo, 7, $localId, "DALA-01");
verificar($pdo->escritas("INSERT INTO DISPOSITIVOS") === 2, "Primeiro ciclo provisiona CLP e Keyence.");
verificar($autentica($tokenClp) === "CLP" && $autentica($tokenKeyence) === "SCANNER", "Os tokens do .env devem autenticar.");
verificar(password_verify($tokenClp, (string) $dispositivo("CLP")["token_hash"]), "O hash bcrypt continua sendo gravado.");

$pdo->exec("UPDATE dispositivos SET token_last_used_at = '2026-11-24 10:00:00', last_seen_at = '2026-11-24 10:00:00'");
$antes = $dispositivo("CLP");
$pdo->zerar();
ProvisionadorDispositivosLocais::garantir($pdo, 7, $localId, "DALA-01");
verificar($pdo->escritas("INSERT INTO DISPOSITIVOS") === 0, "Credenciais iguais às do .env não são reescritas.");
verificar($dispositivo("CLP") === $antes, "Sem mudança, token_id, hash e último uso ficam como estão.");

// .env com token novo do CLP: reprovisiona só o CLP e o token antigo deixa de valer.
$tokenClpNovo = str_repeat("c3", 32);
putenv("TRACE_DEVICE_TOKEN={$tokenClpNovo}");
$pdo->zerar();
ProvisionadorDispositivosLocais::garantir($pdo, 7, $localId, "DALA-01");
verificar($pdo->escritas("INSERT INTO DISPOSITIVOS") === 1, "Token novo no .env deve reprovisionar o CLP.");
verificar($autentica($tokenClpNovo) === "CLP" && $autentica($tokenClp) === null, "Só o token novo do CLP deve autenticar.");
verificar($autentica($tokenKeyence) === "SCANNER", "A Keyence não pode perder a credencial.");
verificar($dispositivo("CLP")["token_id"] !== $antes["token_id"], "A rotação gera um token_id novo.");

// Dispositivo desativado, revogado ou com validade: o ciclo restaura a credencial.
foreach ([
    "UPDATE dispositivos SET active = 0 WHERE device_type = 'SCANNER'",
    "UPDATE dispositivos SET token_revoked_at = '2026-11-24 09:00:00' WHERE device_type = 'SCANNER'",
    "UPDATE dispositivos SET token_expires_at = '2099-01-01 00:00:00' WHERE device_type = 'SCANNER'",
] as $alteracao) {
    $pdo->exec($alteracao);
    $pdo->zerar();
    ProvisionadorDispositivosLocais::garantir($pdo, 7, $localId, "DALA-01");
    verificar($pdo->escritas("INSERT INTO DISPOSITIVOS") === 1, "Credencial alterada fora do .env deve ser reprovisionada: {$alteracao}");
    verificar($autentica($tokenKeyence) === "SCANNER", "A Keyence deve voltar a autenticar: {$alteracao}");
}

// Token inválido no .env continua sendo recusado antes de qualquer atalho.
putenv("TRACE_DEVICE_TOKEN=curto");
$recusado = false;
try {
    ProvisionadorDispositivosLocais::garantir($pdo, 7, $localId, "DALA-01");
} catch (RuntimeException) {
    $recusado = true;
}
verificar($recusado, "Token inválido no .env deve continuar falhando.");

echo "Sincronização da Dala: sem escrita quando nada mudou; credencial nova ou alterada é reprovisionada.\n";
