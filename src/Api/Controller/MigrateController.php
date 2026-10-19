<?php

namespace T11N\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use T11N\MigrationTool\Service\MigrationService;

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
            $actor = RequestUtil::getActor($request);
            $actor->assertAdmin();

            $body = $request->getParsedBody();
            $filename = basename((string) Arr::get($body, 'filename', ''));

            if ($filename === '' || $filename === '.' || pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
                return new JsonResponse(['success' => false, 'error' => 'Invalid filename'], 400);
            }

            $zipPath = storage_path('migration_upload/' . $filename);

            // An empty password field means "keep the current password"
            $dbPass = Arr::get($body, 'db_pass');
            if ($dbPass === null || $dbPass === '') {
                $dbPass = $this->migrationService->getCurrentDbPassword();
            }

            $configData = [
                'new_url' => Arr::get($body, 'new_url'),
                'db_host' => Arr::get($body, 'db_host', 'localhost'),
                'db_name' => Arr::get($body, 'db_name'),
                'db_user' => Arr::get($body, 'db_user'),
                'db_pass' => $dbPass,
            ];

            $result = $this->migrationService->performMigration($zipPath, $configData);

            return new JsonResponse($result);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
