<?php
/**
 * This is NOT a freeware, use is subject to license terms.
 *
 * @copyright Copyright (c) 2010-2099 Jinan Larva Information Technology Co., Ltd.
 * @link http://www.larva.com.cn/
 */
namespace Larva\Ip2Region;

use Illuminate\Support\Facades\Facade;

/**
 * Class Ip2Region
 * @method static array|mixed find(string $ip)
 * @method static array|mixed memorySearch(string $ip)
 * @method static array|mixed binarySearch(string $ip)
 * @method static array|mixed btreeSearch(string $ip)
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class Ip2Region extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'ip2region';
    }
}