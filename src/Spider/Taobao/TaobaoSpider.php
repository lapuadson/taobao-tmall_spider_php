<?php
/**
 * 淘宝、天猫商品详情爬虫抓取
 * Created by PhpStorm.
 * User: lapuad
 * Date: 2019/1/18
 * Time: 下午2:26
 */

namespace Spider\Taobao;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use phpQuery;

class TaobaoSpider
{
    //当前支持的域名
    private static $allow_domains = [
        'item.taobao.com',
        'detail.tmall.com'
    ];

    private static $crawl_tried_times = 3;    //爬虫抓取失败重复次数
    private static $goods_import_link_invalid = '1001|商品链接有误！';
    private static $goods_link_not_support = '1002|当前链接暂不支持！';
    private static $goods_import_failed = '1003|商品数据抓取失败！';

    /**
     * 发起http请求
     * @param $url
     * @param array $params
     * @param string $method
     * @param array $cookies
     * @param array $headers
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private static function httpRequest($url, $params = array(), $method = 'GET', $cookies = [], $headers = [])
    {
        $result = array(
            'status' => 200,
            'content' => '',
            'msg' => ''
        );

        $client = new Client();
        $options = array(
            'http_errors' => false,
            'verify' => false
        );

        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        if (!empty($cookies)) {
            $jar = new CookieJar();
            foreach ($cookies as $cookie) {
                $jar->setCookie($cookie);
            }

            $options['cookies'] = $jar;
        }

        if (!empty($params)) {
            if ($method == 'GET') {
                $options['query'] = $params;
            } else {
                $options['form_params'] = $params;
            }
        }

        try {
            $res = $client->request($method, $url, $options);
            //var_dump($jar->toArray());exit;
            $status_code = $res->getStatusCode();
            $content = $res->getBody()->getContents();
            $result['status'] = $status_code;
            $result['content'] = $content;
            if ($status_code == 200) {
                $result['msg'] = '请求成功！';
            } else {
                $result['msg'] = '请求失败！';
            }
        } catch (\GearmanException $exception) {
            $exception_msg = $exception->getMessage();
            $result['status'] = 500;
            $result['msg'] = $exception_msg;
        }

        return $result;
    }

    /**
     * 检测输入的URL是否合法
     * @param string $url 输入的URL地址
     * @return bool
     */
    private static function validateURL($url)
    {
        if (empty($url) || !preg_match('/^http[s]?:\/\/.+\..+/i', $url)) {
            return false;
        }

        return true;
    }

    /**
     * 获取一个网址的域名
     * @param $url
     * @return bool
     */
    private static function getUrlDomain($url)
    {
        $url = str_replace(['http://', 'https://'], '', $url);
        $parts = explode('/', $url);
        return isset($parts[0]) ? $parts[0] : false;
    }

    /**
     * 获取URL的协议
     * @param $url
     * @return bool
     */
    private static function getUrlProtocol($url)
    {
        $parts = explode(':', $url);
        return isset($parts[0]) ? $parts[0] : false;
    }

    /**
     * 格式化数据返回，返回数组
     * @param string $code
     * @param string $msg
     * @param null $data
     * @return array
     */
    private static function apiReturn($code = '200', $msg = '请求成功！', $data = null)
    {
        $result = array(
            'code' => isset($code) ? $code : 200,
            'msg' => isset($msg) ? $msg : '请求成功！',
            'data' => isset($data) ? $data : array()
        );

        if (empty($code) || $code == 200) {
            $result['code'] = 200;
            $result['msg'] = isset($msg) ? $msg : '请求成功！';
        } else {
            $code_arr = explode('|', $code);
            $result['code'] = isset($code_arr[0]) ? $code_arr[0] : 200;
            if (!empty($msg) && $msg != '请求成功！') {
                $result['msg'] = $msg;
            } else {
                $result['msg'] = isset($code_arr[1]) ? $code_arr[1] : '';
            }
        }

        if (empty($data)) {
            $result['data'] = array();
        } else {
            $result['data'] = $data;
        }

        return $result;
    }

