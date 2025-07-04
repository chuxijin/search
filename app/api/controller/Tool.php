<?php

namespace app\api\controller;

use think\App;
use think\facade\Request;
use think\facade\Cache;
use app\api\QfShop;
use app\model\User as Usermodel;
use app\model\Ads as Adsmodel;
use app\model\Feedback as FeedbackModel;
use app\model\SourceCategory as SourceCategoryModel;

class Tool extends QfShop
{
    /**
     * 系统配置参数
     *
     * @return void
     */
    public function getConfig()
    {
        $data = [
            'app_name'        => Config('qfshop.app_name'),
            'qcode'   => getimgurl(Config('qfshop.qcode')),
            'logo'   => getimgurl(Config('qfshop.logo')),
            'app_description'   => Config('qfshop.app_description'),
        ];
        return jok('获取成功',$data);
    }
    /**
     * 上传图片
     *
     * @return void
     */
    public function Upload()
    {
        // 获取当前登录的用户信息
        $userInfo = $this->getLoginUser();
        
        try {
            $file = request()->file('file');
        } catch (\Exception $error) {
            return jerr('上传文件失败，请检查你的文件！');
        }
        $Usermodel = new Usermodel();
        $data = $Usermodel->Upload($file, $userInfo);
        return jok('上传成功',$data);
    }

    /**
     * 根据广告位关键词获取广告图片列表
     * 
     * @return void
     */
    public function getAdsCode()
    {
        $Adsmodel = new Adsmodel();
        $data = $Adsmodel->getAdsCode(input(''));
        return jok('获取成功',$data);
    }

    /**
     * 用户反馈
     * 
     * @return void
     */
    public function feedback()
    {
        $data = input('');
        if (empty($data['content'])) {
            return jerr("请输入要看的内容");
        }
        $FeedbackModel = new FeedbackModel();
        $FeedbackModel->save(['content' => $data['content']]);
        return jok('已反馈');
    }
    
    

