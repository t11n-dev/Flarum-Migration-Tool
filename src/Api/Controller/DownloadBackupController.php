<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Stream;

class DownloadBackupController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $filename = basename($request->getAttribute('filename'));
            $backupPath = storage_path('backups/' . $filename);

            if (!file_exists($backupPath) || !is_readable($backupPath)) {
                return new JsonResponse(['error' => 'Backup not found or not readable'], 404);
            }

            // Important: Use a clean response to avoid any previous output buffering
            $body = new Stream($backupPath, 'rb');
            
            return (new Response($body))
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string)filesize($backupPath))
                ->withHeader('Cache-Control', 'no-cache, private')
                ->withHeader('Content-Transfer-Encoding', 'binary');
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