    /**
     * 爬虫重复请求抓取封装
     * @param $url
     * @param array $params
     * @param string $method
     * @param array $cookies
     * @param array $headers
     * @return array|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private static function crawl($url, $params = array(), $method = 'GET', $cookies = [], $headers = [])
    {
        $page_result = null;
        for ($i = 0; $i < self::$crawl_tried_times; $i++) {
            $page_result = self::httpRequest($url, $params, $method, $cookies, $headers);
            if ($page_result['status'] == 200) {
                break;
            }
        }

        return $page_result;
    }

    /**
     * 根据商品链接抓取商品详情数据
     * @param string $url
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function importGoods($url)
    {
        set_time_limit(0);

        $url = urldecode($url);
        if (!self::validateURL($url)) {
            return self::apiReturn(self::$goods_import_link_invalid);
        }

        $domain = self::getUrlDomain($url);
        if (!in_array($domain, self::$allow_domains)) {
            return self::apiReturn(self::$goods_link_not_support);
        }

        $result = array();
        switch ($domain) {
            case 'item.taobao.com':
                $result = self::crawlGoodsFromTaobao($url);
                break;
            case 'detail.tmall.com':
                $result = self::crawlGoodsFromTmall($url);
                break;
        }

        return $result;
    }

    /**
     * 从淘宝抓取商品详情数据
     * @param $url
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected static function crawlGoodsFromTaobao($url)
    {
        $protocol = self::getUrlProtocol($url);
        $headers = array(
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'accept-encoding' => 'gzip, deflate, br',
            'accept-language' => 'zh-CN,zh;q=0.9,zh-TW;q=0.8,en;q=0.7,da;q=0.6,so;q=0.5',
            'cache-control' => 'max-age=0',
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.102 Safari/537.36'
        );

        $page_result = self::crawl($url, [], 'GET', [], $headers);
        if (empty($page_result) || $page_result['status'] != 200) {
            $error_msg = "crawl goods info from url failed, url: {$url}, response status code: {$page_result['status']}, error message: {$page_result['msg']}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $desc_matches = array();
        $desc_url_count = preg_match('/descUrl.+,/', $page_result['content'], $desc_matches);
        if (empty($desc_matches)) {
            $error_msg = "can not find desc url, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $desc_match_str = preg_replace('/descUrl.+\?/', '', $desc_matches[0]);
        $desc_url_parts = explode(':', $desc_match_str);
        $desc_url = '';
        if ($protocol == 'https') {
            $desc_url = "{$protocol}:" . str_replace(['\'', ','], '', trim($desc_url_parts[1]));
        } else {
            $desc_url = "{$protocol}:" . str_replace(['\'', ','], '', trim($desc_url_parts[0]));
        }

        if (empty($desc_url)) {
            $error_msg = "can not find desc url, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        //活动banner部分
        $asyn_matches = array();
        $asyn_url_count = preg_match('/\/\/hdc1\.alicdn\.com\/asyn\.htm.+/', $page_result['content'], $asyn_matches);
        $asyn_url = '';
        if (!empty($asyn_matches)) {
            $asyn_url = "{$protocol}:" . trim(str_replace(['\''], '', $asyn_matches[0]));
        }

        $top_html = '';
        $right_html = '';
        $bottom_html = '';
        if (!empty($asyn_url)) {
            $asyn_result = self::crawl($asyn_url, [], 'GET', [], $headers);
            if (!empty($asyn_result['content'])) {
                $top_html = self::handleImportGoodsAsynTop($asyn_result['content'], $url);
                $right_html = self::handleImportGoodsAsynRight($asyn_result['content'], $url);
                $bottom_html = self::handleImportGoodsAsynBottom($asyn_result['content'], $url);
            }
        }

        //把gbk编码转换为utf-8
        $page_html = mb_convert_encoding($page_result['content'], 'UTF-8', 'GBK');

        //抓取商品标题和图片
        $goods_title = '';
        $page_obj = phpQuery::newDocument($page_html);
        //$title_tag = pq('.tb-main-title:first');
        $title_tag = pq("title");
        if (!empty($title_tag)) {
            $goods_title = trim(pq($title_tag)->text());
            $goods_title = str_replace(["\n", "-淘宝网"], "", $goods_title);
        }

        if (empty($goods_title)) {
            $error_msg = "get goods title failed, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $goods_images = array();
        $main_img_tags = pq('li .tb-pic img');
        if (!empty($main_img_tags)) {
            foreach ($main_img_tags as $img_tag) {
                $img_src = pq($img_tag)->attr('data-src');
                $img_src = str_replace('_50x50.jpg', '', $img_src);
                if (!self::validateURL($img_src)) {
                    $img_src = 'https:' . $img_src;
                }

                $goods_images[] = $img_src;
            }
        }

        if (empty($goods_images)) {
            $error_msg = "get goods images has error, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        //抓取商品详情
        $desc_result = self::crawl($desc_url, [], 'GET', [], $headers);
        if (empty($desc_result) || $desc_result['status'] != 200) {
            $error_msg = "crawl goods desc failed, desc url: {$desc_url}, goods url: {$url}, response status code: {$desc_result['status']}, error message: {$desc_result['msg']}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $desc_content = $desc_result['content'];
        $desc_html = str_replace(["var desc='", "';", '\\'], '', $desc_content);
        $new_desc_html = self::handleImportGoodsDesc($desc_html, $url);
        if (empty($new_desc_html)) {
            $error_msg = "handle goods desc has error, desc url: {$desc_url}, goods url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $result = array(
            'title' => $goods_title,
            'images' => $goods_images,
            'desc' => $top_html . $new_desc_html . $right_html . $bottom_html
        );

        unset($page_obj);
        unset($page_result);
        unset($desc_result);
        return self::apiReturn(200, '获取成功！', $result);
    }

    /**
     * 从天猫抓取商品详情数据
     * @param $url
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected static function crawlGoodsFromTmall($url)
    {
        $protocol = self::getUrlProtocol($url);
        $headers = [];

        $page_result = self::crawl($url, [], 'GET', [], $headers);
        if (empty($page_result) || $page_result['status'] != 200) {
            $error_msg = "crawl goods info from url failed, url: {$url}, response status code: {$page_result['status']}, error message: {$page_result['msg']}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $desc_matches = array();
        $desc_url_count = preg_match('/httpsDescUrl.+fetchDcUrl/', $page_result['content'], $desc_matches);
        if (empty($desc_matches)) {
            $error_msg = "can not find desc url, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $desc_url = "{$protocol}:" . trim(str_replace(['httpsDescUrl":"', '","fetchDcUrl'], '', $desc_matches[0]));
        if (empty($desc_url)) {
            $error_msg = "can not find desc url, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        //活动banner部分
        $asyn_matches = array();
        $asyn_url_count = preg_match('/fetchDcUrl.+&userId=\d+/', $page_result['content'], $asyn_matches);
        $asyn_url = '';
        if (!empty($asyn_matches)) {
            $asyn_url = "{$protocol}:" . trim(str_replace(['fetchDcUrl":"'], '', $asyn_matches[0]));
        }

        $top_html = '';
        $right_html = '';
        $bottom_html = '';
        if (!empty($asyn_url)) {
            $asyn_result = self::crawl($asyn_url, [], 'GET', [], $headers);
            if (!empty($asyn_result['content'])) {
                $top_html = self::handleImportGoodsAsynTop($asyn_result['content'], $url);
                $right_html = self::handleImportGoodsAsynRight($asyn_result['content'], $url);
                $bottom_html = self::handleImportGoodsAsynBottom($asyn_result['content'], $url);
            }
        }

        //把gbk编码转换为utf-8
        $page_html = mb_convert_encoding($page_result['content'], 'UTF-8', 'GBK');

        //抓取商品标题和图片
        $goods_title = '';
        $page_obj = phpQuery::newDocument($page_html);
        $title_tag = pq('.tb-detail-hd h1');
        if (!empty($title_tag)) {
            $goods_title = trim(pq($title_tag)->text());
        }

        if (empty($goods_title)) {
            $error_msg = "get goods title failed, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $goods_images = array();
        $main_img_tags = pq('.tb-thumb-content li img');
        if (!empty($main_img_tags)) {
            foreach ($main_img_tags as $img_tag) {
                $img_src = pq($img_tag)->attr('src');
                $img_src = 'https:' . str_replace('_60x60q90.jpg', '', $img_src);
                $goods_images[] = $img_src;
            }
        }

        if (empty($goods_images)) {
            $error_msg = "get goods images has error, url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        //抓取商品详情
        $desc_result = self::crawl($desc_url, [], 'GET', [], $headers);
        if (empty($desc_result) || $desc_result['status'] != 200) {
            $error_msg = "crawl goods desc failed, desc url: {$desc_url}, goods url: {$url}, response status code: {$desc_result['status']}, error message: {$desc_result['msg']}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $desc_content = $desc_result['content'];
        $desc_html = str_replace(["var desc='", "';", '\\'], '', $desc_content);
        $new_desc_html = self::handleImportGoodsDesc($desc_html, $url);
        if (empty($new_desc_html)) {
            $error_msg = "handle goods desc has error, desc url: {$desc_url}, goods url: {$url}";
            return self::apiReturn(self::$goods_import_failed, $error_msg);
        }

        $result = array(
            'title' => $goods_title,
            'images' => $goods_images,
            'desc' => $top_html . $new_desc_html . $right_html . $bottom_html
        );

        unset($page_obj);
        unset($page_result);
        unset($desc_result);
        return self::apiReturn(200, '获取成功！', $result);
    }

    /**
     * 处理商品详情里的图片，上传OSS并替换html
     * @param $html
     * @param string $goods_url
     * @return bool|string
     */
    protected static function handleImportGoodsDesc($html, $goods_url = '')
    {
        //编码转换为utf-8
        $html = mb_convert_encoding($html, 'UTF-8', ['GBK', 'GB2312', 'UTF-8']);

        $doc_obj = phpQuery::newDocument($html);

        $img_tags = pq('img');
        if (!empty($img_tags)) {
            foreach ($img_tags as $img_tag) {
                $img_src = pq($img_tag)->attr('src');
                if (!self::validateURL($img_src)) {
                    $img_src = 'https:' . $img_src;
                }

                pq($img_tag)->attr("src", $img_src);
            }
        }

        //特殊标签须移除
        $special_texts = ['模板保护代码'];
        $special_p_tags = pq('p');
        if (!empty($special_p_tags)) {
            foreach ($special_p_tags as $p_tag) {
                if (in_array(trim(pq($p_tag)->text()), $special_texts)) {
                    pq($p_tag)->remove();
                }
            }
        }

        $special_div_tags = pq('div');
        if (!empty($special_div_tags)) {
            foreach ($special_div_tags as $div_tag) {
                if (in_array(trim(pq($div_tag)->text()), $special_texts)) {
                    pq($div_tag)->remove();
                }
            }
        }

        $content = str_replace(['\n', '\t', "\n", "\t"], '', trim($doc_obj->htmlOuter()));
        unset($html);
        unset($doc_obj);
        return $content;
    }

