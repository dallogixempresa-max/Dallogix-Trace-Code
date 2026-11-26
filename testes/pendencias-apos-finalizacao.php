<?php
declare(strict_types=1);
// Executa as consultas reais de seleção com SQLite; não valida locks de MySQL.
foreach (['keyence_worker.php','operacoes/sensor_eventos.php'] as $endpoint) {
    $source=file_get_contents(__DIR__.'/../servidor/api/'.$endpoint);
    if (!preg_match('/"SELECT c\.id(?:, c\.romaneio_id, c\.truck_id)? FROM carregamentos c.*?LIMIT 1(?: FOR UPDATE)?"/s',$source,$match)) throw new RuntimeException('Consulta não localizada: '.$endpoint);
    $sql=trim($match[0],'"');
    $sql=str_replace(' FOR UPDATE','',$sql);
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE carregamentos(id INTEGER,romaneio_id INTEGER,truck_id INTEGER,equipment_id INTEGER,company_id INTEGER,state TEXT);CREATE TABLE romaneios(id INTEGER,status TEXT);CREATE TABLE eventos_sensor(id INTEGER,carregamento_id INTEGER);CREATE TABLE leituras(sensor_event_id INTEGER)');
    $pdo->exec("INSERT INTO romaneios VALUES(10,'FINALIZADO'),(11,'EM_ANDAMENTO');INSERT INTO carregamentos VALUES(42,10,1,3,7,'FINALIZADO'),(43,11,1,3,7,'CARREGANDO');INSERT INTO eventos_sensor VALUES(100,42)");
    $stmt=$pdo->prepare($sql);
    $read=function(int $company=7)use($stmt){$stmt->execute(['equipment_id'=>3,'company_id'=>$company]);return $stmt->fetchColumn();};
    if((int)$read()!==42)throw new RuntimeException('Pendência finalizada não selecionada');
    if($read(8)!==false)throw new RuntimeException('Escopo por empresa perdido');
    $pdo->exec('INSERT INTO leituras VALUES(100)');
    if((int)$read()!==43)throw new RuntimeException('Carga resolvida bloqueou próxima operação');
    $pdo->exec("DELETE FROM leituras;UPDATE romaneios SET status='CANCELADO' WHERE id=10");
    if((int)$read()!==43)throw new RuntimeException('Carga cancelada selecionada');
}
echo "Pendências finalizadas processadas antes da próxima carga sem misturar empresas.\n";
