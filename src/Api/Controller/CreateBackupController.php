<?php

namespace T11N\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use T11N\MigrationTool\Service\MigrationService;

class CreateBackupController implements RequestHandlerInterface
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
            $includeUploads = Arr::get($body, 'include_uploads', true);
            $includeVendor = Arr::get($body, 'include_vendor', false);
            $includeConfig = Arr::get($body, 'include_config', false);

            $result = $this->migrationService->createBackup($includeUploads, $includeVendor, $includeConfig);
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
