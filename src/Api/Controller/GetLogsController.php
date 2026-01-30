<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;
use Nauhyuh\MigrationTool\Service\MigrationService;

class GetLogsController extends AbstractShowController
{
    protected $migrationService;

    public function __construct(MigrationService $migrationService)
    {
        $this->migrationService = $migrationService;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $logs = $this->migrationService->getLogs(200);

        return [
            'success' => true,
            'logs' => $logs
        ];
    }
}
