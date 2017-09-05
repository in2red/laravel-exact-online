<?php

namespace PendoNL\LaravelExactOnline\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use PendoNL\LaravelExactOnline\LaravelExactOnline;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

class LaravelExactOnlineServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');

        $this->loadViewsFrom(__DIR__.'/../views', 'laravelexactonline');

        $this->publishes([
            __DIR__.'/../views' => base_path('resources/views/vendor/laravelexactonline'),
            __DIR__.'/../exact.api.json' => storage_path('exact.api.json'),
            __DIR__.'/../config/laravel-exact-online.php' => config_path('laravel-exact-online.php')
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->alias(LaravelExactOnline::class, 'laravel-exact-online');

        $this->app->singleton('Exact\Connection', function() {

            $config = LaravelExactOnline::loadConfig();

            $connection = new \Picqer\Financials\Exact\Connection();
            $logger = new Logger('EXACT API');
            $logger->pushHandler(new StreamHandler(storage_path().'/logs/laravel-' . date('Y-m-d') . '.log', Logger::DEBUG));
            $logger->pushHandler(new FirePHPHandler());

            $middleware = Middleware::log(
                $logger,
                new MessageFormatter('method: {method} uri: {uri} body:{req_body} - response:{res_body} headers:{req_headers} - response:{res_headers}')
            );
            $connection->insertMiddleWare($middleware);
            $connection->setRedirectUrl(route('exact.callback'));
            $connection->setExactClientId(config('laravel-exact-online.exact_client_id'));
            $connection->setExactClientSecret(config('laravel-exact-online.exact_client_secret'));
            $connection->setBaseUrl('https://start.exactonline.' . config('laravel-exact-online.exact_country_code'));
            if(config('laravel-exact-online.exact_division') !== '') {
                $connection->setDivision(config('laravel-exact-online.exact_division'));
            }

            if(isset($config->exact_authorisationCode)) {
                $connection->setAuthorizationCode($config->exact_authorisationCode);
            }
            if(isset($config->exact_accessToken)) {
                $connection->setAccessToken(unserialize($config->exact_accessToken));
            }
            if(isset($config->exact_refreshToken)) {
                $connection->setRefreshToken(decrypt($config->exact_refreshToken));
            }
            if(isset($config->exact_tokenExpires)) {
                // $connection->setTokenExpires($config->exact_tokenExpires);
            }

            try {

                if(isset($config->exact_authorisationCode)) {
                    $connection->connect();

                    $config->exact_accessToken = serialize($connection->getAccessToken());
                    $config->exact_refreshToken = encrypt($connection->getRefreshToken());
                    $config->exact_tokenExpires = $connection->getTokenExpires();

                    LaravelExactOnline::storeConfig($config);
                }

            } catch (\Exception $e)
            {
                throw new \Exception('Could not connect to Exact: ' . $e->getMessage());
            }

			return $connection;
        });
    }
}
