<?php

declare(strict_types=1);

// Fachada de compatibilidade: os endpoints ainda incluem este arquivo.
// A ordem mantém configuração e sessão antes das funções que as utilizam.
require_once __DIR__ . "/observabilidade.php";
require_once __DIR__ . "/paginacao.php";
require_once __DIR__ . "/ambiente.php";
require_once __DIR__ . "/sessao.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/dispositivos.php";
require_once __DIR__ . "/autenticacao.php";
require_once __DIR__ . "/eventos.php";
