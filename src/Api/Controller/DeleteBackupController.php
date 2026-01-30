<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Nauhyuh\MigrationTool\Service\MigrationService;

class DeleteBackupController implements RequestHandlerInterface
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

            $filenameAttr = $request->getAttribute('filename');
            
            if (empty($filenameAttr)) {
                return new JsonResponse(['success' => false, 'error' => 'Filename parameter is missing'], 400);
            }

            $filename = basename($filenameAttr);
            
            if ($filename === '.' || empty($filename)) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid filename'], 400);
            }

            $result = $this->migrationService->deleteBackup($filename);
            
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
