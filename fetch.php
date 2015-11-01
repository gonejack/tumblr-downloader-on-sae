<?php
/**
 * Created by PhpStorm.
 * User: Youi
 * Date: 2015-09-22
 * Time: 13:09
 */

if (!isset($_GET['url']) || !preg_match('#^http.?://.+\.tumblr\.com.*#i', $_GET['url'])) {
    exit('Hello World!');
} else {
    main();
}

function main() {
    $html     = get_page(encode_cjk_url($_GET['url']));
    $img_urls = parse_img_urls($html);
    switch (count($img_urls)) {
        case 0:
            exit_with_msg('Images not found');
            break;
        case 1:
            redirect_location(array_pop($img_urls));
            break;
        default:
            output_zip(make_zip(fetch_and_store_images($img_urls)));
            break;
    }
}

function encode_cjk_url($raw_url) {

    $url = '';
    if (preg_match('#(http.+?tumblr\.com)(.+$)#i', $raw_url, $matches)) {
        $path_parts = array_map('urlencode', explode('/', $matches[2]));
        $url        = $matches[1] . implode('/', $path_parts);
    }

    return $url;
}

function get_page($url) {

    $url_hash = date('Y-m-d_') . md5($url);
    $kvdb     = new SaeKV();
    $kvdb->init();
    $page_str = false;
    if ($str = $kvdb->get($url_hash)) {
        $page_str = $str;
    } else {
        $page_str = file_get_contents($url);
        //Tumblr has two URL types, try the short one when the long one failed.
        strlen($page_str) < 100 && preg_match('<http.+/post/\d+>', $url, $arrMatch) && $page_str = @file_get_contents($arrMatch[0]);

        if (strlen($page_str) < 100) {
            $page_str = false;
        } else {
            $kvdb->set($url_hash, $page_str);
        }
    }

    return $page_str;
}

function parse_img_urls($html) {

    $return_urls = array();

    $re_patten = "<(?:content|src)=\"((?:https?://\d+\.media\.tumblr\.com)/(?:(\w+)/)?(?:tumblr_\w+_(1280|540|500|400|250)\.(?:png|jpg|gif)))\">i";
    if (preg_match_all($re_patten, $html, $matches)) {
        list(, $urls, $hashes, $sizes) = $matches;

        //find for the largest img
        $temp_container = array();
        for ($i = 0, $length = sizeof($urls); $i < $length; $i++) {
            $url  = $urls[$i];
            $hash = $hashes[$i];
            $size = $sizes[$i];
            if (empty($temp_container[$hash]) || $temp_container[$hash]['size'] < $size) {
                $temp_container[$hash] = array('url' => $url, 'size' => $size);
            }
        }

        $kvdb = new SaeKV();
        $kvdb->init();
        foreach ($temp_container as $hash => $item) {
            $filename = basename($item['url']);
            $local_file   = 'none';
            if ($img_info = $kvdb->get($filename)) {
                if (in_array($img_info['remark'], array('unwanted', 'inaccessible'))) {
                    continue;
                }
                $item['size'] <= $img_info['size'] && $local_file = $filename;
            }
            $hash_size_mark = "{$hash}#{$item['size']}#$local_file";
            $return_urls[$hash_size_mark] = $item['url'];
        }
    }

    return $return_urls;
}

function exit_with_msg($msg) {
    exit($msg);
}

function redirect_location($img_url) {
    header('Location: ' . $img_url, true, 301);
    exit;
}

function parse_header(array $headers, $header = null) {
    $output = array();

    if ('HTTP' === substr($headers[0], 0, 4)) {
        list(, $output['status'], $output['status_text']) = explode(' ', $headers[0]);
        unset($headers[0]);
    }

    foreach ($headers as $v) {
        $h                         = preg_split('/:\s*/', $v);
        $output[strtolower($h[0])] = $h[1];
    }

    if (null !== $header) {
        if (isset($output[strtolower($header)])) {
            return $output[strtolower($header)];
        }

        return null;
    }

    return $output;
}

function fetch_and_store_images(array $img_urls) {

    $return_arr = array('valid_images' => array(), 'invalid_image_urls' => array());

    $valid_status = array(200, 301, 304);
    $kvdb         = new SaeKV();
    $kvdb->init();
    foreach ($img_urls as $hash_size_name => $img_url) {
        list($hash, $size, $filename) = explode('#', $hash_size_name);

        if ($filename !== 'none' && file_exists("saestor://tumblrlikes/$filename")) {

            $img = file_get_contents("saestor://tumblrlikes/$filename");

        } else {
            $filename = basename($img_url);

            $img           = @file_get_contents($img_url);
            $fetch_succeed = in_array(parse_header($http_response_header, 'status'), $valid_status);

            $img_info = array('date' => date('Y-m-d'), 'size' => $size, 'read_counter' => 1, 'remark' => $fetch_succeed ? '' : 'inaccessible');
            $kvdb->set($filename, $img_info);

            if ($img === false || !$fetch_succeed) {
                $return_arr['invalid_image_urls'][] = $img_url;
                continue;
            } else {
                file_put_contents("saestor://tumblrlikes/$filename", $img);
            }
        }

        $return_arr['valid_images'][$img_url] = $img;
    }

    return $return_arr;
}

function make_zip(&$imgs_and_urls) {
    require_once('zip.lib.php');
    $zip = new ZipFile();

    foreach ($imgs_and_urls['valid_images'] as $url => $img) {
        $zip->addFile($img, basename($url));
    }

    if ($imgs_and_urls['invalid_image_urls']) {
        $msg = "images list below cannot be found: \r\n";
        $msg .= implode("\r\n", $imgs_and_urls['invalid_image_urls']);
        $zip->addFile($msg, 'failed_list.txt');
    }

    return $zip->file();
}

function output_zip(&$zip_str) {
    header('Content-Type: application/zip');
    header('Content-Length: ' . strlen($zip_str));
    header('Content-Disposition: attachment; filename=' . date('Y/M/j/D G:i:s') . '.zip');

    echo $zip_str;
}