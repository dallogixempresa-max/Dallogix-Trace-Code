<?php
declare(strict_types=1);
namespace App\Aplicacao;

use RuntimeException;

/** Confere identidade antes de incorporar qualquer foto; não tenta adivinhar vínculos. */
final class ConferidorEvidenciasRelatorio
{
    /** Seleciona somente fotos comprovadas e devolve as falhas para constarem no PDF. */
    public static function selecionarDisponiveis(array $images, array $requests): array
    {
        $byId = [];
        foreach ($requests as $request) {
            $byId[(int) ($request['request_id'] ?? 0)][] = $request;
        }
        $selected = [];
        $selectedRequests = [];
        $issues = [];
        foreach ($images as $image) {
            $id = (int) ($image['request_id'] ?? 0);
            if ($id < 1
                && preg_match('/(?:^|\/)keyence-([1-9][0-9]*)-[a-f0-9]{64}\.(?:jpg|png)\z/i', (string) ($image['path'] ?? ''), $matches) === 1) {
                $id = (int) $matches[1];
                $image['request_id'] = $id;
            }
            try {
                $matches = $byId[$id] ?? [];
                if (count($matches) !== 1) {
                    throw new RuntimeException('Vínculo não confirmado: solicitação ausente ou ambígua.');
                }
                $valid = self::vincular([$image], $matches, false);
                $imageKey = strtolower((string) ($valid[0]['image_sha256'] ?? ''));
                $selected[$id . ':' . $imageKey] = $valid[0];
                $selectedRequests[$id] = true;
            } catch (RuntimeException $exception) {
                $issues[] = ['request_id' => $id, 'sensor_event_id' => $image['sensor_event_id'] ?? null,
                    'reason' => $image['reason'] ?? '', 'detail' => $exception->getMessage()];
            }
        }
        foreach ($requests as $request) {
            $id = (int) ($request['request_id'] ?? 0);
            if (!isset($selectedRequests[$id])) {
                $issues[] = ['request_id' => $id, 'sensor_event_id' => $request['sensor_event_id'] ?? null,
                    'reason' => $request['reason'] ?? '', 'detail' => 'Foto não disponível com vínculo confirmado.'];
            }
        }
        return ['images' => array_values($selected), 'issues' => $issues];
    }

