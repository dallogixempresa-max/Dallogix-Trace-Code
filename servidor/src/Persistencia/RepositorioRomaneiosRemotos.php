<?php

declare(strict_types=1);

namespace App\Persistencia;

use PDO;
use RuntimeException;

require_once __DIR__ . "/RepositorioFilaSincronizacao.php";

/** Persiste romaneios importados sem sobrescrever operações locais pendentes. */
final class RepositorioRomaneiosRemotos
{
    public function __construct(
        private readonly PDO $connection,
        private readonly RepositorioFilaSincronizacao $fila,
    ) {
    }

    /** @param array<string,mixed> $remote */
    public function salvarOuAtualizarRomaneio(int $companyId, int $remoteId, array $remote): int
    {
        $number = trim((string) ($remote["romaneio_number"] ?? ""));
        if ($remoteId < 1 || $number === "") {
            throw new RuntimeException("Romaneio remoto sem identificador válido.");
        }
        $find = $this->connection->prepare(
            "SELECT id FROM romaneios WHERE company_id = :company_id
             AND remote_romaneio_id = :remote_id LIMIT 1",
        );
        $find->execute(["company_id" => $companyId, "remote_id" => $remoteId]);
        $localId = (int) ($find->fetchColumn() ?: 0);
        if ($localId === 0) {
            $find = $this->connection->prepare(
                "SELECT id FROM romaneios WHERE company_id = :company_id
                 AND number = :number LIMIT 1",
            );
            $find->execute(["company_id" => $companyId, "number" => $number]);
            $localId = (int) ($find->fetchColumn() ?: 0);
        }
        $remoteStatus = strtoupper(trim((string) (
            $remote["romaneio_status"]
                ?? (is_array($remote["romaneio"] ?? null) ? ($remote["romaneio"]["status"] ?? "") : "")
        )));
        $allowedStatuses = ["IMPORTADO", "AGUARDANDO", "EM_ANDAMENTO", "FINALIZADO", "CANCELADO"];
        $status = in_array($remoteStatus, $allowedStatuses, true)
            ? $remoteStatus
            : ((string) ($remote["state"] ?? "PREPARANDO") === "FINALIZADO" ? "FINALIZADO" : "EM_ANDAMENTO");
        if ($localId) {
            if ($this->fila->possuiEventoPendente($companyId, "romaneio", $localId)) {
                $this->connection->prepare(
                    "UPDATE romaneios SET remote_romaneio_id = :remote_id
                     WHERE id = :id AND company_id = :company_id",
                )->execute(["remote_id" => $remoteId, "id" => $localId, "company_id" => $companyId]);
                return $localId;
            }
            $update = $this->connection->prepare(
                "UPDATE romaneios SET remote_romaneio_id = :remote_id, number = :number,
                 expedidor = :expedidor, scheduled_date = :scheduled_date, status = :status
                 WHERE id = :id AND company_id = :company_id",
            );
            $update->execute([
                "remote_id" => $remoteId,
                "number" => $number,
                "expedidor" => $remote["expedidor"] ?? null,
                "scheduled_date" => $remote["scheduled_date"] ?? date("Y-m-d"),
                "status" => $status,
                "id" => $localId,
                "company_id" => $companyId,
            ]);
            return $localId;
        }
        $insert = $this->connection->prepare(
            "INSERT INTO romaneios
             (company_id, remote_romaneio_id, number, expedidor, scheduled_date, status)
             VALUES (:company_id, :remote_id, :number, :expedidor, :scheduled_date, :status)",
        );
        $insert->execute([
            "company_id" => $companyId,
            "remote_id" => $remoteId,
            "number" => $number,
            "expedidor" => $remote["expedidor"] ?? null,
            "scheduled_date" => $remote["scheduled_date"] ?? date("Y-m-d"),
            "status" => $status,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /** @param list<array<string,mixed>> $cancelledManifests */
    public function sincronizarRomaneiosCancelados(int $companyId, array $cancelledManifests): int
    {
        $updated = 0;
        $findByRemoteId = $this->connection->prepare(
            "SELECT id FROM romaneios
             WHERE company_id = :company_id AND remote_romaneio_id = :remote_id LIMIT 1",
        );
        $findUnmappedByNumber = $this->connection->prepare(
            "SELECT id FROM romaneios
             WHERE company_id = :company_id AND number = :number
               AND remote_romaneio_id IS NULL ORDER BY id LIMIT 2",
        );
        $cancel = $this->connection->prepare(
            "UPDATE romaneios SET remote_romaneio_id = :remote_id, status = 'CANCELADO'
             WHERE id = :id AND company_id = :company_id",
        );

        foreach ($cancelledManifests as $manifest) {
            $remoteId = (int) ($manifest["id"] ?? 0);
            $number = trim((string) ($manifest["number"] ?? ""));
            if ($remoteId < 1 || $number === "") {
                throw new RuntimeException("Cancelamento remoto de romaneio sem identificador válido.");
            }

            $findByRemoteId->execute(["company_id" => $companyId, "remote_id" => $remoteId]);
            $localId = (int) ($findByRemoteId->fetchColumn() ?: 0);
            if ($localId < 1) {
                $findUnmappedByNumber->execute(["company_id" => $companyId, "number" => $number]);
                $matches = $findUnmappedByNumber->fetchAll(PDO::FETCH_COLUMN);
                // A numeração só pode vincular registros quando é única no PC.
                if (count($matches) === 1) {
                    $localId = (int) $matches[0];
                }
            }
            if ($localId < 1) {
                continue;
            }

            // O cancelamento remoto é terminal e deve prevalecer sobre uma
            // atualização local antiga ainda pendente na fila de sincronização.
            $cancel->execute([
                "remote_id" => $remoteId,
                "id" => $localId,
                "company_id" => $companyId,
            ]);
            $updated += $cancel->rowCount();
        }

        return $updated;
    }

    /** @param list<array<string,mixed>> $manifests */
    public function sincronizarRomaneiosDisponiveis(int $companyId, array $manifests): int
    {
        $findByRemoteId = $this->connection->prepare(
            "SELECT id FROM romaneios
             WHERE company_id = :company_id AND remote_romaneio_id = :remote_id LIMIT 1",
        );
        $findByNumber = $this->connection->prepare(
            "SELECT id, remote_romaneio_id FROM romaneios
             WHERE company_id = :company_id AND number = :number ORDER BY id LIMIT 2",
        );
        $insert = $this->connection->prepare(
            "INSERT INTO romaneios
             (company_id, remote_romaneio_id, number, scheduled_date, expedidor, status)
             VALUES (:company_id, :remote_id, :number, :scheduled_date, :expedidor, :status)",
        );
        $update = $this->connection->prepare(
            "UPDATE romaneios SET remote_romaneio_id = :remote_id, number = :number,
             scheduled_date = :scheduled_date, expedidor = :expedidor, status = :status
             WHERE id = :id AND company_id = :company_id",
        );
        $linkRemoteId = $this->connection->prepare(
            "UPDATE romaneios SET remote_romaneio_id = :remote_id
             WHERE id = :id AND company_id = :company_id",
        );

        $updated = 0;
        foreach ($manifests as $manifest) {
            $remoteId = (int) ($manifest["id"] ?? 0);
            $number = trim((string) ($manifest["number"] ?? ""));
            $status = strtoupper(trim((string) ($manifest["status"] ?? "")));
            if ($remoteId < 1 || $number === "" || !in_array($status, ["IMPORTADO", "AGUARDANDO"], true)) {
                throw new RuntimeException("Romaneio disponível recebido do Central é inválido.");
            }

            $findByRemoteId->execute(["company_id" => $companyId, "remote_id" => $remoteId]);
            $localId = (int) ($findByRemoteId->fetchColumn() ?: 0);
            if ($localId < 1) {
                $findByNumber->execute(["company_id" => $companyId, "number" => $number]);
                $sameNumber = $findByNumber->fetchAll(PDO::FETCH_ASSOC);
                if ($sameNumber !== []) {
                    throw new RuntimeException(
                        "Romaneio central {$number} conflita com um registro local; a sincronização não o vinculou automaticamente.",
                    );
                }
                $insert->execute([
                    "company_id" => $companyId,
                    "remote_id" => $remoteId,
                    "number" => $number,
                    "scheduled_date" => $manifest["scheduled_date"],
                    "expedidor" => $manifest["expedidor"] ?? null,
                    "status" => $status,
                ]);
                $localId = (int) $this->connection->lastInsertId();
                $updated++;
            } elseif ($this->fila->possuiEventoPendente($companyId, "romaneio", $localId)) {
                $linkRemoteId->execute([
                    "remote_id" => $remoteId,
                    "id" => $localId,
                    "company_id" => $companyId,
                ]);
            } else {
                $current = $this->connection->prepare(
                    "SELECT status FROM romaneios WHERE id = :id AND company_id = :company_id LIMIT 1",
                );
                $current->execute(["id" => $localId, "company_id" => $companyId]);
                $currentStatus = strtoupper(trim((string) ($current->fetchColumn() ?: "")));
                if (in_array($currentStatus, ["FINALIZADO", "CANCELADO"], true)) {
                    continue;
                }
                $update->execute([
                    "remote_id" => $remoteId,
                    "number" => $number,
                    "scheduled_date" => $manifest["scheduled_date"],
                    "expedidor" => $manifest["expedidor"] ?? null,
                    "status" => $status,
                    "id" => $localId,
                    "company_id" => $companyId,
                ]);
                $updated++;
            }

            if ($this->fila->possuiEventoPendente($companyId, "romaneio", $localId)) {
                continue;
            }
            foreach (($manifest["trucks"] ?? []) as $remoteTruck) {
                $truckId = $this->salvarOuAtualizarCaminhao(
                    $companyId,
                    $localId,
                    (int) ($remoteTruck["id"] ?? 0),
                    $remoteTruck,
                );
                $this->sincronizarItens($localId, $companyId, $truckId, $remoteTruck["items"] ?? []);
            }
        }
        return $updated;
    }

    /** @param array<string,mixed> $remote */
    public function salvarOuAtualizarCaminhao(int $companyId, int $romaneioId, int $remoteId, array $remote): int
    {
        $plate = strtoupper(trim((string) ($remote["plate"] ?? "")));
        if (!placa_caminhao_valida($plate)) {
            throw new RuntimeException("Caminhão recebido pela sincronização é inválido.");
        }
        if ($remoteId < 1) {
            throw new RuntimeException("Caminhão remoto sem identificador válido.");
        }
        $find = $this->connection->prepare(
            "SELECT id FROM romaneio_caminhoes WHERE romaneio_id = :romaneio_id
             AND remote_truck_id = :remote_id LIMIT 1",
        );
        $find->execute(["romaneio_id" => $romaneioId, "remote_id" => $remoteId]);
        $localId = (int) ($find->fetchColumn() ?: 0);
        if ($localId === 0) {
            $find = $this->connection->prepare(
                "SELECT id FROM romaneio_caminhoes WHERE romaneio_id = :romaneio_id
                 AND plate = :plate LIMIT 1",
            );
            $find->execute(["romaneio_id" => $romaneioId, "plate" => $plate]);
            $localId = (int) ($find->fetchColumn() ?: 0);
        }
        if ($localId) {
            if ($this->fila->possuiEventoPendente($companyId, "romaneio", $romaneioId)) {
                $this->connection->prepare(
                    "UPDATE romaneio_caminhoes SET remote_truck_id = :remote_id WHERE id = :id",
                )->execute(["remote_id" => $remoteId, "id" => $localId]);
                return $localId;
            }
            $update = $this->connection->prepare(
                "UPDATE romaneio_caminhoes SET remote_truck_id = :remote_id,
                 plate = :plate, driver_name = :driver_name WHERE id = :id",
            );
            $update->execute([
                "remote_id" => $remoteId,
                "plate" => $plate,
                "driver_name" => $remote["driver_name"] ?? null,
                "id" => $localId,
            ]);
            return $localId;
        }
        $insert = $this->connection->prepare(
            "INSERT INTO romaneio_caminhoes (romaneio_id, remote_truck_id, plate, driver_name)
             VALUES (:romaneio_id, :remote_id, :plate, :driver_name)",
        );
        $insert->execute([
            "romaneio_id" => $romaneioId,
            "remote_id" => $remoteId,
            "plate" => $plate,
            "driver_name" => $remote["driver_name"] ?? null,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /** @param list<array<string,mixed>> $items */
    public function sincronizarItens(int $romaneioId, int $companyId, int $truckId, array $items): void
    {
        $itemsByCode = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string) ($item["code"] ?? ""));
            if ($code === "") {
                continue;
            }
            $itemsByCode[mb_strtolower($code, "UTF-8")][] = $item;
        }

        $product = $this->connection->prepare(
            "SELECT id FROM produtos WHERE company_id = :company_id AND code = :code LIMIT 1",
        );
        $existing = $this->connection->prepare(
            "SELECT id, truck_id FROM romaneio_itens
             WHERE romaneio_id = :romaneio_id AND product_id = :product_id
               AND (truck_id = :truck_id_match OR truck_id IS NULL)
             ORDER BY CASE WHEN truck_id = :truck_id_order THEN 0 ELSE 1 END, id",
        );
        $update = $this->connection->prepare(
            "UPDATE romaneio_itens SET truck_id = :truck_id, planned_quantity = :quantity
             WHERE id = :id AND romaneio_id = :romaneio_id",
        );
        $deleteDuplicate = $this->connection->prepare(
            "DELETE FROM romaneio_itens
             WHERE id = :id AND romaneio_id = :romaneio_id",
        );
        $insert = $this->connection->prepare(
            "INSERT INTO romaneio_itens (company_id, romaneio_id, product_id, truck_id, planned_quantity)
             VALUES (:company_id, :romaneio_id, :product_id, :truck_id, :quantity)",
        );

        foreach ($itemsByCode as $codeItems) {
            $code = trim((string) ($codeItems[0]["code"] ?? ""));
            if ($code === "") {
                continue;
            }
            $product->execute(["company_id" => $companyId, "code" => $code]);
            $productId = (int) ($product->fetchColumn() ?: 0);
            if (!$productId) {
                $insertProduct = $this->connection->prepare(
                    "INSERT INTO produtos (company_id, code, name, active) VALUES (:company_id, :code, :name, 1)",
                );
                $insertProduct->execute([
                    "company_id" => $companyId,
                    "code" => $code,
                    "name" => trim((string) ($codeItems[0]["name"] ?? $code)),
                ]);
                $productId = (int) $this->connection->lastInsertId();
            }
            $existing->execute([
                "romaneio_id" => $romaneioId,
                "product_id" => $productId,
                "truck_id_match" => $truckId > 0 ? $truckId : 0,
                "truck_id_order" => $truckId > 0 ? $truckId : 0,
            ]);
            $existingRows = $existing->fetchAll();
            foreach ($codeItems as $index => $item) {
                $quantity = max(1, (int) ($item["planned_quantity"] ?? 1));
                if (isset($existingRows[$index])) {
                    $update->execute([
                        "truck_id" => $truckId > 0 ? $truckId : null,
                        "quantity" => $quantity,
                        "id" => (int) $existingRows[$index]["id"],
                        "romaneio_id" => $romaneioId,
                    ]);
                } else {
                    $insert->execute([
                        "company_id" => $companyId,
                        "romaneio_id" => $romaneioId,
                        "product_id" => $productId,
                        "truck_id" => $truckId > 0 ? $truckId : null,
                        "quantity" => $quantity,
                    ]);
                }
            }
            foreach (array_slice($existingRows, count($codeItems)) as $staleItem) {
                $deleteDuplicate->execute([
                    "id" => (int) $staleItem["id"],
                    "romaneio_id" => $romaneioId,
                ]);
            }
        }
    }

}
