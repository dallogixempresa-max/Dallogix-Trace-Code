<?php
declare(strict_types=1);
require_once __DIR__.'/../servidor/src/Aplicacao/ConferidorEvidenciasRelatorio.php';
require_once __DIR__.'/../servidor/src/Aplicacao/RelatorioAuditoriaPdf.php';
use App\Aplicacao\ConferidorEvidenciasRelatorio as Check;
use App\Aplicacao\RelatorioAuditoriaPdf as Pdf;
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function chunkPng(string $type,string $data):string{return pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));}
function png(int $r,int $g,int $b):string{return "\x89PNG\r\n\x1a\n".chunkPng('IHDR',pack('NNC5',1,1,8,6,0,0,0)).chunkPng('IDAT',gzcompress("\0".chr($r).chr($g).chr($b)."\xff")).chunkPng('IEND','');}
$bytes=[png(255,0,0),png(0,0,255),png(0,180,0)];
$requests=[];$images=[];
foreach(['SEM_LEITURA','PRODUTO_INCORRETO','EXCESSO'] as $i=>$reason){
 $id=$i+1;$hash=hash('sha256',$bytes[$i]);
 $requests[]=['request_id'=>$id,'carregamento_id'=>42,'company_id'=>7,'equipment_id'=>3,'sensor_event_id'=>100+$id,'sensor_event_uuid'=>sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d',$id),'reading_id'=>200+$id,'reason'=>$reason,'image_sha256'=>$hash];
 $images[]=array_merge($requests[$i],['path'=>"company_7/equipment_3/keyence-{$id}-{$hash}.png"]);
}
$selected=Check::vincular(array_reverse($images),$requests);check(count($selected)===3,'Nem todas as fotos foram vinculadas');
check(count(Check::vincular([...$images,$images[0]],$requests))===3,'Foto duplicada entrou duas vezes');
foreach(['sensor_event_id','carregamento_id','company_id','equipment_id','reading_id'] as $field){
 $bad=$images;$bad[0][$field]=999;
 try{Check::vincular($bad,$requests);throw new LogicException('Vínculo incorreto aceito: '.$field);}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
}
foreach(['SEM_LEITURA-falso','PRODUTO_INCORRETO'] as $badReason){
 $bad=$images;$bad[0]['reason']=$badReason;
 try{Check::vincular($bad,$requests);throw new LogicException('Motivo errado aceito');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
}
try{Check::vincular(array_slice($images,1),$requests);throw new LogicException('Foto faltante aceita');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
$manifest=['source_images'=>array_map(fn($r)=>['sha256'=>$r['image_sha256'],'reason'=>$r['reason'],'sensor_event_uuid'=>$r['sensor_event_uuid']],$requests)];
check(Check::manifestoCorresponde($manifest,array_reverse($requests)),'Ordem dos registros alterou a identidade');
$remote=['evidence_identity_sha256'=>Check::assinaturaManifesto($manifest)];
check(Check::manifestoCorresponde($remote,$requests),'Identidade se perdeu no transporte ao Central');
$invalidManifest=$remote+['source_images'=>[['sha256'=>'invalido']]];
check(!Check::manifestoCorresponde($invalidManifest,$requests),'Manifesto malformado aceito pela assinatura alternativa');
$changed=$requests;$changed[0]['image_sha256']=str_repeat('b',64);
check(!Check::manifestoCorresponde($manifest,$changed),'Troca de foto com mesma quantidade não invalidou o PDF');
$changed=$requests;$changed[0]['sensor_event_uuid']=$requests[1]['sensor_event_uuid'];
check(!Check::manifestoCorresponde($manifest,$changed),'Troca de passagem não invalidou o PDF');
check(!Check::manifestoCorresponde(['no_read_count'=>1],$requests),'Contagem isolada foi aceita como identidade');
if(isset($argv[1])){
 $pdf=new Pdf('Homologação sintética — sem dados reais','Fotos por incidente');
 $pdf->escreverTitulo('Conferência de evidências após finalização');
 $pdf->escreverParagrafo('Teste sintético: três incidentes, três imagens distintas. As cores permitem identificar troca de fotos. Não representa uma operação real.');
 foreach($requests as $i=>$request){
  $caption=$request['reason'].' · Saca '.($i+1).' · Evento '.$request['sensor_event_id'].' · Evidência '.$request['request_id'];
  check($pdf->inserirDadosDaImagemDaOcorrencia($bytes[$i],'image/png',$caption,true),'Imagem não incorporada');
 }
 $content=$pdf->obterArquivoPdf();$extracted=Pdf::extrairImagensOriginaisDoPdf($content);
 check(count($extracted)===3,'PDF não tem as três fotos originais');
 foreach($extracted as $i=>$image)check(hash('sha256',$image['bytes'])===$requests[$i]['image_sha256'],'Bytes da imagem foram trocados');
 file_put_contents($argv[1],$content);
}
echo "ok\n";
