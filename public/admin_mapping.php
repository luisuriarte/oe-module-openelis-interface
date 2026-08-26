<?php

/**
 * Admin page for mapping OpenEMR procedure codes to OpenELIS test IDs.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once dirname(__FILE__, 6) . '/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('Acceso denegado');
    exit;
}

$perPage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page_unmapped = max(1, (int)($_GET['page_unmapped'] ?? 1));
$page_mapped = max(1, (int)($_GET['page_mapped'] ?? 1));

// ── Procesar acciones POST ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_mapping') {
        $procedureCode = trim($_POST['procedure_code'] ?? '');
        $procedureName = trim($_POST['procedure_name'] ?? '');
        $elisTestId = trim($_POST['openelis_test_id'] ?? '');
        $elisTestName = trim($_POST['openelis_test_name'] ?? '');

        if ($procedureCode !== '' && $elisTestId !== '') {
            $sql = "INSERT INTO mod_openelis_code_mapping
                        (openemr_procedure_code, openemr_procedure_name, openelis_test_id, openelis_test_name, is_active)
                    VALUES (?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        openemr_procedure_name = VALUES(openemr_procedure_name),
                        openelis_test_id = VALUES(openelis_test_id),
                        openelis_test_name = VALUES(openelis_test_name),
                        is_active = 1";
            sqlStatement($sql, [$procedureCode, $procedureName, $elisTestId, $elisTestName]);
        }

        $searchParam = $search !== '' ? '&search=' . attr_url($search) : '';
        header('Location: admin_mapping.php?saved=1' . $searchParam);
        exit;
    }

    if ($action === 'toggle_active') {
        $mappingId = (int)($_POST['mapping_id'] ?? 0);
        if ($mappingId > 0) {
            $sql = "UPDATE mod_openelis_code_mapping SET is_active = NOT is_active WHERE id = ?";
            sqlStatement($sql, [$mappingId]);
        }

        $searchParam = $search !== '' ? '&search=' . attr_url($search) : '';
        header('Location: admin_mapping.php?toggled=1' . $searchParam);
        exit;
    }
}

// ── Parámetros de búsqueda ──────────────────────────────────────────────

$whereExtra = '';
$paramsExtra = [];
if ($search !== '') {
    $whereExtra = " AND (pt.name LIKE ? OR pt.procedure_code LIKE ? OR pt.standard_code LIKE ?)";
    $like = '%' . $search . '%';
    $paramsExtra = [$like, $like, $like];
}

// ── Conteos para paginación ─────────────────────────────────────────────

$countBase = "FROM procedure_type pt
    LEFT JOIN mod_openelis_code_mapping m ON pt.procedure_code = m.openemr_procedure_code
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord'" . $whereExtra;

$countUnmapped = sqlQuery(
    "SELECT COUNT(*) AS total " . $countBase . " AND m.id IS NULL",
    $paramsExtra
);
$totalUnmapped = (int)($countUnmapped['total'] ?? 0);
$totalPagesUnmapped = max(1, (int)ceil($totalUnmapped / $perPage));

$countMapped = sqlQuery(
    "SELECT COUNT(*) AS total " . $countBase . " AND m.id IS NOT NULL",
    $paramsExtra
);
$totalMapped = (int)($countMapped['total'] ?? 0);
$totalPagesMapped = max(1, (int)ceil($totalMapped / $perPage));

// ── Datos: procedimientos sin mapeo ─────────────────────────────────────

$offsetUnmapped = ($page_unmapped - 1) * $perPage;
$rsUnmapped = sqlStatement(
    "SELECT pt.procedure_code, pt.name, pt.standard_code
    FROM procedure_type pt
    LEFT JOIN mod_openelis_code_mapping m ON pt.procedure_code = m.openemr_procedure_code
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord' AND m.id IS NULL" . $whereExtra . "
    ORDER BY pt.name
    LIMIT ? OFFSET ?",
    array_merge($paramsExtra, [$perPage, $offsetUnmapped])
);

$unmapped = [];
while ($row = sqlFetchArray($rsUnmapped)) {
    $unmapped[] = $row;
}

// ── Datos: procedimientos con mapeo ─────────────────────────────────────

$offsetMapped = ($page_mapped - 1) * $perPage;
$rsMapped = sqlStatement(
    "SELECT pt.procedure_code, pt.name, pt.standard_code,
            m.id AS mapping_id, m.openelis_test_id, m.openelis_test_name, m.is_active
    FROM procedure_type pt
    INNER JOIN mod_openelis_code_mapping m ON pt.procedure_code = m.openemr_procedure_code
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord'" . $whereExtra . "
    ORDER BY pt.name
    LIMIT ? OFFSET ?",
    array_merge($paramsExtra, [$perPage, $offsetMapped])
);

$mapped = [];
while ($row = sqlFetchArray($rsMapped)) {
    $mapped[] = $row;
}

$csrfToken = CsrfUtils::collectCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt("Mapeo códigos OpenELIS"); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .mapping-section { margin-bottom: 2rem; }
        .badge-active { background-color: #198754; color: #fff; }
        .badge-inactive { background-color: #dc3545; color: #fff; }
        .form-inline-row { display: none; }
        .form-inline-row.open { display: table-row; }
        .standard-code { font-size: 0.85em; color: #6c757d; }
    </style>
</head>
<body class="container-fluid">
    <div class="row mt-3 mb-3">
        <div class="col">
            <h3><?php echo xlt("Mapeo de códigos OpenEMR ↔ OpenELIS"); ?></h3>
            <p class="text-muted">
                <?php echo xlt("Asocie los procedimientos de laboratorio de OpenEMR con las pruebas de OpenELIS."); ?>
            </p>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo xlt("Mapeo guardado correctamente."); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['toggled'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?php echo xlt("Estado del mapeo actualizado."); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Búsqueda -->
    <div class="row mb-3">
        <div class="col-md-8 col-lg-6">
            <form method="get" class="input-group">
                <input type="text" class="form-control" name="search"
                       placeholder="<?php echo attr("Buscar por nombre, código o estándar..."); ?>"
                       value="<?php echo attr($search); ?>">
                <button type="submit" class="btn btn-primary"><?php echo xlt("Buscar"); ?></button>
                <?php if ($search !== ''): ?>
                    <a href="admin_mapping.php" class="btn btn-outline-secondary"><?php echo xlt("Limpiar"); ?></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- ── Procedimientos sin mapeo ─────────────────────────────────── -->
    <div class="mapping-section">
        <h5>
            <?php echo xlt("Procedimientos sin mapeo"); ?>
            <span class="badge bg-secondary"><?php echo text($totalUnmapped); ?></span>
        </h5>

        <?php if (empty($unmapped)): ?>
            <div class="alert alert-light border">
                <?php echo xlt("No hay procedimientos sin mapeo."); ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt("Código"); ?></th>
                            <th><?php echo xlt("Nombre"); ?></th>
                            <th><?php echo xlt("Estándar"); ?></th>
                            <th class="text-end"><?php echo xlt("Acción"); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($unmapped as $row): ?>
                        <tr id="unmapped-<?php echo attr($row['procedure_code']); ?>">
                            <td><code><?php echo text($row['procedure_code']); ?></code></td>
                            <td><?php echo text($row['name']); ?></td>
                            <td class="standard-code">
                                <?php echo $row['standard_code'] !== '' ? text($row['standard_code']) : '—'; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="toggleRow('unmapped-<?php echo attr($row['procedure_code']); ?>-form')">
                                    <?php echo xlt("Asignar"); ?>
                                </button>
                            </td>
                        </tr>
                        <tr class="form-inline-row" id="unmapped-<?php echo attr($row['procedure_code']); ?>-form">
                            <td colspan="4">
                                <form method="post" class="row g-2 align-items-end">
                                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="action" value="save_mapping">
                                    <input type="hidden" name="procedure_code" value="<?php echo attr($row['procedure_code']); ?>">
                                    <input type="hidden" name="procedure_name" value="<?php echo attr($row['name']); ?>">
                                    <div class="col-auto">
                                        <label class="form-label small"><?php echo xlt("ID prueba OpenELIS"); ?></label>
                                        <input type="text" class="form-control form-control-sm" name="openelis_test_id" required
                                               placeholder="<?php echo attr("Ej: 42"); ?>">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small"><?php echo xlt("Nombre prueba OpenELIS"); ?></label>
                                        <input type="text" class="form-control form-control-sm" name="openelis_test_name"
                                               placeholder="<?php echo attr("Ej: Glucosa"); ?>">
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-success"><?php echo xlt("Guardar"); ?></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                onclick="toggleRow('unmapped-<?php echo attr($row['procedure_code']); ?>-form')">
                                            <?php echo xlt("Cancelar"); ?>
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php renderPagination('page_unmapped', $page_unmapped, $totalPagesUnmapped, $search); ?>
        <?php endif; ?>
    </div>

    <!-- ── Mapeos configurados ──────────────────────────────────────── -->
    <div class="mapping-section">
        <h5>
            <?php echo xlt("Mapeos configurados"); ?>
            <span class="badge bg-secondary"><?php echo text($totalMapped); ?></span>
        </h5>

        <?php if (empty($mapped)): ?>
            <div class="alert alert-light border">
                <?php echo xlt("No hay mapeos configurados."); ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt("Código EMR"); ?></th>
                            <th><?php echo xlt("Nombre EMR"); ?></th>
                            <th><?php echo xlt("Estándar"); ?></th>
                            <th><?php echo xlt("ID ELIS"); ?></th>
                            <th><?php echo xlt("Nombre ELIS"); ?></th>
                            <th><?php echo xlt("Estado"); ?></th>
                            <th class="text-end"><?php echo xlt("Acciones"); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mapped as $row): ?>
                        <tr id="mapped-<?php echo attr($row['mapping_id']); ?>">
                            <td><code><?php echo text($row['procedure_code']); ?></code></td>
                            <td><?php echo text($row['name']); ?></td>
                            <td class="standard-code">
                                <?php echo $row['standard_code'] !== '' ? text($row['standard_code']) : '—'; ?>
                            </td>
                            <td><code><?php echo text($row['openelis_test_id']); ?></code></td>
                            <td><?php echo text($row['openelis_test_name'] ?? ''); ?></td>
                            <td>
                                <?php if ($row['is_active']): ?>
                                    <span class="badge badge-active"><?php echo xlt("Activo"); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-inactive"><?php echo xlt("Inactivo"); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="toggleRow('mapped-<?php echo attr($row['mapping_id']); ?>-form')">
                                    <?php echo xlt("Editar"); ?>
                                </button>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('<?php echo xla("¿Está seguro de cambiar el estado?"); ?>');">
                                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="mapping_id" value="<?php echo attr($row['mapping_id']); ?>">
                                    <?php if ($row['is_active']): ?>
                                        <button type="submit" class="btn btn-sm btn-outline-warning"><?php echo xlt("Desactivar"); ?></button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success"><?php echo xlt("Activar"); ?></button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <tr class="form-inline-row" id="mapped-<?php echo attr($row['mapping_id']); ?>-form">
                            <td colspan="7">
                                <form method="post" class="row g-2 align-items-end">
                                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="action" value="save_mapping">
                                    <input type="hidden" name="procedure_code" value="<?php echo attr($row['procedure_code']); ?>">
                                    <input type="hidden" name="procedure_name" value="<?php echo attr($row['name']); ?>">
                                    <div class="col-auto">
                                        <label class="form-label small"><?php echo xlt("ID prueba OpenELIS"); ?></label>
                                        <input type="text" class="form-control form-control-sm" name="openelis_test_id" required
                                               value="<?php echo attr($row['openelis_test_id']); ?>">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small"><?php echo xlt("Nombre prueba OpenELIS"); ?></label>
                                        <input type="text" class="form-control form-control-sm" name="openelis_test_name"
                                               value="<?php echo attr($row['openelis_test_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-success"><?php echo xlt("Guardar"); ?></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                onclick="toggleRow('mapped-<?php echo attr($row['mapping_id']); ?>-form')">
                                            <?php echo xlt("Cancelar"); ?>
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php renderPagination('page_mapped', $page_mapped, $totalPagesMapped, $search); ?>
        <?php endif; ?>
    </div>

<script>
function toggleRow(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.toggle('open');
    }
}
</script>
</body>
</html>
<?php

// ── Funciones auxiliares ────────────────────────────────────────────────

function renderPagination(string $paramName, int $currentPage, int $totalPages, string $search): void
{
    if ($totalPages <= 1) {
        return;
    }

    echo '<nav><ul class="pagination pagination-sm justify-content-center">';

    // Anterior
    if ($currentPage > 1) {
        $prevParams =([$paramName => $currentPage - 1]);
        if ($search !== '') {
            $prevParams['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($prevParams) . '">'
            . xlt("Anterior") . '</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">' . xlt("Anterior") . '</span></li>';
    }

    // Números de página (máximo 5 visibles)
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $p =([$paramName => 1]);
        if ($search !== '') {
            $p['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($p) . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $p =([$paramName => $i]);
        if ($search !== '') {
            $p['search'] = $search;
        }
        if ($i === $currentPage) {
            echo '<li class="page-item active"><span class="page-link">' . text($i) . '</span></li>';
        } else {
            echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($p) . '">' . text($i) . '</a></li>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $p =([$paramName => $totalPages]);
        if ($search !== '') {
            $p['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($p) . '">' . text($totalPages) . '</a></li>';
    }

    // Siguiente
    if ($currentPage < $totalPages) {
        $nextParams =([$paramName => $currentPage + 1]);
        if ($search !== '') {
            $nextParams['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($nextParams) . '">'
            . xlt("Siguiente") . '</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">' . xlt("Siguiente") . '</span></li>';
    }

    echo '</ul></nav>';
}
