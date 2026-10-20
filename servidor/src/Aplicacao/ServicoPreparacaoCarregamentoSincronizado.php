<?php

declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioCarregamentosEventos;
use App\Persistencia\RepositorioDalasEventos;
use App\Persistencia\RepositorioItensRomaneioSincronizacao;
use App\Persistencia\RepositorioProdutosEventos;
use App\Persistencia\RepositorioRomaneiosEventos;
use RuntimeException;

final class ServicoPreparacaoCarregamentoSincronizado
{
    public function __construct(
        private readonly RepositorioCarregamentosEventos $carregamentos,
        private readonly RepositorioDalasEventos $dalas,
        private readonly RepositorioRomaneiosEventos $romaneios,
        private readonly RepositorioItensRomaneioSincronizacao $itensRomaneio,
        private readonly RepositorioProdutosEventos $produtos,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,manifest_id:int,truck_id:int,equipment_id:int}
     * @throws RuntimeException quando um identificador, vínculo ou estado é inválido
     */
    public function preparar(
        int $companyId,
        int $installationId,
        ?int $installationEquipmentId,
        string $assignedEquipmentCode,
        int $sourceLoadingId,
        array $data,
    ): array {
        $knownRemoteLoadingId = filter_var($data["remote_carregamento_id"] ?? null, FILTER_VALIDATE_INT);
        $knownRemoteLoadingId = $knownRemoteLoadingId !== false && $knownRemoteLoadingId !== null && $knownRemoteLoadingId > 0
            ? (int) $knownRemoteLoadingId : null;
        $sourceLoadingId = filter_var($data["source_carregamento_id"] ?? $sourceLoadingId, FILTER_VALIDATE_INT);
        $sourceLoadingId = $sourceLoadingId !== false && $sourceLoadingId !== null && $sourceLoadingId > 0
            ? (int) $sourceLoadingId : 0;
        if ($sourceLoadingId < 1) {
            throw new RuntimeException("Identificador do carregamento recebido pela sincronização é inválido.");
        }

        $manifestData = is_array($data["romaneio"] ?? null) ? $data["romaneio"] : $data;
        $manifest = $this->romaneios->salvarRomaneio(
            $companyId,
            (int) ($data["source_romaneio_id"] ?? ($data["romaneio_id"] ?? 0)),
            $manifestData,
        );
        $truckData = is_array($data["truck"] ?? null) ? $data["truck"] : $data;
        $truck = $this->romaneios->salvarCaminhao((int) $manifest["id"], $truckData);
        $equipmentId = $this->dalas->obterIdDalaVinculada(
            $companyId,
            $installationEquipmentId,
            $assignedEquipmentCode,
            $data,
        );
        $state = strtoupper(trim((string) ($data["state"] ?? "PREPARANDO")));
        $allowedStates = ["AGUARDANDO", "PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "EMERGENCIA", "FINALIZADO"];
        if (!in_array($state, $allowedStates, true)) {
            throw new RuntimeException("Estado de carregamento recebido pela sincronização é inválido.");
        }

        $loadingId = $knownRemoteLoadingId === null
            ? null
            : $this->carregamentos->localizarPorIdRemoto($companyId, $knownRemoteLoadingId, $equipmentId);
        if ($loadingId === null) {
            $loadingId = $this->carregamentos->localizarPorOrigem($companyId, $installationId, $sourceLoadingId);
        }
        $loadingId = $this->carregamentos->salvar([
            "source_id" => $sourceLoadingId,
            "installation_id" => $installationId,
            "equipment_id" => $equipmentId,
            "manifest_id" => (int) $manifest["id"],
            "truck_id" => (int) $truck["id"],
            "state" => $state,
            "started_at" => (string) ($data["started_at"] ?? date("Y-m-d H:i:s")),
            "company_id" => $companyId,
        ], $loadingId);

        if (isset($data["items"]) && is_array($data["items"]) && $data["items"] !== []) {
            $this->itensRomaneio->substituirItens(
                $companyId,
                (int) $manifest["id"],
                (int) $truck["id"],
                $data["items"],
                [$this->produtos, "salvarVarios"],
            );
        }
        $this->romaneios->sincronizarStatusCarregamento($companyId, $loadingId, $state);

        return [
            "id" => $loadingId,
            "manifest_id" => (int) $manifest["id"],
            "truck_id" => (int) $truck["id"],
            "equipment_id" => $equipmentId,
        ];
    }
}
