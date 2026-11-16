<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Export\Exporter;
use Petitstats\Importers\PetitstatsImporter;

/*
 * GET  ?site=<id>|all&format=archive|csv|json  downloads the export as a ZIP file.
 * POST action=restore with a "file"            brings back every website of a Petitstats archive.
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
header('Content-Type: application/json; charset=utf-8');
Auth::requireAdmin();
@set_time_limit(0);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (($_POST['action'] ?? '') !== 'restore') {
            throw new InvalidArgumentException('Unknown action.');
        }
        $error = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('This archive is larger than the upload limit of your server (' . ini_get('upload_max_filesize') . '). Restore it with php bin/import.php --provider petitstats instead.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Choose the .zip archive exported from Petitstats.');
        }
        echo json_encode((new PetitstatsImporter())->restore($_FILES['file']['tmp_name']), JSON_UNESCAPED_SLASHES);
        exit;
    }

    $site = (string) ($_GET['site'] ?? 'all');
    $siteIds = $site === 'all' ? [] : [$site];
    $format = (string) ($_GET['format'] ?? 'archive');
    $path = (new Exporter())->export($siteIds, $format);
    try {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . Exporter::fileName($siteIds, $format) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
    } finally {
        @unlink($path);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
