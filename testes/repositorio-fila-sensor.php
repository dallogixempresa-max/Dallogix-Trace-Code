<?php
declare(strict_types=1);
require_once __DIR__.'/../servidor/src/Persistencia/RepositorioFilaSensor.php';
final class StatementFila extends PDOStatement {
    public array $params = [];
    public function execute(?array $params = null): bool { $this->params=$params??[]; return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $cursorOrientation=PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed { return ['pending_events'=>'0']; }
}
final class ConnectionFila extends PDO {
    public string $query='';
    public StatementFila $statement;
    public function __construct() { $this->statement=new StatementFila(); }
    public function prepare(string $query, array $options=[]): PDOStatement|false { $this->query=$query; return $this->statement; }
}
$connection=new ConnectionFila();
$repository=new App\Persistencia\RepositorioFilaSensor($connection);
foreach ([false,true] as $lock) {
    if ($repository->consultar(42,$lock)!==['pending_events'=>'0']) throw new RuntimeException('Resposta alterada');
    if ($connection->statement->params!==['equipment_id'=>42]) throw new RuntimeException('Escopo incorreto');
    if (str_contains($connection->query,'FOR UPDATE')!==$lock) throw new RuntimeException('Lock incorreto');
    if (substr_count($connection->query,"status = 'ONLINE' AND last_seen_at >= DATE_SUB(NOW(3), INTERVAL 5 SECOND)")!==2) throw new RuntimeException('Confirmação recente ausente');
}
echo "Consulta e lock da fila verificados com PDO controlado.\n";