    /**
     * 处理商品详情顶部活动banner里的图片，上传OSS并替换html
     * @param $html
     * @param string $goods_url
     * @return bool|string
     */
    protected static function handleImportGoodsAsynTop($html, $goods_url = '')
    {
        //编码转换为utf-8
        $html = mb_convert_encoding($html, 'UTF-8', ['GBK', 'GB2312', 'UTF-8']);

        $asyn_matches = array();
        $asyn_match_count = preg_match('/"topRight":.+","userId/', $html, $asyn_matches);
        $asyn_html = '';
        if (!empty($asyn_matches)) {
            $asyn_html = trim(str_replace(['"topRight":"', '\r', '\n', '","userId'], '', $asyn_matches[0]));
            $asyn_html = stripcslashes($asyn_html);
        }

        $doc_obj = phpQuery::newDocument($asyn_html);

        //移除script标签
        pq('script')->remove();

        //移除空白区域
        $tb_modules = pq('.tb-module');
        if (!empty($tb_modules)) {
            foreach ($tb_modules as $tb_module) {
                if (trim(pq($tb_module)->html()) == '') {
                    pq($tb_module)->parents('.J_TModule')->remove();
                }
            }
        }

        $img_tags = pq('img');
        if (!empty($img_tags)) {
            foreach ($img_tags as $img_tag) {
                $img_src = pq($img_tag)->attr('data-ks-lazyload');
                if (empty($img_src)) {
                    $img_src = pq($img_tag)->attr('src');
                }

                if (empty($img_src)) {
                    continue;
                }

                if (!self::validateURL($img_src)) {
                    $img_src = 'https:' . $img_src;
                }

                pq($img_tag)->attr("src", $img_src);
                pq($img_tag)->removeAttr('data-ks-lazyload');
            }
        }

        $content = str_replace(['\n', '\t', "\n", "\t"], '', trim($doc_obj->htmlOuter()));
        unset($html);
        unset($doc_obj);
        return $content;
    }

