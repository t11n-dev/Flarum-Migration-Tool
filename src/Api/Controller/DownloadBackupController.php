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
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $filename = $request->getAttribute('filename');
        $backupPath = storage_path('backups/' . $filename);

        if (!file_exists($backupPath)) {
            return new JsonResponse(['error' => 'Backup not found'], 404);
        }

        $stream = new Stream($backupPath, 'r');

        return (new Response($stream))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', filesize($backupPath));
    }
}
