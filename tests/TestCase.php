<?php

namespace Padosoft\MigrateCloudflareRules\Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\MigrateCloudflareRules\MigrateCloudflareRulesServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const SOURCE_TOKEN = 'source-token-1234';

    protected const SOURCE_ACCOUNT = 'src-account';

    protected const SOURCE_ZONE = 'src-zone';

    protected const DESTINATION_TOKEN = 'destination-token-5678';

    protected const DESTINATION_ACCOUNT = 'dst-account';

    protected const DESTINATION_ZONE = 'dst-zone';

    protected function getPackageProviders($app): array
    {
        return [
            MigrateCloudflareRulesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.default', 'null');
    }

    /**
     * Fill the package configuration with the test credentials.
     */
    protected function configureCredentials(): void
    {
        config()->set('migrate-cloudflare-rules.source.api_token', self::SOURCE_TOKEN);
        config()->set('migrate-cloudflare-rules.source.account_id', self::SOURCE_ACCOUNT);
        config()->set('migrate-cloudflare-rules.source.zone_id', self::SOURCE_ZONE);
        config()->set('migrate-cloudflare-rules.destination.api_token', self::DESTINATION_TOKEN);
        config()->set('migrate-cloudflare-rules.destination.account_id', self::DESTINATION_ACCOUNT);
        config()->set('migrate-cloudflare-rules.destination.zone_id', self::DESTINATION_ZONE);
    }

    /**
     * Fake the Cloudflare API with a "METHOD path" => response map.
     *
     * Keys are like "GET zones/src-zone/firewall/rules"; the path is matched against
     * the request URL without the https://api.cloudflare.com/client/v4/ prefix and
     * without the query string. Unmatched requests fail the test.
     *
     * @param  array<string, array|PromiseInterface>  $routes
     */
    protected function fakeCloudflare(array $routes): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $request) use ($routes) {
            $path = preg_replace('#^https://api\.cloudflare\.com/client/v4/#', '', strtok($request->url(), '?'));
            $key = $request->method().' '.$path;

            if (! array_key_exists($key, $routes)) {
                // Throw an \Error (not an \Exception) so the command's catch (Exception) blocks cannot swallow it
                throw new UnexpectedCloudflareCall("Unexpected Cloudflare API call: {$key}");
            }

            $response = $routes[$key];

            return is_array($response) ? Http::response($response) : $response;
        });
    }

    /**
     * Build a Cloudflare-like paginated list response.
     */
    protected function cfList(array $result, int $totalPages = 1): array
    {
        return [
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => $result,
            'result_info' => [
                'page' => 1,
                'per_page' => 50,
                'total_pages' => $totalPages,
                'count' => count($result),
                'total_count' => count($result),
            ],
        ];
    }

    /**
     * Build a Cloudflare-like single-object response.
     */
    protected function cfResult(array $result): array
    {
        return [
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => $result,
        ];
    }
}
