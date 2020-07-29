<?php

$WALLET = "t1fD9dVevpYn61jYMGS9WYR1DyPhngkTXnn";
$COIN = "zel";
$HASHRATE = 100;
$DEVIATION = 15;
$CONF['HTTP_HEADERS'] = array('accept' => 'application/json');

$blocks = -1;
$solo = false;

msg('Initializing...');

$ch = dl_init();

declare(ticks=1);
function signalHandler($signo) {
  global $solo;
  print("Ctrl+C received, killing miner..." . PHP_EOL);
  if ($solo)
    stopSoloMining();
  else
    stopPoolMining();
  exit(1);
}
if (!function_exists('pcntl_signal'))
  print('WARNING! pcntl extension disabled, see http://www.php.net/manual/en/pcntl.installation.php for more info' . PHP_EOL);
else
  pcntl_signal(SIGINT, 'signalHandler');


while (true) {
  $stat = getPoolStat($ch, $COIN, $WALLET);

  if ($stat['blocksFound'] > $blocks) {
    if ($blocks >= 0) {
      // Pool block found
      msg('Pool block found. Restarting...');
      saveBlockFound($stat, $startHeight, 'POOL');
      $TARGET = predict();
      msg("Target: $TARGET%");
      $startHeight = $stat['height'];
    } else {
      // Startup
      $progress = loadProgress();
      if (is_array($progress)) {
        msg('Previous session found. Restoring...');
        list($sess_startHeight, $sess_blocksBehind, $sess_target, $sess_dest) = $progress;
        $startHeight = $stat['height'] - $sess_blocksBehind;
        $TARGET = $sess_target;
        $solo = ($sess_dest == 'SOLO');
      } else {
        $startHeight = $stat['height'];
        $TARGET = predict();
        msg("Target: $TARGET%");
      }
      if ($solo)
        startSoloMining();
      else
        startPoolMining();
    }
    $blocks = $stat['blocksFound'];
    $blocks_solo = $stat['blocksFoundSolo'];
  }

  $portion = $stat['networkHashrate'] / $HASHRATE;
  $time2Block = $portion * $stat['avgBlockTime'];
  $blocksBehind = $stat['height'] - $startHeight;
  $blocksLeft = $portion - $blocksBehind;
  $timeLeft = $blocksLeft * $stat['avgBlockTime'] / 60 / 60;
  $progress = $blocksBehind * 100 / $portion;
  $dest = $solo ? 'SOLO' : 'POOL';

  $msg = sprintf("Height: %d\t", $stat['height']);
  $msg .= sprintf("Target: %d%%\t", $TARGET);
  $msg .= sprintf("Progress: %.2f%% (%d/%d) on %s\t", $progress, $blocksBehind, $portion, $dest);
  $msg .= sprintf("Left: %.1f hours (%d blocks)\t", $timeLeft, $blocksLeft);
  msg($msg);

  if (!$solo && ($progress > $TARGET)) {
    msg(sprintf("%0.2f%% target reached. Switching to SOLO", $TARGET));
    stopPoolMining();
    startSoloMining();
    $solo = true;
  }

  if ($solo && ($stat['blocksFoundSolo'] > $blocks_solo)) {
    msg("SOLO block found!!!!!");
    saveBlockFound($stat, $startHeight, 'SOLO');
    stopSoloMining();
    $TARGET = predict();
    msg("Target: $TARGET%");
    startPoolMining();
    $solo = false;
    $blocks = -1;
  }

  saveProgress($startHeight, $blocksBehind, $TARGET, $dest);
  sleep(60);
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function predict() {
  global $DEVIATION;
  $sum = 0;
  $cnt = 0;
  $avg_luck_sum = 0;
  if (file_exists(dirname(__FILE__) . '/history.dat')) {
    $fh = fopen(dirname(__FILE__) . '/history.dat', 'r');
    while (($line = fgets($fh)) !== false) {
      if (preg_match('/^(.*?)%\t/is', $line, $matches)) {
        $sum += $matches[1];
        $avg_luck_sum += $matches[1];
        $cnt++;
      }
    }
    fclose($fh);
    if ($cnt > 0) {
      $avg_luck = $avg_luck_sum / $cnt;
      print("avg_luck: " . $avg_luck . PHP_EOL);
      //return ($avg_luck-$DEVIATION)*($cnt+1)-$sum;
      return ($avg_luck)*($cnt+1)-$sum - $DEVIATION;
    }
  }
  return 100 - $DEVIATION;
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function saveBlockFound($stat, $startHeight, $dest) {
  global $HASHRATE;

  $portion = $stat['networkHashrate'] / $HASHRATE;
  $blocksBehind = $stat['height'] - $startHeight;
  $progress = $blocksBehind * 100 / $portion;

  $fh = fopen(dirname(__FILE__) . '/history.dat', 'a');
  fwrite($fh, sprintf("%.2f%%\t%s\t%s\t%d/%d".PHP_EOL, $progress, $dest, date("Y-m-d H:i:s"), $blocksBehind, $portion));
  fclose($fh);
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function loadProgress() {
  if (file_exists(dirname(__FILE__) . '/progress.dat')) {
    $fh = fopen(dirname(__FILE__) . '/progress.dat', 'r');
    while (($line = fgets($fh)) !== false) {
      if (preg_match('/^(\w*)[[:blank:]]*=[[:blank:]]*(\w*)/is', $line, $matches))
        ${$matches[1]} = $matches[2];
    }
    fclose($fh);
    if (isset($startHeight) && isset($blocksBehind) && isset($target) && isset($dest)) {
      return array($startHeight, $blocksBehind, $target, $dest);
    }
  }
  return false;
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function saveProgress($startHeight, $blocksBehind, $target, $dest) {
  $fh = fopen(dirname(__FILE__) . '/progress.dat', 'w');
  fwrite($fh, "startHeight = " . $startHeight . PHP_EOL);
  fwrite($fh, "blocksBehind = " . $blocksBehind . PHP_EOL);
  fwrite($fh, "target = " . $target . PHP_EOL);
  fwrite($fh, "dest = " . $dest . PHP_EOL);
  fclose($fh);
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function startPoolMining() {
  msg("Starting POOL mining...");
  shell_exec('/opt/mining/zel.sh');
}
function stopPoolMining() {
  msg("Stopping POOL mining...");
  shell_exec('screen -X -S zel quit');
}
function startSoloMining() {
  msg("Starting SOLO mining...");
  shell_exec('/opt/mining/zel-solo.sh');
}
function stopSoloMining() {
  msg("Stopping SOLO mining...");
  shell_exec('screen -X -S zel quit');
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function msg($str) {
  $LOG_FILE_NAME = dirname(__FILE__) . '/improbability.log';
  $fh = fopen($LOG_FILE_NAME, 'a');
  fwrite($fh, sprintf("%s - %s" . PHP_EOL, date("Y-m-d H:i:s"), $str));
  fclose($fh);
  print($str . PHP_EOL);
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function getPoolStat($ch, $coin, $wallet) {
  $pool_api = 'https://' . $coin . '.2miners.com/api';
  $solo_api = 'https://solo-' . $coin . '.2miners.com/api';
  $stat = array();
  $res = json_decode(dl_get($ch, $pool_api . '/accounts/' . $wallet), true);
  $stat['blocksFound'] = isset($res['stats']['blocksFound']) ? $res['stats']['blocksFound'] : 0;
  $res = json_decode(dl_get($ch, $pool_api . '/stats'), true);
  $stat['networkHashrate'] = isset($res['nodes'][0]['networkhashps']) ? $res['nodes'][0]['networkhashps'] : 0;
  $stat['difficulty'] = isset($res['nodes'][0]['difficulty']) ? $res['nodes'][0]['difficulty'] : 0;
  $stat['height'] = isset($res['nodes'][0]['height']) ? $res['nodes'][0]['height'] : 0;
  $stat['avgBlockTime'] = isset($res['nodes'][0]['avgBlockTime']) ? $res['nodes'][0]['avgBlockTime'] : 0;
  $res = json_decode(dl_get($ch, $solo_api . '/accounts/' . $wallet), true);
  $stat['blocksFoundSolo'] = isset($res['stats']['blocksFound']) ? $res['stats']['blocksFound'] : 0;
  return $stat;
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////
function dl_init() {
  $ch = curl_init();
  if (isset($CONF['REFERER'])) curl_setopt($ch, CURLOPT_REFERER, $CONF['REFERER']);
  if (isset($CONF['HTTP_AGENT'])) curl_setopt($ch, CURLOPT_USERAGENT, $CONF['HTTP_AGENT']);
  if (isset($CONF['HTTP_HEADERS'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $CONF['HTTP_HEADERS']);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  if (isset($CONF['PROXY_TYPE'])) curl_setopt($ch, CURLOPT_PROXYTYPE, $CONF['PROXY_TYPE']);
  if (isset($CONF['PROXY'])) curl_setopt($ch, CURLOPT_PROXY, $CONF['PROXY']);
  if (isset($CONF['USERPWD'])) {
    curl_setopt($ch, CURLOPT_USERPWD, $CONF['USERPWD']);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
  }
  return $ch;
}

///////////////////////////////////////////////////////////////////////////////////////////////////////
function dl_get($ch, $url, $params = array()) {
  if (count($params) > 0 ) {
    $req = http_build_query($params);
    $url .= req;
  }
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, false);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $res = curl_exec($ch);
  $info = curl_getinfo($ch);
  if ($info['http_code'] == 200)
    return $res;
  else
    print_r($info);
}
