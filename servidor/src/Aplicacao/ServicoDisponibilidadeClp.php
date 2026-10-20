<?php
declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioDisponibilidadeClp;
require_once __DIR__ . "/ExcecaoDisponibilidadeClp.php";
require_once __DIR__ . "/../Persistencia/RepositorioDisponibilidadeClp.php";

/**
 * Centraliza a regra de disponibilidade do CLP para comandos operacionais.
 * O CLP continua responsável pelos intertravamentos e pela segurança física.
 */
final class ServicoDisponibilidadeClp
{
    private const LIMITE_SEM_SINAL_SEGUNDOS = 3;

    public function __construct(private readonly RepositorioDisponibilidadeClp $disponibilidade)
    {
    }

    public function validarComando(int $companyId, int $equipmentId): void
    {
        $clp = $this->disponibilidade->obterEstado($companyId, $equipmentId);

        $limiteSemSinal = function_exists("\\limite_sinal_clp_segundos")
            ? \limite_sinal_clp_segundos()
            : self::LIMITE_SEM_SINAL_SEGUNDOS;
        $online =
            $clp &&
            $clp["status"] === "ONLINE" &&
            $clp["last_seen_at"] !== null &&
            (int) $clp["segundos_sem_sinal"] <= $limiteSemSinal;
        if ($online) {
            return;
        }

        throw new ExcecaoDisponibilidadeClp(
            "Comunicação com o CLP indisponível há mais de {$limiteSemSinal} segundos. Novos comandos foram bloqueados; o sistema tentará reconectar automaticamente.",
            423,
        );
    }
}