    /**
     * 处理商品详情底部里的图片，上传OSS并替换html
     * @param $html
     * @param string $goods_url
     * @return bool|string
     */
    protected static function handleImportGoodsAsynBottom($html, $goods_url = '')
    {
        //编码转换为utf-8
        $html = mb_convert_encoding($html, 'UTF-8', ['GBK', 'GB2312', 'UTF-8']);

        $asyn_matches = array();
        $asyn_match_count = preg_match('/"bottomRight":.+","categoryName/', $html, $asyn_matches);
        $asyn_html = '';
        if (!empty($asyn_matches)) {
            $asyn_html = trim(str_replace(['"bottomRight":"', '\r', '\n', '","categoryName'], '', $asyn_matches[0]));
            $asyn_html = stripcslashes($asyn_html);
        }

        $doc_obj = phpQuery::newDocument($asyn_html);

        //移除script标签
        pq('script')->remove();

        //移除空白区域
        $tb_modules = pq('.tb-module');
        if (!empty($tb_modules)) {
            foreach ($tb_modules as $tb_module) {
                if (trim(pq($tb_module)->html()) == '') {
                    pq($tb_module)->parents('.J_TModule')->remove();
                }
            }
        }

        //特殊移除
        $div_module_tags = pq('.J_TModule');
        if (!empty($div_module_tags)) {
            foreach ($div_module_tags as $div_module_tag) {
                if (trim(pq($div_module_tag)->attr('data-title')) != '自定义内容区') {
                    pq($div_module_tag)->remove();
                }
            }
        }

        $img_tags = pq('img');
        if (!empty($img_tags)) {
            foreach ($img_tags as $img_tag) {
                $img_src = pq($img_tag)->attr('data-ks-lazyload');
                if (empty($img_src)) {
                    $img_src = pq($img_tag)->attr('src');
                }

                if (empty($img_src)) {
                    continue;
                }

                if (!self::validateURL($img_src)) {
                    $img_src = 'https:' . $img_src;
                }

                pq($img_tag)->attr("src", $img_src);
                pq($img_tag)->removeAttr('data-ks-lazyload');
            }
        }

        $content = str_replace(['\n', '\t', "\n", "\t"], '', trim($doc_obj->htmlOuter()));
        unset($html);
        unset($doc_obj);
        return $content;
    }

