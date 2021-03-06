<?php

/**
 * Emulates Apaches's mod_uid or Nginx's ngx_http_userid_module module
 *
 * Usage:
 * <pre>
 * $uid = new Castanet_Userid;
 * $uid->enable()
 *     ->start();
 * </pre>
 */
class Castanet_Userid
{
    const NOTE_NAME_SET = 'uid_set';
    const NOTE_NAME_GOT = 'uid_got';
    const NOTE_NAME_MERGED = 'uid';
    const SEQUENCER_V1 = 1;
    const SEQUENCER_V2 = 0x03030302;

    protected static $configNames = array('name', 'domain', 'p3p', 'path', 'expires', 'service', 'timestamp', 'startValue');
    private static $SEQUENCER = self::SEQUENCER_V2;

    private $enabled = false;
    private $name    = 'uid';
    private $domain  = null;
    private $p3p     = false;
    private $path    = '/';
    private $expires = null;
    private $service;
    private $timestamp;
    private $startValue;
    private $sequencer;

    public static function refreshSequencer()
    {
        self::$SEQUENCER = self::SEQUENCER_V2;
    }

    public static function createFromCookie($cookieValue)
    {
        $uid = new self;

        $props = self::parseCookie($cookieValue);
        $uid->service    = $props['service'];
        $uid->timestamp  = $props['timestamp'];
        $uid->startValue = $props['startValue'];
        $uid->sequencer  = $props['sequencer'];

        return $uid;
    }

    public function __construct()
    {
        $this->sequencer = self::$SEQUENCER;
        $this->expires = time()+60*60*24*365;
        self::$SEQUENCER += 0x100;
        if (self::$SEQUENCER < 0x03030302) {
            self::$SEQUENCER = 0x03030302;
        }
    }

    public function start(array $cookie=array())
    {
        if (! $this->enabled) {
            return;
        }
        if (empty($cookie)) {
            $cookie = $_COOKIE;
        }
        if (isset($cookie[$this->name])) {
            $props = self::parseCookie($cookie[$this->name]);
            $this->service    = $props['service'];
            $this->timestamp  = $props['timestamp'];
            $this->startValue = $props['startValue'];
            $this->sequencer  = $props['sequencer'];
            $noteName = self::NOTE_NAME_GOT;
        } else {
            $noteName = self::NOTE_NAME_SET;
            setrawcookie($this->name, $this->toCookie(), $this->expires, $this->path, $this->domain);
        }
        $logValue = $this->toLog();
        $this->setNote(self::NOTE_NAME_MERGED, $logValue, false);
        return $this->setNote($noteName, $logValue);
    }

    /**
     * @return Castanet_Userid
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (boolean)$enabled;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getEnabled()
    {
        return $this->enabled;
    }

    /**
     * @return Castanet_Userid
     */   
    public function enable()
    {
        return $this->setEnabled(true);
    }

    /**
     * @return Castanet_Userid
     */
    public function disable()
    {
        return $this->setEnabled(false);
    }

    /**
     * @return boolean
     */
    public function isEnabled()
    {
        return $this->getEnabled();
    }

    /**
     * @return boolean
     */
    public function isDisabled()
    {
        return ! $this->getEnabled();
    }

    public function getConfig($name)
    {
        if (! in_array($name, self::$configNames)) {
            return;
        }
        return $this->$name;
    }

    /**
     * Usage:
     * <pre>
     * $uid->setConfig('name', 'castanet');
     * </pre>
     * 
     * @return Castanet_Userid
     */
    public function setConfig($name, $value)
    {
        if (in_array($name, self::$configNames)) {
            $this->$name = $value;
        }
        return $this;
    }

    /**
     * @param array $configs
     * @return Castanet_Userid
     */
    public function setConfigs(array $configs)
    {
        foreach ($configs as $name => $value) {
            $this->setConfig($name, $value);
        }
        return $this;
    }

    public function getService()
    {
        if (! $this->service) {
            $this->service = isset($_SERVER['SERVER_ADDR'])
                           ? ip2long($_SERVER['SERVER_ADDR'])
                           : ip2long('1');
        }
        return $this->service;
    }

    public function getTimestamp()
    {
        if (! $this->timestamp) {
            $this->timestamp = $_SERVER['REQUEST_TIME']
                             ? $_SERVER['REQUEST_TIME']
                             : time();
        }
        return $this->timestamp;
    }

    public function getStartValue()
    {
        if (! $this->startValue) {
            list($usec, $sec) = explode(' ', microtime());
            $this->startValue = (((int)$usec * 1000 * 1000 / 20) << 16 | getmypid());
        }
        return $this->startValue;
    }

    public function toLog()
    {
        return sprintf('%08X%08X%08X%08X',
                       $this->htonl($this->getService()),
                       $this->htonl($this->getTimestamp()),
                       $this->htonl($this->getStartValue()),
                       $this->htonl($this->sequencer));
    }

    public function toCookie()
    {
        $buf = '';
        foreach (array($this->getService(), $this->getTimestamp(), $this->getStartValue(), $this->sequencer) as $seed) {
            $buf .= pack('N*', $seed);
        }
        return base64_encode($buf);
    }

    public function uidToLog(array $seeds)
    {
        return sprintf('%08X%08X%08X%08X',
                       $seeds[0],
                       $seeds[1],
                       $seeds[2],
                       $seeds[3]);
    }

    public function htonl($integer)
    {
        $hex = sprintf('%08X', $integer);
        if (strlen($hex) <= 2) {
            return $integer;
        }
        $unpacked = unpack('H*', strrev(pack('H*', $hex)));
        return hexdec($unpacked[1]);
    }

    /**
     * @return String|false previous note value.
     *                      false if failed to set note.
     */
    protected function setNote($name, $value, $noteName=true)
    {
        $note = $noteName ? "{$name}={$value}" : $value;
        if (function_exists('apache_note')) {
            return apache_note($name, $note);
        }
    }

    protected static function parseCookie($cookieValue)
    {
        $decoded = base64_decode($cookieValue);
        $unpacked = unpack('N*', $decoded);
        return array(
            'service'    => $unpacked[1],
            'timestamp'  => $unpacked[2],
            'startValue' => $unpacked[3],
            'sequencer'  => $unpacked[4]
        );
    }
}
