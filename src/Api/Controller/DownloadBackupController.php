<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DownloadBackupController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $filename = $request->getAttribute('filename');
        $backupPath = storage_path('backups/' . $filename);

        if (!file_exists($backupPath)) {
            return new JsonResponse(['error' => 'Backup not found'], 404);
        }

        $response = new Response();
        $response = $response->withHeader('Content-Type', 'application/zip');
        $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response = $response->withHeader('Content-Length', filesize($backupPath));
        
        $response->getBody()->write(file_get_contents($backupPath));

        return $response;
    }
}