    /**
     * 处理商品详情右侧（bottom之上）里的图片，上传OSS并替换html
     * @param $html
     * @param string $goods_url
     * @return bool|string
     */
    protected static function handleImportGoodsAsynRight($html, $goods_url = '')
    {
        //编码转换为utf-8
        $html = mb_convert_encoding($html, 'UTF-8', ['GBK', 'GB2312', 'UTF-8']);

        $asyn_matches = array();
        $asyn_match_count = preg_match('/"right":.+","sdkTemplate/', $html, $asyn_matches);
        $asyn_html = '';
        if (!empty($asyn_matches)) {
            $asyn_html = trim(str_replace(['"right":"', '\r', '\n', '","sdkTemplate'], '', $asyn_matches[0]));
            $asyn_html = stripcslashes($asyn_html);
        }

        $doc_obj = phpQuery::newDocument($asyn_html);

        //移除script标签
        pq('script')->remove();

        //移除空白区域
        $tb_modules = pq('.tb-module');
        if (!empty($tb_modules)) {
            foreach ($tb_modules as $tb_module) {
                if (trim(pq($tb_module)->html()) == '') {
                    pq($tb_module)->parents('.J_TModule')->remove();
                }
            }
        }

        $img_tags = pq('img');
        if (!empty($img_tags)) {
            foreach ($img_tags as $img_tag) {
                $img_src = pq($img_tag)->attr('data-ks-lazyload');
                if (empty($img_src)) {
                    $img_src = pq($img_tag)->attr('src');
                }

                if (empty($img_src)) {
                    continue;
                }

                if (!self::validateURL($img_src)) {
                    $img_src = 'https:' . $img_src;
                }

                pq($img_tag)->attr("src", $img_src);
                pq($img_tag)->removeAttr('data-ks-lazyload');
            }
        }

        $content = str_replace(['\n', '\t', "\n", "\t"], '', trim($doc_obj->htmlOuter()));
        unset($html);
        unset($doc_obj);
        return $content;
    }
}