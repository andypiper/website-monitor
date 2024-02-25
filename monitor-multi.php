<?php
/*

Website Monitor
===============

Hello! This is the monitor script, which does the actual monitoring of websites
stored in monitors.json.

This is a reworked version that uses curl_multi to improve performance.

You can run this manually, but it’s probably better if you use a cron job.
Here’s an example of a crontab entry that will run it every minute:

* * * * * /usr/bin/php -f /path/to/monitor.php >/dev/null 2>&1

*/

include('configuration.php');

$monitors = json_decode(file_get_contents(PATH.'/monitors.json'));

$multiCurl = curl_multi_init();
// array to hold individual cURL handles
$curlArray = array();

foreach($monitors as $name => $url) {
    $curlArray[$name] = curl_init($url);
    curl_setopt($curlArray[$name], CURLOPT_URL, $url);
    curl_setopt($curlArray[$name], CURLOPT_HEADER, true);
    curl_setopt($curlArray[$name], CURLOPT_RETURNTRANSFER, true);
    // set a user agent as some sites (DEV.to) appear to block the default
    curl_setopt($curlArray[$name], CURLOPT_USERAGENT, "website-monitor/1.0");
    if ($verbose) {
        curl_setopt($curlArray[$name], CURLOPT_VERBOSE, true);
    }
    curl_multi_add_handle($multiCurl, $curlArray[$name]);
}

$active = null;
// Execute the handles
do {
    $status = curl_multi_exec($multiCurl, $active);
    if ($state = curl_multi_info_read($multiCurl)) {
        $info = curl_getinfo($state['handle']);
        $error = curl_error($state['handle']);
        $results = curl_multi_getcontent($state['handle']);
        $timestamp = time();
        $response_data[$timestamp]['timestamp'] = $timestamp;
        if($results === false) {
            $response_data[$timestamp]['error'] = curl_error($curl);
        }
        else {
            $http_code = $info['http_code'];
            $ms = $info['total_time_us'] / 1000;
            $response_data[$timestamp]['time'] = $ms;
            $response_data[$timestamp]['response'] = $http_code;
        }

        // Find the name of the current URL
        foreach ($curlArray as $name => $curl) {
            if ($curl === $state['handle']) {
                $currentName = $name;
                break;
            }
        }
        // Save the data
        $directory = PATH.'/monitors/';
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }
        if(file_exists($directory.$currentName)) {
            $data = json_decode(file_get_contents($directory.$currentName), true);
        }
        else {
            $data = array();
        }
        $data = array_merge($data, $response_data);
        $data = array_slice($data, -60);
        file_put_contents(PATH.'/monitors/'.$currentName, json_encode($data, JSON_PRETTY_PRINT));
    }
} while ($status === CURLM_CALL_MULTI_PERFORM || $active);

// Clean up the handles
foreach($curlArray as $curl) {
    curl_multi_remove_handle($multiCurl, $curl);
}
curl_multi_close($multiCurl);
