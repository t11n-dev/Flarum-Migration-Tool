<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Nauhyuh\MigrationTool\Service\MigrationService;

class MigrationStatusController implements RequestHandlerInterface
{
    protected $migrationService;

    public function __construct(MigrationService $migrationService)
    {
        $this->migrationService = $migrationService;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $uploadPath = storage_path('migration_upload');
        $uploads = [];

        if (is_dir($uploadPath)) {
            $files = scandir($uploadPath);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                    $filePath = $uploadPath . '/' . $file;
                    $uploads[] = [
                        'filename' => $file,
                        'size' => filesize($filePath),
                        'uploaded' => filemtime($filePath)
                    ];
                }
            }
        }

        return new JsonResponse([
            'success' => true,
            'uploads' => $uploads,
            'backup_count' => count($this->migrationService->listBackups())
        ]);
    }
}
