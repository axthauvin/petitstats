<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Importers\ImporterRegistry;
use Petitstats\Sites\SiteRegistry;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/../../../vendor/autoload.php';
Auth::requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}


try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // 1. GET: List all import providers
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === 'providers' || empty($action))) {
        echo json_encode([
            'success' => true,
            'providers' => ImporterRegistry::getProvidersList(),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 2. Inspect an export file or folder without importing
    if ($action === 'inspect') {
        $providerId = $_POST['provider'] ?? 'umami';
        $importer = ImporterRegistry::getImporter($providerId);
        if (!$importer || !$importer->isAvailable()) {
            throw new InvalidArgumentException("Unsupported or unavailable provider '{$providerId}'.");
        }

        $sourcePath = null;
        $tempUploaded = null;

        if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $sourcePath = $_FILES['file']['tmp_name'];
            $tempUploaded = $sourcePath;
        } elseif (!empty($_POST['server_path'])) {
            $rawPath = trim($_POST['server_path']);
            // Allow relative to project root or absolute
            $projectRoot = dirname(__DIR__, 2);
            $candidate1 = $projectRoot . '/' . ltrim($rawPath, '/\\');
            $candidate2 = $rawPath;

            if (file_exists($candidate1)) {
                $sourcePath = realpath($candidate1);
            } elseif (file_exists($candidate2)) {
                $sourcePath = realpath($candidate2);
            } else {
                throw new InvalidArgumentException("File or folder not found: {$rawPath}");
            }
        } else {
            throw new InvalidArgumentException("Please provide a file to inspect.");
        }

        if (method_exists($importer, 'inspect')) {
            $info = $importer->inspect($sourcePath);
            echo json_encode(array_merge(['success' => true], $info, [
                'default_retention_days' => SiteRegistry::DEFAULT_RETENTION_DAYS,
                'max_retention_days' => SiteRegistry::MAX_RETENTION_DAYS,
            ]), JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // 3. POST: Execute Import
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $providerId = $_POST['provider'] ?? 'umami';
        $importer = ImporterRegistry::getImporter($providerId);

        if (!$importer) {
            http_response_code(400);
            echo json_encode(['error' => "Unknown import provider '{$providerId}'."]);
            exit;
        }

        if (!$importer->isAvailable()) {
            http_response_code(400);
            echo json_encode(['error' => "Provider '{$importer->getName()}' is coming soon and cannot be imported yet."]);
            exit;
        }

        $sourcePath = null;
        $tempUploaded = null;

        // Check for file upload
        if (isset($_FILES['file'])) {
            $fileError = $_FILES['file']['error'];
            if ($fileError === UPLOAD_ERR_INI_SIZE || $fileError === UPLOAD_ERR_FORM_SIZE) {
                http_response_code(413);
                $maxSize = ini_get('upload_max_filesize');
                echo json_encode([
                    'error' => "The uploaded ZIP file exceeds the PHP maximum upload limit ({$maxSize}). You can either increase 'upload_max_filesize' in php.ini, or specify a server/local path.",
                ]);
                exit;
            } elseif ($fileError !== UPLOAD_ERR_OK && $fileError !== UPLOAD_ERR_NO_FILE) {
                http_response_code(400);
                echo json_encode(['error' => "File upload error code: {$fileError}"]);
                exit;
            }

            if ($fileError === UPLOAD_ERR_OK) {
                $sourcePath = $_FILES['file']['tmp_name'];
                $tempUploaded = $sourcePath;
            }
        }

        // Check for server path if no uploaded file
        if (empty($sourcePath) && !empty($_POST['server_path'])) {
            $rawPath = trim($_POST['server_path']);
            $projectRoot = dirname(__DIR__, 2);
            $candidate1 = $projectRoot . '/' . ltrim($rawPath, '/\\');
            $candidate2 = $rawPath;

            if (file_exists($candidate1)) {
                $sourcePath = realpath($candidate1);
            } elseif (file_exists($candidate2)) {
                $sourcePath = realpath($candidate2);
            } else {
                http_response_code(404);
                echo json_encode(['error' => "Specified server path or file does not exist: {$rawPath}"]);
                exit;
            }
        }

        if (empty($sourcePath)) {
            http_response_code(400);
            echo json_encode(['error' => "Please upload a .zip export file or specify a local path."]);
            exit;
        }

        $siteId = trim($_POST['site_id'] ?? '');
        $siteName = trim($_POST['site_name'] ?? '');
        $siteDomain = trim($_POST['site_domain'] ?? '');

        // If siteId is empty, try to auto-generate from inspection
        if (empty($siteId) && method_exists($importer, 'inspect')) {
            try {
                $inspected = $importer->inspect($sourcePath);
                $siteId = $inspected['suggested_site_id'] ?? 'imported_site';
                if (empty($siteName)) {
                    $siteName = $inspected['suggested_name'] ?? $siteId;
                }
                if (empty($siteDomain)) {
                    $siteDomain = $inspected['detected_host'] ?? '';
                }
            } catch (Throwable $e) {
                $siteId = 'imported_site';
            }
        }

        if (empty($siteId)) {
            $siteId = 'imported_site';
        }

        $importOptions = ['name' => $siteName, 'domain' => $siteDomain];
        if (isset($_POST['retention_days']) && is_numeric($_POST['retention_days'])) {
            $importOptions['retention_days'] = (int) $_POST['retention_days'];
        }
        $result = $importer->import($sourcePath, $siteId, $importOptions);

        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
