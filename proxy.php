<?php

// Get stuff
$headers = getallheaders();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$url = $headers['X-Proxy-Url'] ?? null;
$cookie = $headers['X-Proxy-Cookie'] ?? null;


// Check that we have a URL
if( ! $url)
	http_response_code(400) and exit("X-Proxy-Url header missing");

// Check that the URL looks like an absolute URL
if( ! parse_url($url, PHP_URL_SCHEME))
	http_response_code(403) and exit("Not an absolute URL: $url");

// Check referer hostname
if( ! parse_url($headers['Referer'] ?? null, PHP_URL_HOST) == $_SERVER['HTTP_HOST'])
	http_response_code(403) and exit("Invalid referer");

// Check whitelist, if not empty
if( ! array_reduce($whitelist ?? [], 'is_bad', [$url, false]))
	http_response_code(403) and exit("Not whitelisted: $url");


// Remove ignored headers and prepare the rest for resending
$ignore = ['cookie', 'host', 'x-proxy-url', 'x-proxy-cookie'];
$headers = array_diff_key(array_change_key_case($headers), array_flip($ignore));
if($cookie)
	$headers['Cookie'] = $cookie;
foreach($headers as $key => &$value)
	$value = ucwords($key, '-').": $value";

// Init curl
$curl = curl_init();
$maxredirs = $opts[CURLOPT_MAXREDIRS] ?? 20;
do
{
	// Set generic options
	curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_HEADER => true,
		] + ($opts??[]) + [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => $maxredirs,
		]);

	// Method specific options
	switch($method)
	{
		case 'HEAD':
			curl_setopt($curl, CURLOPT_NOBODY, true);
			break;

		case 'GET':
			break;

		case 'PUT':
		case 'POST':
		case 'DELETE':
		default:
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($curl, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
			break;
	}

	// Perform request
	ob_start();
	curl_exec($curl) or http_response_code(500) and exit(curl_error($curl));
	$out = ob_get_clean();

	// HACK: If for any reason redirection doesn't work, do it manually...
	$url = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
}
while($url and --$maxredirs > 0);


// Get curl info and close handler
$info = curl_getinfo($curl);
curl_close($curl);


// Remove any existing headers
header_remove();

// Use zlib, if acceptable
ini_set('zlib.output_compression', $zlib ?? 'On');

// Get content and headers
$content = substr($out, $info['header_size']);
$header = substr($out, 0, $info['header_size']);

// Rename Set-Cookie header
$header = preg_replace('/^Set-Cookie:/im', 'X-Proxy-Set-Cookie:', $header);

// Output headers
array_map('header', explode("\r\n", $header));

// HACK: Prevent chunked encoding and gz issues (Issue #1)
header_remove('Transfer-Encoding');
header('Content-Length: '.strlen($content), true);

// Output content
echo $content;





function is_bad($carry, array $rule): bool
{
	static $url;
	if(is_array($carry))
	{
		$url = parse_url($carry[0]);
		$url['raw'] = $carry[0];
		$carry = $carry[1];
	}

	// Equals full URL
	if(isset($rule[0]))
		return $carry or $url['raw'] == $rule[0];
	
	// Regex matches URL
	if(isset($rule['regex']))
		return $carry or preg_match($rule['regex'], $url['raw']);

	// Components in rule matches same components in URL
	return $carry or $rule == array_intersect_key($url, $rule);
}
