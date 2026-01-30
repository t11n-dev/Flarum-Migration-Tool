<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Nauhyuh\MigrationTool\Service\MigrationService;

class MigrateController implements RequestHandlerInterface
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

            $body = $request->getParsedBody();
            $filename = Arr::get($body, 'filename');
            
            $zipPath = storage_path('migration_upload/' . $filename);
            
            $configData = [
                'new_url' => Arr::get($body, 'new_url'),
                'db_host' => Arr::get($body, 'db_host', 'localhost'),
                'db_name' => Arr::get($body, 'db_name'),
                'db_user' => Arr::get($body, 'db_user'),
                'db_pass' => Arr::get($body, 'db_pass'),
            ];

            $result = $this->migrationService->performMigration($zipPath, $configData);

            return new JsonResponse($result);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
