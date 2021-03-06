<?php

namespace QafooLabs\Profiler;

class CurlBackend implements Backend
{
    const API_HOSTNAME = 'profiler.qafoolabs.com';

    private $certificationFile;
    private $connectionTimeout;
    private $timeout;
    private $proxy;

    public function __construct($certificationFile = null, $connectionTimeout = 3, $timeout = 3)
    {
        $this->certificationFile = $certificationFile;
        $this->connectionTimeout = $connectionTimeout;
        $this->timeout = $timeout;
        $this->proxy = $this->detectProxySettings();
    }

    public function setProxy($proxy)
    {
        $this->proxy = $proxy;
    }

    public function getProxy()
    {
        return $this->proxy;
    }

    public function storeProfile(array $data)
    {
        if (function_exists("curl_init") === false) {
            return;
        }

        $data['server'] = gethostname();

        $this->request(
            "https://profiler.qafoolabs.com/api/profile/create",
            $data,
            $data['apiKey'],
            $data['op']
        );
    }

    public function storeMeasurement(array $data)
    {
        $this->request("https://profiler.qafoolabs.com/api/performance", array(
            'server' => gethostname(),
            'time' => time(),
            'apps' => array(
                $data['apiKey'] => array(
                    'operations' => array(
                        $data['op'] => array(
                            'lastRev' => time()+84600,
                            'cnt' => 1,
                            'err' => 0,
                            'wt' => array(
                                array(
                                    'wt' => $data['wt'],
                                    'mem' => $data['mem'],
                                    'c' => $data['c'],
                                )
                            )
                        )
                    )
                )
            )
        ));
    }

    public function storeError(array $data)
    {
    }

    private function request($url, $data, $apiKey = null, $operation = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectionTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($this->certificationFile) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $this->certificationFile);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $headers = array(
            "Content-Type: application/json+gzip",
            "User-Agent: QafooLabs Profiler Collector DevMode"
        );

        if ($apiKey) {
            $headers[] = 'X-Profiler-Key: ' . $apiKey;
        }
        if ($operation) {
            $headers[] = 'X-Profiler-Op: ' . $operation;
        }

        curl_setopt($ch, CURLOPT_FAILONERROR,true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, gzencode(json_encode($data)));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (curl_exec($ch) === false) {
            syslog(LOG_WARNING, "Qafoo Profiler DevMode cURL failed: " . curl_error($ch));
        }
    }

    private function detectProxySettings()
    {
        $proxy = getenv('https_proxy') ?: getenv('http_proxy');
        if (!$proxy) {
            return null;
        }

        $noProxy = explode(',', getenv('no_proxy'));
        foreach ($noProxy as $current) {
            if ($current === self::API_HOSTNAME || (substr($current, 0, 1) === '.' && substr(self::API_HOSTNAME, -strlen($current)) === $current)) {
                return null;
            }
        }

        return $proxy;
    }
}
