<?php

require_once __DIR__ . '/../includes/DomainHierarchy.php';

function assertDomainHierarchySame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALLO: {$message}\nEsperado: " . var_export($expected, true) . "\nObtenido: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$result = DomainHierarchy::build([
    ['name' => 'mail.api.ebone.es'],
    ['name' => 'example.com'],
    ['name' => 'Z.EBONE.ES'],
    ['name' => 'ebone.es'],
    ['name' => 'api.ebone.es'],
    ['name' => ''],
    ['invalid' => 'ignored'],
]);

assertDomainHierarchySame(
    [
        ['name' => 'ebone.es', 'depth' => 0, 'is_subdomain' => false, 'root' => 'ebone.es'],
        ['name' => 'api.ebone.es', 'depth' => 1, 'is_subdomain' => true, 'root' => 'ebone.es'],
        ['name' => 'mail.api.ebone.es', 'depth' => 2, 'is_subdomain' => true, 'root' => 'ebone.es'],
        ['name' => 'z.ebone.es', 'depth' => 1, 'is_subdomain' => true, 'root' => 'ebone.es'],
        ['name' => 'example.com', 'depth' => 0, 'is_subdomain' => false, 'root' => 'example.com'],
    ],
    $result,
    'Debe ordenar cada dominio principal seguido de sus subdominios por niveles.'
);

assertDomainHierarchySame(
    [
        ['name' => 'orphan.department.co.uk', 'depth' => 0, 'is_subdomain' => false, 'root' => 'orphan.department.co.uk'],
    ],
    DomainHierarchy::build([['name' => 'orphan.department.co.uk']]),
    'No debe inferir un dominio principal que no esté en la lista de Plesk.'
);

fwrite(STDOUT, "OK: DomainHierarchyTest\n");
