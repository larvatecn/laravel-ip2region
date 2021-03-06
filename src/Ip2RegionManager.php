<?php
/**
 * This is NOT a freeware, use is subject to license terms
 * @copyright Copyright (c) 2010-2099 Jinan Larva Information Technology Co., Ltd.
 * @link http://www.larva.com.cn/
 */

namespace Larva\Ip2Region;

use Exception;

class Ip2RegionManager
{
    public const INDEX_BLOCK_LENGTH = 12;
    public const TOTAL_HEADER_LENGTH = 8192;

    /**
     * db file handler
     */
    private $dbFileHandler = null;

    /**
     * header block info
     */
    private $headerSip = null;
    private $headerPtr = null;
    private $headerLen = 0;

    /**
     * super block index info
     */
    private $firstIndexPtr = 0;
    private $lastIndexPtr = 0;
    private $totalBlocks = 0;

    /**
     * for memory mode only
     *  the original db binary string
     */
    private $dbBinStr = null;
    private $dbFile = null;

    private $defaultSearch = 'btree';

    /**
     * construct method
     *
     * @param string ip2regionFile
     */
    public function __construct($ip2regionFile = null)
    {
        $this->dbFile = is_null($ip2regionFile) ? __DIR__ . '/ip2region.db' : $ip2regionFile;
    }

    /**
     * IP搜索
     * @param string $ip
     * @return array|null
     * @throws Exception
     */
    public function find($ip): ?array
    {
        if ($this->defaultSearch == 'memory') {
            return $this->memorySearch($ip);
        } else if ($this->defaultSearch == 'binary') {
            return $this->binarySearch($ip);
        } else {
            return $this->btreeSearch($ip);
        }
    }

