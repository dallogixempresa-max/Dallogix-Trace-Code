<?php

declare(strict_types=1);

namespace App\Aplicacao;

use RuntimeException;

final class ValidadorInstantaneoRemoto
{
    /** @param array<string,mixed> $installation @param array<string,mixed> $snapshot */
    public static function validar(array $installation, array $snapshot): void
    {
        $remoteCompanyId = (int) ($installation["remote_company_id"] ?? 0);
        $company = $snapshot["empresa"] ?? null;
        if (!is_array($company) || $remoteCompanyId < 1 || (int) ($company["id"] ?? 0) !== $remoteCompanyId) {
            throw new RuntimeException("Resposta do servidor central pertence a outra empresa.");
        }

        $remoteInstallation = $snapshot["instalacao"] ?? null;
        if (!is_array($remoteInstallation)
            || (int) ($remoteInstallation["id"] ?? 0) < 1
            || (int) ($remoteInstallation["equipment_id"] ?? 0) < 1
        ) {
            throw new RuntimeException(
                "Resposta do servidor central não identificou este PC e sua Dala; a instalação industrial aceita uma Dala por PC.",
            );
        }
        $storedInstallationId = (int) ($installation["remote_installation_id"] ?? 0);
        $storedEquipmentId = (int) ($installation["remote_equipment_id"] ?? 0);
        if (($storedInstallationId > 0 && $storedInstallationId !== (int) $remoteInstallation["id"])
            || ($storedEquipmentId > 0 && $storedEquipmentId !== (int) $remoteInstallation["equipment_id"])
        ) {
            throw new RuntimeException("O PC local está vinculado a outra instalação ou Dala no servidor central.");
        }

        $licenseStatus = strtoupper(trim((string) ($company["license_status"] ?? "")));
        if (!in_array($licenseStatus, ["ATIVA", "BLOQUEADA"], true)) {
            throw new RuntimeException("Resposta do servidor central não informou uma licença válida.");
        }

        foreach (["equipamentos", "produtos", "carregamentos_ativos", "comandos"] as $field) {
            if (!array_key_exists($field, $snapshot) || !is_array($snapshot[$field])) {
                throw new RuntimeException("Resposta do servidor central está incompleta ({$field}).");
            }
        }
        if (array_key_exists("romaneios_cancelados", $snapshot) && !is_array($snapshot["romaneios_cancelados"])) {
            throw new RuntimeException("Resposta do servidor central contém uma lista de romaneios cancelados inválida.");
        }
        if (array_key_exists("romaneios_disponiveis", $snapshot) && !is_array($snapshot["romaneios_disponiveis"])) {
            throw new RuntimeException("Resposta do servidor central contém uma lista de romaneios disponíveis inválida.");
        }

        if (count($snapshot["equipamentos"]) !== 1) {
            throw new RuntimeException(
                "A instalação industrial aceita uma Dala por PC e deve receber exatamente a Dala vinculada a este PC.",
            );
        }
        if ((int) ($snapshot["equipamentos"][0]["id"] ?? 0) !== (int) $remoteInstallation["equipment_id"]) {
            throw new RuntimeException("A Dala recebida não corresponde ao vínculo deste PC industrial.");
        }

        foreach ($snapshot["equipamentos"] as $equipment) {
            $port = is_array($equipment) ? filter_var($equipment["plc_port"] ?? null, FILTER_VALIDATE_INT) : false;
            if (!is_array($equipment) || (int) ($equipment["id"] ?? 0) < 1
                || trim((string) ($equipment["equipment_code"] ?? "")) === ""
                || $port === false || $port < 1 || $port > 65535) {
                throw new RuntimeException("Resposta do servidor central contém uma Dala inválida.");
            }
        }

        foreach ($snapshot["produtos"] as $product) {
            if (!is_array($product)
                || (int) ($product["id"] ?? 0) < 1
                || trim((string) ($product["code"] ?? "")) === ""
                || trim((string) ($product["name"] ?? "")) === ""
                || !is_array($product["barcodes"] ?? null)
            ) {
                throw new RuntimeException("Resposta do servidor central contém um produto inválido.");
            }
        }

        foreach (($snapshot["romaneios_cancelados"] ?? []) as $manifest) {
            if (!is_array($manifest)
                || (int) ($manifest["id"] ?? 0) < 1
                || trim((string) ($manifest["number"] ?? "")) === ""
                || strtoupper(trim((string) ($manifest["status"] ?? ""))) !== "CANCELADO"
            ) {
                throw new RuntimeException("Resposta do servidor central contém um cancelamento de romaneio inválido.");
            }
        }

        foreach (($snapshot["romaneios_disponiveis"] ?? []) as $manifest) {
            if (!is_array($manifest)
                || (int) ($manifest["id"] ?? 0) < 1
                || trim((string) ($manifest["number"] ?? "")) === ""
                || !in_array(strtoupper(trim((string) ($manifest["status"] ?? ""))), ["IMPORTADO", "AGUARDANDO"], true)
                || !is_array($manifest["trucks"] ?? null)
                || $manifest["trucks"] === []
            ) {
                throw new RuntimeException("Resposta do servidor central contém um romaneio disponível inválido.");
            }
            $scheduledDate = trim((string) ($manifest["scheduled_date"] ?? ""));
            $date = \DateTime::createFromFormat("!Y-m-d", $scheduledDate);
            if ($date === false || $date->format("Y-m-d") !== $scheduledDate) {
                throw new RuntimeException("Resposta do servidor central contém uma data de romaneio inválida.");
            }
            foreach ($manifest["trucks"] as $truck) {
                if (!is_array($truck)
                    || (int) ($truck["id"] ?? 0) < 1
                    || !placa_caminhao_valida((string) ($truck["plate"] ?? ""))
                    || !is_array($truck["items"] ?? null)
                ) {
                    throw new RuntimeException("Resposta do servidor central contém um caminhão de romaneio inválido.");
                }
                foreach ($truck["items"] as $item) {
                    $quantity = is_array($item)
                        ? filter_var($item["planned_quantity"] ?? null, FILTER_VALIDATE_INT)
                        : false;
                    if (!is_array($item)
                        || trim((string) ($item["code"] ?? "")) === ""
                        || $quantity === false || $quantity < 1
                    ) {
                        throw new RuntimeException("Resposta do servidor central contém um item de romaneio inválido.");
                    }
                }
            }
        }

        $allowedStates = ["AGUARDANDO", "PREPARANDO", "CARREGANDO", "PAUSADO", "FINALIZANDO", "EMERGENCIA", "FINALIZADO"];
        $allowedManifestStatuses = ["IMPORTADO", "AGUARDANDO", "EM_ANDAMENTO", "FINALIZADO", "CANCELADO"];
        foreach ($snapshot["carregamentos_ativos"] as $loading) {
            if (!is_array($loading)
                || (int) ($loading["id"] ?? 0) < 1
                || (int) ($loading["romaneio_id"] ?? 0) < 1
                || (int) ($loading["truck_id"] ?? 0) < 1
                || !in_array(strtoupper(trim((string) ($loading["state"] ?? ""))), $allowedStates, true)
                || !in_array(strtoupper(trim((string) ($loading["romaneio_status"] ?? ""))), $allowedManifestStatuses, true)
                || !is_array($loading["items"] ?? null)
            ) {
                throw new RuntimeException("Resposta do servidor central contém um carregamento inválido.");
            }
        }

        foreach ($snapshot["comandos"] as $command) {
            if (!is_array($command)
                || (int) ($command["id"] ?? 0) < 1
                || (int) ($command["carregamento_id"] ?? 0) < 1
                || (int) ($command["equipment_id"] ?? 0) < 1
            ) {
                throw new RuntimeException("Resposta do servidor central contém um comando inválido.");
            }
        }
    }
}
