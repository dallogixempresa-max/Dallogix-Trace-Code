<?php
declare(strict_types=1);
// Executa o endpoint real com transporte e PDO controlados, sem servidor ou CLP.
$scenario=json_decode($argv[1]??'{}',true);
require_once __DIR__.'/../../servidor/src/Aplicacao/ValidadorParadaCarregamento.php';
$events=[];
final class FinishStatement extends PDOStatement {
    public function __construct(private string $sql) {}
    public function execute(?array $params=null): bool { return true; }
    public function rowCount(): int { return 1; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
        global $scenario;
        return ['id'=>42,'equipment_id'=>3,'state'=>$scenario['state']??'PAUSADO','stop_reason'=>'COTA_ROMANEIO_ATINGIDA','romaneio_id'=>10,'truck_id'=>5,'romaneio_number'=>'ROM-10','romaneio_status'=>'EM_ANDAMENTO'];
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        return [['product_id'=>1,'planned_quantity'=>2,'moved_quantity'=>0,'name'=>'Produto']];
    }
    public function fetchColumn(int $column=0): mixed {
        global $scenario;
        if(str_contains($this->sql,'FROM logs_auditoria')) return $scenario['stopApplied']??1;
        if(str_contains($this->sql,'FROM solicitacoes_comandos_clp')) return $scenario['pendingCommands']??0;
        if(str_contains($this->sql,'SELECT COUNT(*) FROM carregamentos c')) return 0;
        if(str_contains($this->sql,'SUM(ri.planned_quantity)')) return $scenario['planned']??2;
        if(str_contains($this->sql,'COUNT(*) FROM eventos_sensor')) return $scenario['detected']??2;
        return 0;
    }
}
final class FinishConnection extends PDO {
    private bool $transaction=false;
    public function __construct() {}
    public function beginTransaction(): bool { $this->transaction=true;return true; }
    public function commit(): bool { $this->transaction=false;return true; }
    public function rollBack(): bool { $this->transaction=false;return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_contains($query,'PENDENTE\',\'CAPTURANDO') || str_contains($query,'status_dispositivos')) throw new RuntimeException('Conclusão operacional consultou pendência de relatório');
        return new FinishStatement($query);
    }
}
$connection=new FinishConnection();
function db(): PDO { global $connection; return $connection; }
function require_role(array $roles): array { return ['id'=>1,'company_id'=>7,'role'=>'USUARIO']; }
function require_csrf(): void {}
function request_json(): array { return ['carregamento_id'=>42]; }
function record_operational_event(mixed ...$args): void { global $events;$events[]=$args[2]; }
function json_response(array $data,int $status=200): never { global $events; echo json_encode(['status'=>$status,'body'=>$data,'events'=>$events]);exit; }
$_SERVER['REQUEST_METHOD']='POST';
$code=file_get_contents(__DIR__.'/../../servidor/api/operacoes/encerrar_carregamento.php');
$code=preg_replace('/^require_once .*;\s*$/m','',$code);
$code=preg_replace('/^<\?php\s*/','',$code);
eval($code);
