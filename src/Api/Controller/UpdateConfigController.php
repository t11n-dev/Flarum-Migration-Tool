<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;

class UpdateConfigController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->migrationService = new MigrationService();
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $body = $request->getParsedBody();
        
        $newUrl = Arr::get($body, 'url');
        $dbHost = Arr::get($body, 'db_host');
        $dbName = Arr::get($body, 'db_name');
        $dbUser = Arr::get($body, 'db_user');
        $dbPass = Arr::get($body, 'db_pass');

        try {
            $configPath = base_path('config.php');
            
            if (!file_exists($configPath)) {
                throw new \Exception('Config file not found');
            }

            $config = require $configPath;

            // Update values
            if ($newUrl) {
                $config['url'] = $newUrl;
            }
            if ($dbHost) {
                $config['database']['host'] = $dbHost;
            }
            if ($dbName) {
                $config['database']['database'] = $dbName;
            }
            if ($dbUser) {
                $config['database']['username'] = $dbUser;
            }
            if ($dbPass) {
                $config['database']['password'] = $dbPass;
            }

            // Write back
            $configContent = "<?php return " . var_export($config, true) . ";";
            file_put_contents($configPath, $configContent);

            return new JsonResponse([
                'success' => true,
                'message' => 'Configuration updated successfully'
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
