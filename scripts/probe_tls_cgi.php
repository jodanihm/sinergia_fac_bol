<?php
$url = 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload';
$variants = [
    'A_default'        => [],
    'B_tls12'          => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2],
    'C_tls12_seclvl1'  => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1'],
    'D_http11_tls12'   => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1],
];
foreach ($variants as $name => $opts) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['ping' => '1'],
        CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0 (compatible; PROG 1.0)'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    curl_setopt_array($ch, $opts);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo sprintf("[%-16s] errno=%d code=%d err=\"%s\" bodylen=%d\n", $name, $errno, $code, $err, strlen((string) $body));
    echo '   body0=' . substr(preg_replace('/\s+/', ' ', (string) $body), 0, 160) . "\n";
    curl_close($ch);
}
echo 'openssl: ' . OPENSSL_VERSION_TEXT . "\n";
$cv = curl_version();
echo 'curl: ' . $cv['version'] . ' ssl=' . $cv['ssl_version'] . "\n";
