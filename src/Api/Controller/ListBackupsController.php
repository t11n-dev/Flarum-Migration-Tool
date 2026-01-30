<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;
use Nauhyuh\MigrationTool\Service\MigrationService;

class ListBackupsController extends AbstractShowController
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

        $backups = $this->migrationService->listBackups();

        return [
            'success' => true,
            'backups' => $backups
        ];
    }
}
