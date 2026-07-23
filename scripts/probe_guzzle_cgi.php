<?php
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

$url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload';
$xml = file_get_contents(__DIR__ . '/../rvd_boleta_debug.xml');
$ua  = 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)';

function run(string $label, array $extraOpts, array $extraHeaders): void
{
    global $url, $xml, $ua;
    $client = new Client();
    $opts = [
        'http_errors'     => false,
        'timeout'         => 30,
        'connect_timeout' => 15,
        'headers' => array_merge([
            'User-Agent' => $ua,
            'Cookie'     => 'TOKEN=DUMMYTOKEN12345',
            'Accept'     => '*/*',
        ], $extraHeaders),
        'multipart' => [
            ['name' => 'rutSender',  'contents' => '13520634'],
            ['name' => 'dvSender',   'contents' => '2'],
            ['name' => 'rutCompany', 'contents' => '77724622'],
            ['name' => 'dvCompany',  'contents' => '4'],
            ['name' => 'archivo', 'contents' => $xml, 'filename' => 'envio.xml', 'headers' => ['Content-Type' => 'text/xml']],
        ],
    ];
    foreach ($extraOpts as $k => $v) {
        $opts[$k] = $v;
    }
    try {
        $r = $client->request('POST', $url, $opts);
        $body = (string) $r->getBody();
        echo sprintf("[%-22s] OK code=%d bodylen=%d\n", $label, $r->getStatusCode(), strlen($body));
        echo '   body0=' . substr(preg_replace('/\s+/', ' ', $body), 0, 180) . "\n";
    } catch (\Throwable $e) {
        echo sprintf("[%-22s] ERR %s\n", $label, $e->getMessage());
    }
}

echo 'XML bytes: ' . strlen($xml) . "\n";
run('V1_plain',            [], []);
run('V2_no_expect',        ['expect' => false], []);
run('V3_no_expect_http11', ['expect' => false, 'version' => '1.1'], []);
run('V4_conn_close',       ['expect' => false], ['Connection' => 'close']);
run('V5_tls12',            ['expect' => false, 'curl' => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2]], []);
