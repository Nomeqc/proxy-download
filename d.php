<?php
@error_reporting(E_ALL &~E_NOTICE &~E_WARNING);
@set_time_limit(0);

function die_echo_404_message($message)
{
	header("HTTP/1.1 404 Not Found");
	header("Content-Type: text/plain; charset=utf-8");
	die($message);
}

function check_url($url)
{
	if (null === parse_url($url, PHP_URL_SCHEME)) {
		$url = 'http://' . $url;
	}
	$url_reg = '%^(?:(?:https?|ftp)://)(?:\S+(?::\S*)?@|\d{1,3}(?:\.\d{1,3}){3}|(?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.[a-z\x{00a1}-\x{ffff}]{2,6}))(?::\d+)?(?:[^\s]*)?$%iu';
	if (!preg_match($url_reg, $url)) {
		die_echo_404_message('无效的网址');
	}
	return $url;
}

$origin_url = trim($_GET['url']);
$url = $origin_url;
$play = (bool)$_GET['play'];

if ($play && !empty($url)) {
	$url = check_url($url);
	$url = urlencode($url);
	header('Content-Type: text/html; charset=utf-8');
	echo <<<EOF
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>视频中转</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=1">
    <style>
        html, body, video {
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body>
    <video src="?url=$url" controls>
</body>
</html>
EOF;
	return;
}

function get_final_url($url)
{
	$curl = curl_init();
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
			CURLOPT_NOBODY => true,
		)
	);

	$response = curl_exec($curl);
	$err = curl_error($curl);
	$final_url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
	curl_close($curl);

	if ($err) {
		die_echo_404_message('File not found.');
	}

	return $final_url;
}

if (!empty($url)) {
	$file_name = trim(urldecode($_GET['filename']));

	$url = check_url($url);

	$url = get_final_url($url);

	$urlArgs = parse_url($url);

	$host = $urlArgs['host'];
	$requestUri = $urlArgs['path'];

	if (isset($urlArgs['query'])) {
		$requestUri .= '?' . $urlArgs['query'];
	}

	$protocol = ($urlArgs['scheme'] == 'http') ? 'tcp' : 'ssl';
	$port = $urlArgs['port'];

	if (empty($port)) {
		$port = ($protocol == 'tcp') ? 80 : 443;
	}

	$header = "{$_SERVER['REQUEST_METHOD']} {$requestUri} HTTP/1.1\r\nHost: {$host}\r\n";

	unset($_SERVER['HTTP_HOST']);
	$_SERVER['HTTP_CONNECTION'] = 'close';

	if ($_SERVER['CONTENT_TYPE']) {
		$_SERVER['HTTP_CONTENT_TYPE'] = $_SERVER['CONTENT_TYPE'];
	}

	foreach ($_SERVER as $x => $v) {
		if (substr($x, 0, 5) !== 'HTTP_') {
			continue;
		}
		$x = strtr(ucwords(strtr(strtolower(substr($x, 5)), '_', ' ')), ' ', '-');
		$header .= "{$x}: {$v}\r\n";
	}

	$header .= "\r\n";

	$remote = "{$protocol}://{$host}:{$port}";

	$context = stream_context_create();
	stream_context_set_option($context, 'ssl', 'verify_host', false);

	$p = stream_socket_client($remote, $err, $errstr, 60, STREAM_CLIENT_CONNECT, $context);

	if (!$p) {
		exit;
	}

	fwrite($p, $header);

	$pp = fopen('php://input', 'r');

	while ($pp && !feof($pp)) {
		fwrite($p, fread($pp, 1024));
	}

	fclose($pp);

	$header = '';

	$x = 0;
	$len = false;
	$off = 0;

	while (!feof($p)) {
		if ($x == 0) {
			$header .= fread($p, 1024);

			if (($i = strpos($header, "\r\n\r\n")) !== false) {
				$x = 1;
				$n = substr($header, $i + 4);
				$header = substr($header, 0, $i);
				$has_suggest_name = (strpos($header, "Content-Disposition:") !== false);
				$header = explode("\r\n", $header);
				foreach ($header as $m) {
					if (preg_match('!^\\s*content-length\\s*:!is', $m)) {
						$len = trim(substr($m, 15));
					}
					header($m);
				}

				if (!empty($file_name)) {
					$my_name = trim(pathinfo($file_name, PATHINFO_FILENAME));
					$my_ext = trim(pathinfo($file_name, PATHINFO_EXTENSION));
					if (empty($my_name)) {
						$my_name = 'download';
					}
					$file_name = $my_name;
					if (!empty($my_ext)) {
						$file_name = $file_name . '.' . $my_ext;
					}

					$encoded_filename = rawurlencode($file_name);
					header('Content-Disposition: attachment; filename="' . $encoded_filename . '"; filename*=UTF-8\'\'' . $encoded_filename);
				  //如果服务器没有返回推荐文件名，则添加
				} else if (!$has_suggest_name) {
					$name = trim(pathinfo($origin_url, PATHINFO_FILENAME));
					$ext = trim(pathinfo($origin_url, PATHINFO_EXTENSION));
					if (empty($name)) {
						$name = 'download';
					}
					$file_name = $name;
					if (!empty($ext)) {
						$file_name = $file_name . '.' . $ext;
					}

					$encoded_filename = rawurlencode($file_name);
					header('Content-Disposition: attachment; filename="' . $encoded_filename . '"; filename*=UTF-8\'\'' . $encoded_filename);
				}

				$off = strlen($n);
				echo $n;
				flush();
			}
		} else {
			if ($len !== false && $off >= $len) {
				break;
			}
			$n = fread($p, 1024);
			$off += strlen($n);
			echo $n;
			flush();
		}
	}

	fclose($p);
	return;
}

