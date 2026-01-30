<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;
use Nauhyuh\MigrationTool\Service\MigrationService;

class CreateBackupController extends AbstractShowController
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

        $body = $request->getParsedBody();
        $includeUploads = Arr::get($body, 'include_uploads', true);
        $includeVendor = Arr::get($body, 'include_vendor', false);

        try {
            $result = $this->migrationService->createBackup($includeUploads, $includeVendor);
            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
