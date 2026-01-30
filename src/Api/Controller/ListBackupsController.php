<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Nauhyuh\MigrationTool\Service\MigrationService;

class ListBackupsController implements RequestHandlerInterface
{
    protected $migrationService;

    public function __construct()
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->migrationService = new MigrationService();

            $this->migrationService = new MigrationService();
        $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $backups = $this->migrationService->listBackups();

            return new JsonResponse([
                'success' => true,
                'backups' => $backups
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
