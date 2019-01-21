# taobao-tmall_spider_php
简介：淘宝、天猫商品详情数据抓取PHP版本，目前支持PC端淘宝网和天猫网站商品详情页数据抓取，
后续会考虑添加天猫超市、天猫国际以及Python版本的支持。

安装：

composer require 

使用示例：

use Spider\Taobao\TaobaoSpider;

$url = 'https://detail.tmall.com/item.htm?id=39571199816&ali_refid=a3_430583_1006:1109248652:N:%E9%9B%85%E8%AF%97%E5%85%B0%E9%BB%9B:5f3aed01738e158bc592eebfe8a0efb9&ali_trackid=1_5f3aed01738e158bc592eebfe8a0efb9&spm=a230r.1.14.1';

$info = TaobaoSpider::importGoods($url);

print_r($info);


返回结果：

Array
(
    [code] => 200
    [msg] => 获取成功！
    [data] => Array
        (
            [title] => 雅诗兰黛护肤套装 小棕瓶眼霜+小棕瓶精华 补水保湿修护
            [images] => Array
                (
                    [0] => https://img.alicdn.com/imgextra/i4/2064892827/O1CN01d2TuSJ1WkoAjeoiYn_!!0-item_pic.jpg
                    [1] => https://img.alicdn.com/imgextra/i4/2064892827/O1CN01xIAfe71WkoAjjk35u-2064892827.jpg
                    [2] => https://img.alicdn.com/imgextra/i2/2064892827/O1CN01DlzAt11WkoAUwoQf4_!!2064892827.jpg
                    [3] => https://img.alicdn.com/imgextra/i2/2064892827/O1CN011Wko84caiqRG8Lj_!!2064892827.jpg
                    [4] => https://img.alicdn.com/imgextra/i4/2064892827/O1CN01Neups11WkoAeNMA6H_!!2064892827.jpg
                )
            [desc] => '商品详情html'
     )
)

返回字段说明：

code，状态，200表示抓取成功；

msg，信息，抓取失败会提示信息；

data，数据：title=商品标题，images=商品图片，desc=商品详情HTML


