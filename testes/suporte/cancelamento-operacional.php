<?php
declare(strict_types=1);
require __DIR__.'/../../servidor/src/Persistencia/RepositorioFilaSensor.php';
require __DIR__.'/../../servidor/src/Aplicacao/ValidadorFilaSensor.php';
$scenario=json_decode($argv[1]??'{}',true);
$events=[];
class CancelStatement extends PDOStatement {
    public function __construct(private string $sql) {}
    public function execute(?array $params=null): bool { global $loadingReason; if(str_contains($this->sql,'UPDATE carregamentos')) $loadingReason=$params['reason']??null; return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {
        global $scenario;
        return str_contains($this->sql,'status_dispositivos')
            ? ['pending_events'=>array_key_exists('pending',$scenario)?$scenario['pending']:0]
            : ['id'=>76,'status'=>'EM_ANDAMENTO'];
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        global $scenario;
        return [['id'=>73,'equipment_id'=>3,'state'=>$scenario['state']??'PAUSADO']];
    }
    public function fetchColumn(int $column=0): mixed { global $scenario; return $scenario['unregistered']??0; }
}
class CancelConnection extends PDO {
    private bool $transaction=false;
    public function __construct() {}
    public function beginTransaction(): bool {$this->transaction=true;return true;}
    public function commit(): bool {$this->transaction=false;return true;}
    public function rollBack(): bool {$this->transaction=false;return true;}
    public function inTransaction(): bool {return $this->transaction;}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_contains($query,'solicitacoes_imagens_evidencia') || preg_match('/\bDELETE\b/i',$query)) {
            throw new RuntimeException('Cancelamento consultou fotos ou apagou dados.');
        }
        return new CancelStatement($query);
    }
}
function record_operational_event(mixed ...$args): void {global $events,$eventDetails;$events[]=$args[2];$eventDetails[]=$args[5]??null;}
function responder_json(array $data,int $status=200): never {global $events;global $eventDetails;echo json_encode(['status'=>$status,'body'=>$data,'events'=>$events,'event_details'=>$eventDetails??[],'loading_reason'=>$GLOBALS['loadingReason']??null]);exit;}
$pdo=new CancelConnection();$payload=['romaneio_id'=>76,'justification'=>'Teste de bancada']+(array_key_exists('force',$scenario)?['force'=>$scenario['force']]:[]);$action='cancel';$companyId=7;$usuarioAtor=['id'=>1,'company_id'=>7];
$source=file_get_contents(__DIR__.'/../../servidor/api/romaneios/romaneios.php');
$start=strpos($source,'    if ($action === "cancel")');
$end=strpos($source,'    // O ramo cancel',$start);
eval(substr($source,$start,$end-$start));
