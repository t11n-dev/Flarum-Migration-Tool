<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Nauhyuh\MigrationTool\Service\MigrationService;

class GetLogsController implements RequestHandlerInterface
{
    protected $migrationService;

    public function __construct()
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->migrationService = new MigrationService();
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $logs = $this->migrationService->getLogs(200);

            return new JsonResponse([
                'success' => true,
                'logs' => $logs
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
