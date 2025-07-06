<?php

namespace QuarkPlugin;

use think\facade\Db;
use think\facade\Request;
use think\Exception;
use app\model\Source as SourceModel;
use app\model\SourceLog as SourceLogModel;

class QuarkPlugin
{
    protected $url;
    protected $model;
    protected $SourceLogModel;

    public function __construct()
    {
        // 第三方转存接口地址
        $this->url = "https://duanju.life";
        $this->model = new SourceModel();
        $this->SourceLogModel = new SourceLogModel();
        $this->source_category_id = 0;
    }

    public function getFiles($type=0,$pdir_fid=0)
    {
        $transfer = new \netdisk\Transfer();
        return $transfer->getFiles($type,$pdir_fid);
    }
    
    public function import($allData, $source_category_id)
    {
        $this->source_category_id = $source_category_id;

        $length = count($allData);
        $logId = $this->SourceLogModel->addLog('批量转入链接', $length);

        foreach ($allData as $data) {
            $this->processSingleData($data, $logId, $length, 1);
        }

        $this->SourceLogModel->editLog($logId, $length, '', '', 3);
    }

    public function transfer($allData, $source_category_id)
    {
        $this->source_category_id = $source_category_id;

        $length = count($allData);
        $logId = $this->SourceLogModel->addLog('批量转存他人链接', $length);

        foreach ($allData as $data) {
            $this->processSingleData($data, $logId, $length);
        }

        $this->SourceLogModel->editLog($logId, $length, '', '', 3);
    }

    public function transferAll($source_category_id, $day = 0)
    {
        if(empty($this->url)){
            return jerr('未配置转存接口地址');
        }

        @set_time_limit(999999);
        
        $this->source_category_id = $source_category_id;

        // 分页转存
        $page_no = 1;
        $allData = [];
        $logId = '';

        while (true) {
            $searchData = [
                'page_no' => $page_no,
                'page_size' => 10000,
                'type' => 2, //从旧到新排序  也就是先采集旧数据
                'day' => $day,  //等2时 用于每日更新  默认0是全部数据
                'category_id' => $this->source_category_id
            ];
            $res = curlHelper($this->url . "/api/search", "POST", $searchData)['body'];
            $res = json_decode($res, true);

            if ($res['code'] !== 200 || empty($res['data']['items'])) {
                break;
            }

            $dataList = $res['data'];
            $allData = array_merge($allData, $dataList['items']);
            $page_no++;

            if ($logId == '') {
                $name = $day == 2 ? '每日更新' : '全部转存';
                $logId = $this->SourceLogModel->addLog($name, $dataList['total_result']);
            }

            if ($page_no > 1000) {
                break;
            }
        }

        foreach ($allData as $data) {
            $this->processSingleData($data, $logId, count($allData));
        }

        $this->SourceLogModel->editLog($logId, count($allData), '', '', 3);
    }

    function processSingleData($value, $logId = 0, $total_result = 0, $isType = 0)
    {
        $detail = $this->model->where('title', $value['title'])->where('is_type', determineIsType($value['url']))->find();
        if (!empty($detail)) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'skip_num', '重复跳过转存');
            }
            return;
        }

        $url = $value['url'];
        $substring = strstr($url, 's/');
        if ($substring === false) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'fail_num', '资源地址格式有误');
            }
            return;
        }

        // 根据资源创建时间确定目标文件夹
        $target_folder_id = $this->createDateFolders($value);
        if ($target_folder_id === false) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'fail_num', '创建日期文件夹失败');
            }
            return;
        }

        $urlData = [
            'expired_type' => 1,  // 1正式资源 2临时资源
            'url' => $url,
            'code' => $value['code'] ?? '',
            'isType' => $isType,
            'to_pdir_fid' => $target_folder_id  // 设置目标文件夹
        ];

        $transfer = new \netdisk\Transfer();
        $res = $transfer->transfer($urlData);

        if ($res['code'] !== 200) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'fail_num', $res['message']);
            }
            return;
        }

        $title = empty($value['title']) ? preg_replace('/^\d+\./', '', $res['data']['title']) : $value['title'];
        $source_category_id = $value['source_category_id'] ?? $this->source_category_id;

        $data = [
            "title" => $title,
            "url" => $res['data']['share_url'],
            "is_type" => determineIsType($res['data']['share_url']),
            "code" => $res['data']['code'] ?? $value['code'] ?? '',
            "source_category_id" => $source_category_id,
            "update_time" => time(),
            "create_time" => time(),
            "fid" => is_array($res['data']['fid'] ?? '') ? json_encode($res['data']['fid']) : ($res['data']['fid'] ?? '')
        ];

        $this->model->insertGetId($data);
        if (!empty($logId)) {
            $this->SourceLogModel->editLog($logId, $total_result, 'new_num', '');
        }
    }

    /**
     * 根据资源创建时间创建年/月/日文件夹结构
     *
     * @param array $value 资源信息
     * @return string|false 返回最终文件夹ID，失败返回false
     */
    private function createDateFolders($value)
    {
        // 获取资源的创建时间 - 使用统一的时间处理逻辑
        $create_time = parseTimestamp($value);
        
        // 获取基础存储路径
        $base_folder_id = \Config('qfshop.quark_file');
        
        // 直接实现文件夹创建逻辑，避免实例化控制器
        return $this->createDateFolderStructure($base_folder_id, $create_time);
    }

    /**
     * 创建日期文件夹结构 (年/月/日)
     *
     * @param string $targetFolderId 目标文件夹ID
     * @param int $timestamp 时间戳
     * @return string|false 返回最终日期文件夹ID，失败返回false
     */
    private function createDateFolderStructure($targetFolderId, $timestamp)
    {
        $year = date('Y', $timestamp);
        $month = date('n', $timestamp) . '月';
        $day = date('j', $timestamp) . '日';
        
        $transfer = new \netdisk\Transfer();
        
        // 创建年文件夹
        $yearFolderId = $this->getOrCreateFolder($transfer, $targetFolderId, $year);
        if ($yearFolderId === false) {
            return false;
        }
        
        // 创建月文件夹
        $monthFolderId = $this->getOrCreateFolder($transfer, $yearFolderId, $month);
        if ($monthFolderId === false) {
            return false;
        }
        
        // 创建日文件夹
        $dayFolderId = $this->getOrCreateFolder($transfer, $monthFolderId, $day);
        
        return $dayFolderId;
    }
    
    /**
     * 获取或创建文件夹
     *
     * @param object $transfer Transfer对象
     * @param string $parentFid 父文件夹ID
     * @param string $folderName 文件夹名称
     * @return string|false 返回文件夹ID，失败返回false
     */
    private function getOrCreateFolder($transfer, $parentFid, $folderName)
    {
        // 获取父文件夹下的文件列表
        $files = $transfer->getFiles(0, $parentFid);
        
        if ($files['code'] !== 200) {
            return false;
        }
        
        // 检查是否已存在同名文件夹
        foreach ($files['data'] as $file) {
            if ($file['file_type'] == 0 && $file['file_name'] == $folderName) {
                return $file['fid'];
            }
        }
        
        // 文件夹不存在，创建新文件夹
        $pan = new \netdisk\pan\QuarkPan();
        
        // 模拟input参数
        $_POST['folder_name'] = $folderName;
        $_POST['parent_fid'] = $parentFid;
        
        $result = $pan->createFolder();
        
        // 清理临时参数
        unset($_POST['folder_name']);
        unset($_POST['parent_fid']);
        
        if ($result['code'] === 200) {
            return $result['data'];
        }
        
        return false;
    }


}