    public static function manifestoCorresponde(array $manifest, array $requests): bool
    {
        if (is_array($manifest['source_images'] ?? null)) {
            $sourceImages = $manifest['source_images'];
            $hasRequestIds = $sourceImages !== [];
            foreach ($sourceImages as $sourceImage) {
                if (!is_array($sourceImage) || (int) ($sourceImage['request_id'] ?? 0) < 1) {
                    $hasRequestIds = false;
                    break;
                }
            }
            if (!$hasRequestIds) {
                $legacyManifest = $manifest;
                unset($legacyManifest['evidence_identity_sha256']);
                $actualSignature = self::assinaturaManifesto($legacyManifest);
                return is_string($actualSignature)
                    && hash_equals(self::assinaturaSolicitacoes($requests), $actualSignature);
            }
            $expectedById = [];
            foreach ($requests as $request) {
                $id = (int) ($request['request_id'] ?? 0);
                $hash = strtolower((string) ($request['image_sha256'] ?? ''));
                $uuid = (string) ($request['sensor_event_uuid'] ?? '');
                if ($id < 1 || !preg_match('/\A[a-f0-9]{64}\z/', $hash) || $uuid === ''
                    || isset($expectedById[$id])) {
                    return false;
                }
                $expectedById[$id] = [
                    'hash' => $hash,
                    'reason' => (string) ($request['reason'] ?? ''),
                    'uuid' => $uuid,
                    'canonical_found' => false,
                ];
            }
            if (count($sourceImages) < count($expectedById)) return false;
            $expectedSignature = self::assinaturaSolicitacoes($requests);
            $declaredSignature = $manifest['evidence_identity_sha256'] ?? null;
            if (is_string($declaredSignature)
                && (!preg_match('/\A[a-f0-9]{64}\z/', $declaredSignature)
                    || !hash_equals($expectedSignature, $declaredSignature))) {
                return false;
            }
            foreach ($sourceImages as $image) {
                $id = (int) ($image['request_id'] ?? 0);
                $hash = strtolower((string) ($image['sha256'] ?? ''));
                $expected = $expectedById[$id] ?? null;
                if ($expected === null || !preg_match('/\A[a-f0-9]{64}\z/', $hash)
                    || (string) ($image['reason'] ?? '') !== $expected['reason']
                    || (string) ($image['sensor_event_uuid'] ?? '') !== $expected['uuid']) {
                    return false;
                }
                if (hash_equals($expected['hash'], $hash)) {
                    $expectedById[$id]['canonical_found'] = true;
                }
            }
            foreach ($expectedById as $expected) {
                if (!$expected['canonical_found']) return false;
            }
            return true;
        }

        $expected=[];
        foreach ($requests as $request) {
            $hash=strtolower((string)($request['image_sha256']??''));
            if (!preg_match('/\A[a-f0-9]{64}\z/',$hash)) return false;
            $uuid=(string)($request['sensor_event_uuid']??'');
            if ($uuid==='') return false;
            $expected[]=(string)$request['reason'].':'.$hash.':'.$uuid;
        }
        sort($expected);
        $expectedSignature=hash('sha256',json_encode($expected,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $actualSignature=array_key_exists('source_images', $manifest)
            ? self::assinaturaManifesto($manifest)
            : ($manifest['evidence_identity_sha256']??null);
        return is_string($actualSignature) && hash_equals($expectedSignature,$actualSignature);
    }

    public static function assinaturaManifesto(array $manifest): ?string
    {
        $declaredSignature = $manifest['evidence_identity_sha256'] ?? null;
        if (is_string($declaredSignature) && preg_match('/\A[a-f0-9]{64}\z/', $declaredSignature)) {
            return $declaredSignature;
        }
        if (!isset($manifest['source_images']) || !is_array($manifest['source_images'])) return null;
        $actual=[];
        foreach ($manifest['source_images'] as $image) {
            $hash=strtolower((string)($image['sha256']??''));
            $uuid=(string)($image['sensor_event_uuid']??'');
            if (!preg_match('/\A[a-f0-9]{64}\z/',$hash) || $uuid==='') return null;
            $actual[]=(string)($image['reason']??'').':'.$hash.':'.$uuid;
        }
        sort($actual);
        return hash('sha256',json_encode($actual,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public static function assinaturaSolicitacoes(array $requests): string
    {
        $expected = [];
        foreach ($requests as $request) {
            $hash = strtolower((string) ($request['image_sha256'] ?? ''));
            $uuid = (string) ($request['sensor_event_uuid'] ?? '');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $hash) || $uuid === '') {
                throw new RuntimeException('Solicitação de evidência sem hash ou evento único.');
            }
            $expected[] = (string) ($request['reason'] ?? '') . ':' . $hash . ':' . $uuid;
        }
        sort($expected);
        return hash('sha256', json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function vincular(array $images, array $requests, bool $requireEveryRequest = true): array
    {
        $byId=[];
        foreach ($requests as $request) {
            $id=(int)($request['request_id']??0);
            if ($id<1 || isset($byId[$id]) || (int)($request['sensor_event_id']??0)<1
                || (int)($request['carregamento_id']??0)<1
                || (int)($request['company_id']??0)<1
                || (int)($request['equipment_id']??0)<1
                || empty($request['sensor_event_uuid'])
                || !in_array($request['reason']??null, ['SEM_LEITURA', 'PRODUTO_INCORRETO', 'EXCESSO'], true)) {
                throw new RuntimeException('Solicitação de evidência sem identidade única de passagem.');
            }
            $byId[$id]=$request;
        }
        $selected=[];
        $seenRequests=[];
        $seenImages=[];
        foreach ($images as $image) {
            $id=(int)($image['request_id']??0);
            $request=$byId[$id]??null;
            if ($request===null
                || (int)($image['sensor_event_id']??0)!==(int)$request['sensor_event_id']
                || (int)($image['carregamento_id']??0)!==(int)$request['carregamento_id']
                || (int)($image['company_id']??0)!==(int)($request['company_id']??0)
                || (int)($image['equipment_id']??0)!==(int)($request['equipment_id']??0)
                || (string)($image['reason']??'')!==(string)$request['reason']
                || (isset($image['reading_id']) && (int)$image['reading_id']!==(int)($request['reading_id']??0))) {
                throw new RuntimeException('Foto e incidente possuem vínculos incompatíveis.');
            }
            $basename=basename((string)($image['path']??''));
            if (!preg_match('/\A(?:keyence|capture)-'.$id.'-([a-f0-9]{64})\.(?:jpg|png)\z/i',$basename, $matches)) {
                throw new RuntimeException('Foto sem identificação e hash compatíveis com a solicitação.');
            }
            $hash = strtolower($matches[1]);
            $rowHash = strtolower((string) ($image['image_sha256'] ?? ''));
            if ($rowHash !== '' && (!preg_match('/\A[a-f0-9]{64}\z/', $rowHash) || !hash_equals($hash, $rowHash))) {
                throw new RuntimeException('O hash da foto diverge do arquivo recebido.');
            }
            $dedupeKey = $id . ':' . $hash;
            if (isset($seenImages[$dedupeKey])) continue;
            $seenImages[$dedupeKey] = true;
            $image['image_sha256']=$hash;
            $image['sensor_event_uuid']=$request['sensor_event_uuid']??null;
            $selected[]=$image;
            $seenRequests[$id] = true;
        }
        if ($requireEveryRequest && count($seenRequests)!==count($byId)) throw new RuntimeException('Faltam fotos vinculadas a incidentes capturados.');
        return $selected;
    }
}
