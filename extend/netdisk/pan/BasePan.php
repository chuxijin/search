<?php
namespace netdisk\pan;

abstract class BasePan
{
    protected $urlHeader;
    protected $code;
    protected $url;
    protected $isType;
    protected $expired_type;
    protected $ad_fid;
    protected $stoken;
    protected $to_pdir_fid;

    public function __construct($config = [])
    {
        $this->code = $config['code'] ?? '';
        $this->url = $config['url'] ?? '';
        $this->isType = $config['isType'] ?? 0;
        $this->expired_type = $config['expired_type'] ?? 1;
        $this->ad_fid = $config['ad_fid'] ?? '';
        $this->stoken = $config['stoken'] ?? '';
        $this->to_pdir_fid = $config['to_pdir_fid'] ?? '';
    }

    abstract public function transfer($share_id);
}