header('Content-Type: text/html; charset=utf-8');
echo <<<EOF

<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>下载中转</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <meta name="renderer" content="webkit">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link rel="stylesheet" href="https://cdn.staticfile.org/layui/2.5.7/css/layui.min.css"  media="all">
  
</head>
<body>
  
  
<fieldset class="layui-elem-field layui-field-title" style="margin-top: 20px; width: 550px">
  <legend>下载中转</legend>
</fieldset>
 
<form class="layui-form" action="" onsubmit="return false" style="width: 450px">
  <div class="layui-form-item layui-form-text">
    <label class="layui-form-label">地址</label>
    <div class="layui-input-block">
      <textarea name="content" id="content" placeholder="请输入地址" class="layui-textarea" lay-verify="content" style="height: 150px"></textarea>
    </div>
  </div>
  <div class="layui-form-item">
    <div class="layui-input-block">
      <button class="layui-btn" lay-submit lay-filter="submitBtn" id='submitBtn'>下载</button>
      <button class="layui-btn" lay-submit lay-filter="playBtn" id='playBtn'>播放</button>
    </div>
  </div>
</form>


<script src="https://cdn.staticfile.org/layui/2.5.7/layui.min.js" charset="utf-8"></script>

<script>

layui.use(['form'], function(){
  var form = layui.form
  ,$ = layui.$
  ,layer = layui.layer;
  
  $('#content').focus();
  
  var element = document.getElementById('content');
  var top = element.getBoundingClientRect().top, left = element.getBoundingClientRect().left, 
  	  width = element.getBoundingClientRect().width, height = element.getBoundingClientRect().height;
 
  var layerWidth = 190, layerHeight = 65;
  layer.config({'area': [layerWidth + 'px', layerHeight + 'px'], "offset": [top + (height - layerHeight) / 2 + 'px', left + width + 25 + 'px']});
  //自定义验证规则
  form.verify({
    content: function(value){
      if (value.trim().length == 0) {
        return "地址不能为空";
      }
      var url_reg = /((([A-Za-z]{3,9}:(?:\/\/)?)(?:[\-;:&=\+\$,\w]+@)?[A-Za-z0-9\.\-]+|(?:www\.|[\-;:&=\+\$,\w]+@)[A-Za-z0-9\.\-]+)((?:\/[\+~%\/\.\w\-_]*)?\??(?:[\-\+=&;%@\.\w_]*)#?(?:[\.\!\/\\\w]*))?)/;
      if(!url_reg.test(decodeURIComponent(value))) {
      	return "无效的网址";
      }
    }
  });
  
  //监听提交
  form.on('submit(submitBtn)', function(data) {
  	var url = encodeURIComponent(decodeURIComponent(data.field['content']));
    window.open(window.location.pathname + "?url=" + url, '_blank');
    return false;
  });
  form.on('submit(playBtn)', function(data) {
    var url = encodeURIComponent(data.field['content']);
    window.open(window.location.pathname + "?url=" + url + "&play=true", '_blank');
    return false;
  });
 
});
</script>

</body>
</html>
EOF;

