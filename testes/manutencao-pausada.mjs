import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
const sources=['../scripts/deploy_from_git.sh','../implantacao/windows/TraceUpdater.ps1'];
for(const file of sources) {
  const source=readFileSync(new URL(file,import.meta.url),'utf8');
  const query=source.match(/"(SELECT IF\(.*?;)"/)?.[1];
  assert.ok(query,file);
  for(const [name,loadings,commands,allowed] of [
    ['carga pausada isolada',[[73,'PAUSADO']],[],true],
    ['carga em movimento',[[73,'CARREGANDO']],[],false],
    ['carga inexistente',[],[],false],
    ['outra carga ativa',[[73,'PAUSADO'],[74,'CARREGANDO']],[],false],
    ['comando pendente',[[73,'PAUSADO']],['PENDENTE'],false],
    ['comando em processamento',[[73,'PAUSADO']],['PROCESSANDO'],false],
    ['comando aplicado',[[73,'PAUSADO']],['APLICADO'],true],
  ]) test(`${file}: manutenção verifica ${name} sem modificar dados`,()=>{
    const sql=query.replace(/\$(PausedMaintenanceLoadingId|maintenance_loading_id)/g,'73');
    const program=`$d=class_exists('Pdo\\Sqlite')?new \\Pdo\\Sqlite('sqlite::memory:'):new PDO('sqlite::memory:');if(method_exists($d,'createFunction'))$d->createFunction('IF',fn($c,$a,$b)=>$c?$a:$b);else $d->sqliteCreateFunction('IF',fn($c,$a,$b)=>$c?$a:$b);$d->exec('CREATE TABLE carregamentos(id INTEGER,state TEXT); CREATE TABLE solicitacoes_comandos_clp(status TEXT)');$s=$d->prepare('INSERT INTO carregamentos VALUES (?,?)');foreach(json_decode($argv[1],true) as $r)$s->execute($r);$s=$d->prepare('INSERT INTO solicitacoes_comandos_clp VALUES (?)');foreach(json_decode($argv[2],true) as $r)$s->execute([$r]);$before=$d->query('SELECT * FROM carregamentos')->fetchAll();$n=$d->query($argv[3])->fetchColumn();if($before!==$d->query('SELECT * FROM carregamentos')->fetchAll())exit(2);echo $n;`;
    const r=spawnSync('php',['-r',program,JSON.stringify(loadings),JSON.stringify(commands),sql],{encoding:'utf8'});
    assert.equal(r.status,0,r.stderr);
    assert.equal(Number(r.stdout)===0,allowed);
  });
}
