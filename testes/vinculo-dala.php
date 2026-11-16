<?php
declare(strict_types=1);
require_once __DIR__.'/../servidor/src/Aplicacao/PoliticaVinculoDala.php';
require_once __DIR__.'/../servidor/src/Persistencia/RepositorioDalasEventos.php';
use App\Aplicacao\PoliticaVinculoDala;
use App\Persistencia\RepositorioDalasEventos;
$cases=['AGUARDANDO'=>'PREPARANDO','PREPARANDO'=>'PREPARANDO','PAUSADO'=>'PAUSADO','CARREGANDO'=>null,'FINALIZANDO'=>null,'EMERGENCIA'=>null,'FINALIZADO'=>null,'DESCONHECIDO'=>null];
foreach ($cases as $state=>$expected) {
    $loading=['equipment_id'=>null,'state'=>$state,'started_at'=>'2026-11-10 09:00:00'];
    if (PoliticaVinculoDala::estadoAposVinculo($loading)!==$expected) throw new RuntimeException('Estado incorreto: '.$state);
    if ($loading['started_at']!=='2026-11-10 09:00:00') throw new RuntimeException('Histórico alterado');
    $loading['equipment_id']=3;
    if (PoliticaVinculoDala::estadoAposVinculo($loading)!==null) throw new RuntimeException('Troca de equipamento já vinculado aceita');
}
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE carregamentos (id INTEGER PRIMARY KEY, company_id INTEGER, equipment_id INTEGER, state TEXT)');
$next=PoliticaVinculoDala::estadoAposVinculo(['equipment_id'=>null,'state'=>'AGUARDANDO']);
$pdo->prepare('INSERT INTO carregamentos VALUES (1,7,3,?)')->execute([$next]);
try {
    (new RepositorioDalasEventos($pdo))->excluirDalaVinculada(7,2,3);
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(),'carregamento ativo')) throw $e;
    echo "Política de vínculo e proteção da Dala em preparação verificadas.\n";
    exit(0);
}
throw new RuntimeException('Exclusão de Dala preparada deveria ser recusada');
