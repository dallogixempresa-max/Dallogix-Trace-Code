<?php
declare(strict_types=1);

namespace App\Aplicacao;

use App\Persistencia\RepositorioLeituras;

final class ServicoLeituras
{
    public function __construct(private readonly RepositorioLeituras $leituras)
    {
    }

    public function listarPendentes(int $companyId, int $loadingId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $total = $this->leituras->contarPendentes($companyId, $loadingId);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        return [
            "data" => $this->leituras->listarPendentes($companyId, $loadingId, $perPage, $offset),
            "pagination" => [
                "page" => $page,
                "per_page" => $perPage,
                "total" => $total,
                "pages" => $pages,
            ],
        ];
    }
}