    /**
     * 获取首页排行榜数据
     *
     * @return void
     */
    public function ranking()
    {
        $channel = input('channel');
        $is_m = input('is_m')??0;
        
        if (empty($channel)) {
            return [];
        }
    
        // 使用 ThinkPHP 提供的 runtime_path() 函数获取 runtime 目录路径
        $cacheDir = runtime_path('cache'); // runtime/cache 目录
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true); // 确保缓存目录存在
        }
    
        // 根据 channel 值生成缓存文件名
        $cacheFile = $cacheDir . "ranking_data_{$channel}.cache";
        $cacheTime = 12*3600; // 缓存时间为 12 小时
    
        // 检查缓存文件是否存在且在缓存时间内
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
            // 从缓存中读取数据
            $data = json_decode(file_get_contents($cacheFile), true);
        } else {
            $data = [];
            if (!empty($channel)) {
                $queryParams =  array(
                    "area" =>  "全部",
                    "year" =>  "全部",
                    "channel" =>  $channel,
                    "rank_type" =>  "最热",
                    "cate" =>  "全部",
                    "from" =>  "hot_page",
                    "start" =>  0,
                    "hit" =>  Config('qfshop.ranking_num') ?? 1,
                );
                $res = curlHelper("https://biz.quark.cn/api/trending/ranking/getYingshiRanking", "GET", null, [], $queryParams)['body'];
                $res = json_decode($res, true);
                try {
                    foreach ($res['data']['hits']['hit']['item'] as $key => $value) {
                        $data[] = array(
                            "title" => $value['title'],
                            "src" => $value['src'],
                            "ranking" => $value['ranking'],
                            "hot_score" => $value['hot_score'],
                            "desc" => $value['desc'],
                        );
                    }
                } catch (Exception $error) {
                    $data = [];
                }
    
                // 将数据缓存到文件中
                file_put_contents($cacheFile, json_encode($data));
            }
        }
        
        if($is_m==1){
             $ranking_m_num = Config('qfshop.ranking_m_num') ?? 6;
            $data = array_slice($data, 0, $ranking_m_num);
        }
       
        return jok('获取成功', $data);
    }


    /**
     * 网页端全网搜接口
     *
     * @return void
     */
    public function Qsearch()
    {
        $title = input('title');
        $list = [];


        $userAgent = Request::header('user-agent');
        // 定义常见爬虫的 User-Agent 关键字
        $bots = ['Googlebot', 'Bingbot', 'Baiduspider'];
        foreach ($bots as $bot) {
            if (strpos($userAgent, $bot) !== false) {
                return jerr('该接口禁止爬虫访问');
            }
        }

        if (empty($title)) {
            return jok('临时资源获取成功', $list);
        }
        
        $keys = Request::ip()."_".$title;
        if(Cache::get($keys) == 1){
            return jerr('调用太过频繁啦');
        }
        Cache::set($keys, 1, 10);

        $bController = app(\app\api\controller\Other::class);
        $list = $bController->all_search($title);

        Cache::delete($keys); 
        return jok('临时资源获取成功', $list);
    }

    /**
     * 按时间整理文件夹中的文件
     * POST接口：将指定文件夹中的文件按创建时间移动到目标文件夹的年/月/日结构中
     *
     * @return void
     */
    public function organizeFiles()
    {
        $source_folder_id = input('source_folder_id');
        $target_folder_id = input('target_folder_id');
        
        if (empty($source_folder_id) || empty($target_folder_id)) {
            return jerr('参数不完整，请提供源文件夹ID和目标文件夹ID');
        }
        
        try {
            // 获取源文件夹中的所有文件
            $transfer = new \netdisk\Transfer();
            $sourceFiles = $transfer->getFiles(0, $source_folder_id);
            
            if ($sourceFiles['code'] !== 200) {
                return jerr('获取源文件夹文件列表失败：' . $sourceFiles['message']);
            }
            
            if (empty($sourceFiles['data'])) {
                return jok('源文件夹为空，无需整理', [
                    'total_items' => 0,
                    'moved_items' => 0,
                    'failed_items' => 0
                ]);
            }
            
            // 获取所有项目（包括文件和文件夹）
            $itemsToProcess = $sourceFiles['data'];
            
            $totalItems = count($itemsToProcess);
            $movedItems = 0;
            $failedItems = 0;
            $moveResults = [];
            
            foreach ($itemsToProcess as $item) {
                try {
                    $itemType = $item['file_type'] == 0 ? '文件夹' : '文件';
                    
                    // 获取项目的创建时间 - 使用统一的时间处理逻辑
                    $createTime = parseTimestamp($item);
                    
                    // 创建目标日期文件夹结构
                    $targetDateFolderId = $this->createDateFolderStructure(
                        $target_folder_id, 
                        $createTime
                    );
                    
                    if ($targetDateFolderId === false) {
                        $failedItems++;
                        $moveResults[] = [
                            'item_name' => $item['file_name'],
                            'item_type' => $itemType,
                            'status' => 'failed',
                            'reason' => '创建日期文件夹失败'
                        ];
                        continue;
                    }
                    
                    // 移动项目到目标文件夹
                    $moveResult = $this->moveFileToFolder($item['fid'], $targetDateFolderId);
                    
                    if ($moveResult['success']) {
                        $movedItems++;
                        $moveResults[] = [
                            'item_name' => $item['file_name'],
                            'item_type' => $itemType,
                            'status' => 'success',
                            'target_path' => date('Y年n月j日', $createTime)
                        ];
                    } else {
                        $failedItems++;
                        $moveResults[] = [
                            'item_name' => $item['file_name'],
                            'item_type' => $itemType,
                            'status' => 'failed',
                            'reason' => $moveResult['reason']
                        ];
                    }
                    
                } catch (\Exception $e) {
                    $failedItems++;
                    $moveResults[] = [
                        'item_name' => $item['file_name'],
                        'item_type' => isset($itemType) ? $itemType : '未知',
                        'status' => 'failed',
                        'reason' => $e->getMessage()
                    ];
                }
            }
            
            return jok('项目整理完成', [
                'total_items' => $totalItems,
                'moved_items' => $movedItems,
                'failed_items' => $failedItems,
                'details' => $moveResults
            ]);
            
        } catch (\Exception $e) {
            return jerr('项目整理过程中出现错误：' . $e->getMessage());
        }
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
    
    /**
     * 移动文件到指定文件夹
     *
     * @param string $fileId 文件ID
     * @param string $targetFolderId 目标文件夹ID
     * @return array 返回移动结果
     */
    private function moveFileToFolder($fileId, $targetFolderId)
    {
        try {
            $pan = new \netdisk\pan\QuarkPan();
            
            // 模拟input参数
            $_POST['file_ids'] = [$fileId];
            $_POST['target_folder_id'] = $targetFolderId;
            
            $result = $pan->moveFiles();
            
            // 清理临时参数
            unset($_POST['file_ids']);
            unset($_POST['target_folder_id']);
            
            if ($result['code'] === 200) {
                return [
                    'success' => true,
                    'target_path' => $targetFolderId
                ];
            } else {
                return [
                    'success' => false,
                    'reason' => $result['message']
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'reason' => $e->getMessage()
            ];
        }
    }
}
