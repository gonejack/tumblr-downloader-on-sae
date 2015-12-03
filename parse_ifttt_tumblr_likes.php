<?php
/**
 * Created by PhpStorm.
 * User: Youi
 * Date: 2015-11-1
 * Time: 00:56
 */

/**
 * Query out the redirect url of ifttt short url
 * @param string $url the short url redirecting to tumblr real url
 * @return bool|string false on failed or tumblr url string on success
 */
function get_redirect_target($url)
{
    $ch = curl_init($url);
    $options = array(
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROXY => '127.0.0.1:1080',
        CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/46.0.2490.80 Safari/537.36',
        CURLOPT_HTTPHEADER => array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.8,zh-CN;q=0.6,zh;q=0.4'
        ),
    );
    curl_setopt_array($ch, $options);
    $i = 0;
    do {
        $headers = curl_exec($ch);
    } while (!$headers && $i++ < 3);
    curl_close($ch);

    if (preg_match('/^Location: (.+)$/im', $headers, $matches)) {
        return trim($matches[1]);
    } else {
        return false;
    }

}

/**
 * Get the html content of url
 * @param string $url the url to be fetched
 * @return bool|mixed false on failed or html content string on success
 */
function get_page_src($url) {
    $ch = curl_init($url);
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROXY => '127.0.0.1:1080',
        CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/46.0.2490.80 Safari/537.36',
        CURLOPT_HTTPHEADER => array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.8,zh-CN;q=0.6,zh;q=0.4'
        ),
        CURLOPT_CAINFO => 'cacert.pem',
    );
    curl_setopt_array($ch, $options);

    $page_str = false;

    $i = 0;
    do {
        $page_str = curl_exec($ch);
        $i++;
    } while (!$page_str && $i < 3);

    //Tumblr has two URL types, try the short one when the long one failed.
    if (strlen($page_str) < 100 && preg_match('<http.+/post/\d+>', $url, $match)) {
        $ch = curl_init($match[0]);
        curl_setopt_array($ch, $options);

        $i = 0;
        do {
            $page_str = curl_exec($ch);
            $i++;
        } while (!$page_str && $i <= 3);

    }

    return $page_str;
}

/**
 * Fetch out post type
 * @param string $page_src the html content string
 * @return string return string: tumblr post type like 'photo' 'photoset' 'audio' 'video' on success or 'unknow' on failed.
 */
function get_post_type($page_src) {
    $post_type = 'unknown';
    if (preg_match('#<meta property="og:type" content="tumblr-feed:(\w+)" />#i', $page_src, $match)) {
        $post_type = strtolower($match[1]);
    }
    return $post_type;
}

/**
 * Fetch out image uris
 * @param string $page_src the html content string
 * @return array return array: array of image uris, or empty array when nothing fetched
 */
function parse_img_urls($page_src) {
    $return_urls = array();

    $re_patten = "<(?:content|src)=\"((?:https?://\d+\.media\.tumblr\.com)/(?:(\w+)/)?(?:(?:tumblr_)?(\w+)_(1280|540|500|400|250)\.(?:png|jpg|gif)))\">i";
    if (preg_match_all($re_patten, $page_src, $matches)) {
        list(, $urls, $hashes, $hashes2, $sizes) = $matches;

        #find for the largest img
        $temp_container = array();
        for ($i = 0, $length = sizeof($urls); $i < $length; $i++) {
            $url  = $urls[$i];
            $hash = $hashes[$i] ? $hashes[$i] : $hashes2[$i];
            $size = $sizes[$i];
            if (empty($temp_container[$hash]) || $temp_container[$hash]['size'] < $size) {
                $temp_container[$hash] = array('url' => $url, 'size' => $size);
            }
        }

        $return_urls = array_column($temp_container, 'url');
    }

    return $return_urls;
}

/**
 * Fetch out audio file uri
 * @param string $page_src the html content string
 * @return bool|string return false on failed or audio uri string on succeed
 */
function parse_audio_url($page_src) {
    $audio_url = false;
    if (preg_match('#source src=\\x22([^\\]+)\\#', $page_src, $match)) {
        $audio_url = $match[1];
    } elseif (preg_match('#audio_file=([^&]+)&#', $page_src, $match)) {
        $audio_url = $match[1] . '?plead=please-dont-download-this-or-our-lawyers-wont-let-us-host-audio';
    }

    return $audio_url;
}

/**
 * Fetch out video file uri
 * @param string $page_src the html content string
 * @return bool
 */
function parse_video_url($page_src) {
    $video_url = false;
    if (preg_match('#<iframe src=\'([^\']*)\'#', $page_src, $match)) {
        $iframe_url = $match[1];
        $iframe_src = get_page_src($iframe_url);
        $iframe_src && preg_match('#<source src="([^"]*)"#', $iframe_src, $video_match) && $video_url = $video_match[1];
    }

    return $video_url;
}

/**
 * Read short urls text file tumblr_likes.txt
 * deal with every short url:
 *     1 read the unwanted_files.txt for name of files that we don't need
 *     2 fetch out long url of short url, if failed write the short url to invalid_urls.txt
 *     3 fetch out resource uris inside html content of long url, filter this uris by the content of unwanted_files.txt
 *     4 write out the resource uris to resource_urls.txt
 * @param null $direct_url if specific deal with this single url instead of reading tumblr_likes.txt
 */
function main($direct_url = null) {
    $txt = $direct_url ? $direct_url : file_get_contents('tumblr_likes.txt');

    if (preg_match_all('#http://ift.tt/.*#', $txt, $matches)) {
        $unwanted = file_get_contents('unwanted_files.txt');

        #deal with each short url
        foreach ($matches[0] as $ori_url) {

            try {
                echo str_repeat('-', 30), "\n";
                echo "Start: $ori_url\n";

                #get long url
                $redirect_url = get_redirect_target($ori_url);
                if (!$redirect_url) {
                    file_put_contents('invalid_urls.txt', "$ori_url\n", FILE_APPEND);
                    throw new exception("invalid original URL $ori_url");
                } else {
                    #file_put_contents('real_post_urls.txt', "$redirect_url\n", FILE_APPEND);
                }
                echo "Location fetched: $redirect_url\n";

                #get html page content
                $page_src = get_page_src($redirect_url);
                if (!$page_src) { throw new exception("zero length page_src"); }
                printf("Page fetched: length(%d)\n", strlen($page_src));

                #fetch out resource urls
                $resource_urls = array();
                $post_type = get_post_type($page_src);
                switch($post_type) {
                    case 'photo':
                    case 'photoset':
                        $resource_urls = parse_img_urls($page_src); break;
                    case 'audio':
                        echo "fetching audio\n";
                        $resource_urls = parse_audio_url($page_src); break;
                    case 'video':
                        echo "fetching video\n";
                        $resource_urls = parse_video_url($page_src); break;
                    default:
                        echo 'unknown resource, trying images', "\n";
                        $resource_urls = parse_img_urls($page_src);
                }

                if (is_array($resource_urls)) {
                    foreach ($resource_urls as $index => $url) {
                        if (strpos($unwanted, basename($url)) !== false) { unset($resource_urls[$index]); }
                    }
                    $resource_urls = implode("\n", $resource_urls);
                }

                echo $resource_urls .= "\n";

                file_put_contents('resource_urls.txt', $resource_urls, FILE_APPEND);

            } catch (exception $e) {
                echo 'Exception: ', $e->getMessage(), "\n";
            }

        }

    }

}

main();