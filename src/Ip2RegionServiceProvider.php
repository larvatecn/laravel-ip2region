<?php
/**
 * This is NOT a freeware, use is subject to license terms.
 *
 * @copyright Copyright (c) 2010-2099 Jinan Larva Information Technology Co., Ltd.
 * @link http://www.larva.com.cn/
 */
namespace Larva\Ip2Region;

use Illuminate\Support\ServiceProvider;

/**
 * Class Ip2RegionServiceProvider
 * @author Tongle Xu <xutongle@gmail.com>
 */
class Ip2RegionServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ip2region.php' => config_path('ip2region.php'),
            ], 'ip2region-config');
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if (!$this->app->configurationIsCached()) {
            $this->mergeConfigFrom(__DIR__ . '/../config/ip2region.php', 'ip2region');
        }

        $this->app->singleton('ip2region', function ($app) {
            return new Ip2RegionManager(config('ip2region.path'));
        });
    }
}