    /**
     * all the db binary string will be loaded into memory
     * then search the memory only and this will a lot faster than disk base search
     * @Note:
     * invoke it once before put it to public invoke could make it thread safe
     *
     * @param string $ip
     * @return array|null
     * @throws Exception
     */
    public function memorySearch($ip): ?array
    {
        //check and load the binary string for the first time
        if ($this->dbBinStr == null) {
            $this->dbBinStr = file_get_contents($this->dbFile);
            if ($this->dbBinStr == false) {
                throw new Exception("Fail to open the db file {$this->dbFile}");
            }
            $this->firstIndexPtr = self::getLong($this->dbBinStr, 0);
            $this->lastIndexPtr = self::getLong($this->dbBinStr, 4);
            $this->totalBlocks = ($this->lastIndexPtr - $this->firstIndexPtr) / self::INDEX_BLOCK_LENGTH + 1;
        }
        if (is_string($ip)) $ip = self::safeIp2long($ip);
        //binary search to define the data
        $l = 0;
        $h = $this->totalBlocks;
        $dataPtr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = $this->firstIndexPtr + $m * self::INDEX_BLOCK_LENGTH;
            $sip = self::getLong($this->dbBinStr, $p);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($this->dbBinStr, $p + 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($this->dbBinStr, $p + 8);
                    break;
                }
            }
        }
        //not matched just stop it here
        if ($dataPtr == 0) return null;
        //get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);
        return [
            'city_id' => self::getLong($this->dbBinStr, $dataPtr),
            'region' => substr($this->dbBinStr, $dataPtr + 4, $dataLen - 4),
        ];
    }

    /**
     * get the data block through the specified ip address or long ip numeric with binary search algorithm
     *
     * @param string ip
     * @return array|null Array or NULL for any error
     * @throws Exception
     */
    public function binarySearch($ip): ?array
    {
        //check and conver the ip address
        if (is_string($ip)) $ip = self::safeIp2long($ip);
        if ($this->totalBlocks == 0) {
            //check and open the original db file
            if ($this->dbFileHandler == null) {
                $this->dbFileHandler = fopen($this->dbFile, 'r');
                if ($this->dbFileHandler == false) {
                    throw new Exception("Fail to open the db file {$this->dbFile}");
                }
            }
            fseek($this->dbFileHandler, 0);
            $superBlock = fread($this->dbFileHandler, 8);
            $this->firstIndexPtr = self::getLong($superBlock, 0);
            $this->lastIndexPtr = self::getLong($superBlock, 4);
            $this->totalBlocks = ($this->lastIndexPtr - $this->firstIndexPtr) / self::INDEX_BLOCK_LENGTH + 1;
        }
        //binary search to define the data
        $l = 0;
        $h = $this->totalBlocks;
        $dataPtr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = $m * self::INDEX_BLOCK_LENGTH;
            fseek($this->dbFileHandler, $this->firstIndexPtr + $p);
            $buffer = fread($this->dbFileHandler, self::INDEX_BLOCK_LENGTH);
            $sip = self::getLong($buffer, 0);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($buffer, 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($buffer, 8);
                    break;
                }
            }
        }
        //not matched just stop it here
        if ($dataPtr == 0) return null;
        //get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);
        fseek($this->dbFileHandler, $dataPtr);
        $data = fread($this->dbFileHandler, $dataLen);
        return [
            'city_id' => self::getLong($data, 0),
            'region' => substr($data, 4),
        ];
    }

    /**
     * get the data block associated with the specified ip with b-tree search algorithm
     * @Note: not thread safe
     *
     * @param string ip
     * @return array|null Array for NULL for any error
     * @throws Exception
     */
    public function btreeSearch($ip): ?array
    {
        if (is_string($ip)) $ip = self::safeIp2long($ip);
        //check and load the header
        if ($this->headerSip == null) {
            //check and open the original db file
            if ($this->dbFileHandler == null) {
                $this->dbFileHandler = fopen($this->dbFile, 'r');
                if ($this->dbFileHandler == false) {
                    throw new Exception("Fail to open the db file {$this->dbFile}");
                }
            }
            fseek($this->dbFileHandler, 8);
            $buffer = fread($this->dbFileHandler, self::TOTAL_HEADER_LENGTH);

            //fill the header
            $idx = 0;
            $this->headerSip = [];
            $this->headerPtr = [];
            for ($i = 0; $i < self::TOTAL_HEADER_LENGTH; $i += 8) {
                $startIp = self::getLong($buffer, $i);
                $dataPtr = self::getLong($buffer, $i + 4);
                if ($dataPtr == 0) break;
                $this->headerSip[] = $startIp;
                $this->headerPtr[] = $dataPtr;
                $idx++;
            }
            $this->headerLen = $idx;
        }

        //1. define the index block with the binary search
        $l = 0;
        $h = $this->headerLen;
        $sptr = 0;
        $eptr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);

            //perfetc matched, just return it
            if ($ip == $this->headerSip[$m]) {
                if ($m > 0) {
                    $sptr = $this->headerPtr[$m - 1];
                    $eptr = $this->headerPtr[$m];
                } else {
                    $sptr = $this->headerPtr[$m];
                    $eptr = $this->headerPtr[$m + 1];
                }

                break;
            }

            //less then the middle value
            if ($ip < $this->headerSip[$m]) {
                if ($m == 0) {
                    $sptr = $this->headerPtr[$m];
                    $eptr = $this->headerPtr[$m + 1];
                    break;
                } elseif ($ip > $this->headerSip[$m - 1]) {
                    $sptr = $this->headerPtr[$m - 1];
                    $eptr = $this->headerPtr[$m];
                    break;
                }
                $h = $m - 1;
            } else {
                if ($m == $this->headerLen - 1) {
                    $sptr = $this->headerPtr[$m - 1];
                    $eptr = $this->headerPtr[$m];
                    break;
                } elseif ($ip <= $this->headerSip[$m + 1]) {
                    $sptr = $this->headerPtr[$m];
                    $eptr = $this->headerPtr[$m + 1];
                    break;
                }
                $l = $m + 1;
            }
        }

        //match nothing just stop it
        if ($sptr == 0) return null;

        //2. search the index blocks to define the data
        $blockLen = $eptr - $sptr;
        fseek($this->dbFileHandler, $sptr);
        $index = fread($this->dbFileHandler, $blockLen + self::INDEX_BLOCK_LENGTH);

        $dataPtr = 0;
        $l = 0;
        $h = $blockLen / self::INDEX_BLOCK_LENGTH;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = (int)($m * self::INDEX_BLOCK_LENGTH);
            $sip = self::getLong($index, $p);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($index, $p + 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($index, $p + 8);
                    break;
                }
            }
        }

        //not matched
        if ($dataPtr == 0) return null;

        //3. get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);

        fseek($this->dbFileHandler, $dataPtr);
        $data = fread($this->dbFileHandler, $dataLen);
        return [
            'city_id' => self::getLong($data, 0),
            'region' => substr($data, 4),
        ];
    }

    private static function getAddress($region)
    {

    }

    /**
     * safe self::safeIp2long function
     *
     * @param string ip
     *
     * @return false|int|string
     */
    public static function safeIp2long($ip)
    {
        $ip = ip2long($ip);
        // convert signed int to unsigned int if on 32 bit operating system
        if ($ip < 0 && PHP_INT_SIZE == 4) {
            $ip = sprintf("%u", $ip);
        }
        return $ip;
    }

    /**
     * read a long from a byte buffer
     *
     * @param string b
     * @param integer offset
     * @return int|string
     */
    public static function getLong($b, $offset)
    {
        $val = (
            (ord($b[$offset++])) |
            (ord($b[$offset++]) << 8) |
            (ord($b[$offset++]) << 16) |
            (ord($b[$offset]) << 24)
        );
        // convert signed int to unsigned int if on 32 bit operating system
        if ($val < 0 && PHP_INT_SIZE == 4) {
            $val = sprintf("%u", $val);
        }
        return $val;
    }

    /**
     * 销毁资源
     */
    public function __destruct()
    {
        if ($this->dbFileHandler != null) {
            fclose($this->dbFileHandler);
        }
        $this->dbBinStr = null;
        $this->headerSip = null;
        $this->headerPtr = null;
    }
}