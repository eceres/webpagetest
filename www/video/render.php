<?php
chdir('..');
$settings = null;
require_once('common_lib.inc');
require_once('video/visualProgress.inc.php');
ignore_user_abort(true);
set_time_limit(3600);
error_reporting(E_ERROR | E_PARSE);

$max_load = GetSetting('render_max_load');
if ($max_load !== false && $max_load > 0)
  WaitForSystemLoad($max_load, 3600);

// Globals used throughout the video render
$width = 900;
$height = 650;
$padding = 4;
$textPadding = 0;
$minThumbnailSize = 60;
$biggestThumbnail = 0;
$backgroundColor = null;
$textColor = null;
$bgEvenText = null;
$bgOddText = null;
$image_bytes = null;
$timeFont = __DIR__ . '/font/sourcesanspro-semibold.ttf';
$labelFont = __DIR__ . '/font/sourcesanspro-semibold.ttf';
$labelHeight = 30;
$timeHeight = 40;
$timePadding = 3;
$rowPadding = 10;
$bottomMargin = 30;
$maxAspectRatio = 0;
$min_font_size = 4;
$videoExtendTime = 3000;
$encodeFormat = 'jpg';  // can be jpg (faster) or png (much slower), used internally to transfer to ffmpeg
$encoderSpeed = 'superfast';
$fps = 30;
$speed = 1;
$fractionTime = 10; // tenths of a second - 100 or 1000 are also available
$stopTime = null;
$combineTimeLabel = false;
$evenTextBackground = null;
$oddTextBackground = null;
$forceBackgroundColor = null;
$forceTextColor = null;
$timeSeconds = false;
$stopText = '';
$forceFontSize = 0;

// load any overrides
if (is_file('./settings/video.ini')) {
  $videoSettings = parse_ini_file('./settings/video.ini');
  if (isset($videoSettings['width']))
    $width = (int)$videoSettings['width'];
  if (isset($videoSettings['height']))
    $height = (int)$videoSettings['height'];
  if (isset($videoSettings['padding']))
    $padding = (int)$videoSettings['padding'];
  if (isset($videoSettings['text-padding']))
    $textPadding = (int)$videoSettings['text-padding'];
  if (isset($videoSettings['label-height']))
    $labelHeight = (int)$videoSettings['label-height'];
  if (isset($videoSettings['time-height']))
    $timeHeight = (int)$videoSettings['time-height'];
  if (isset($videoSettings['font-size']))
    $forceFontSize = (float)$videoSettings['font-size'];
  if (isset($videoSettings['time-padding']))
    $timePadding = (int)$videoSettings['time-padding'];
  if (isset($videoSettings['row-padding']))
    $rowPadding = (int)$videoSettings['row-padding'];
  if (isset($videoSettings['bottom-margin']))
    $bottomMargin = (int)$videoSettings['bottom-margin'];
  if (isset($videoSettings['video-extend-time']))
    $videoExtendTime = (int)$videoSettings['video-extend-time'];
  if (isset($videoSettings['stop-time']))
    $stopTime = $videoSettings['stop-time'];
  if (isset($videoSettings['stop-text']))
    $stopText = $videoSettings['stop-text'];
  if (isset($videoSettings['combine-time-label']) && $videoSettings['combine-time-label'])
    $combineTimeLabel = true;
  if (isset($videoSettings['time-seconds']) && $videoSettings['time-seconds'])
    $timeSeconds = true;
  if (isset($videoSettings['background-color']))
    $forceBackgroundColor = $videoSettings['background-color'];
  if (isset($videoSettings['text-color']))
    $forceTextColor = $videoSettings['text-color'];
  if (isset($videoSettings['even-text-bg']))
    $evenTextBackground = $videoSettings['even-text-bg'];
  if (isset($videoSettings['odd-text-bg']))
    $oddTextBackground = $videoSettings['odd-text-bg'];
}

if ($combineTimeLabel)
  $labelHeight = 0;

$start = microtime(true);

// if FreeType isn't supported we can't draw text
$gdinfo = gd_info();
if(!isset($gdinfo['FreeType Support']) || !$gdinfo['FreeType Support']) {
  $labelHeight = 0;
  $timeHeight = 0;
}

// Load the information about the video that needs rendering
if (isset($_REQUEST['id'])) {
  $videoId = trim($_REQUEST['id']);
  $videoPath = './' . GetVideoPath($_REQUEST['id']);
  if (!is_file("$videoPath/video.ini")) {
    $optionsFile = "$videoPath/testinfo.json";
    if (gz_is_file($optionsFile)) {
      $tests = json_decode(gz_file_get_contents($optionsFile), true);
      if (isset($tests) && !is_array($tests))
        unset($tests);
    }
  }
}

// Render the video
if (isset($tests) && count($tests)) {
  $lock = Lock("video-$videoId", false, 600);
  if ($lock) {
    RenderVideo($tests);
    if (is_file("$videoPath/render.mp4"))
      rename("$videoPath/render.mp4", "$videoPath/video.mp4");
    $ini = 'completed=' . gmdate('c') . "\r\n";
    file_put_contents("$videoPath/video.ini", $ini);
    Unlock($lock);
  }
}

$elapsed = microtime(true) - $start;
//echo number_format($elapsed, 3) . " seconds";

function RenderVideo(&$tests) {
  global $width, $height, $maxAspectRatio, $videoExtendTime, $biggestThumbnail, $fps, $labelHeight, $timeHeight, $rowPadding, $speed, $fractionTime;
  
  // adjust the label sizes if we have a LOT of tests
  $scale = 1;
  $count = count($tests);
  if ($count > 49)
    $scale = 0;
  elseif ($count > 36)
    $scale = 0.5;
  elseif ($count > 25)
    $scale = 0.6;
  elseif ($count > 16)
    $scale = 0.7;
  elseif ($count > 9)
    $scale = 0.8;
  
  // Figure out the end time of the video and
  // make sure all of the tests are restored.
  $videoEnd = 0;
  $all_http = true;
  foreach($tests as &$test) {
    if (isset($test['label']) && strlen($test['label']) && substr($test['label'], 0, 7) !== 'http://')
      $all_http = false;
    if (isset($test['speed']) && $test['speed'] > 0 && $test['speed'] < 10)
      $speed = $test['speed'];
    if (isset($test['bare']) && $test['bare'])
      $scale = 0;
    if (isset($test['id']))
      RestoreTest($test['id']);
    if (isset($test['end']) && is_numeric($test['end']) && $test['end'] > $videoEnd)
      $videoEnd = $test['end'];
    if (isset($test['path']) &&
        isset($test['run']) &&
        isset($test['cached'])) {
      $progress = GetVisualProgress("./{$test['path']}", $test['run'], $test['cached']);
      if (isset($progress) && is_array($progress) && isset($progress['frames'])) {
        $test['frames'] = $progress['frames'];
        if (count($test['frames'])) {
          $frame = current($test['frames']);
          $dim = getimagesize("./{$frame['path']}");
          $size = max($dim[0], $dim[1]);
          if ($size > $biggestThumbnail)
            $biggestThumbnail = $size;
          $test['aspect'] = $dim[0] / $dim[1];
          if ($test['aspect'] > $maxAspectRatio)
            $maxAspectRatio = $test['aspect'];
          if (stripos($frame['file'], 'ms_') !== false) {
            $fps = 60;
            //$fractionTime = 100;
          }
        }
      }
    }
  }

  if ($scale < 1) {
    $labelHeight = ceil($labelHeight * $scale);
    $timeHeight = ceil($timeHeight * $scale);
    $rowPadding = ceil($rowPadding * $scale);
  }
  
  // no need for 60fps video if we are running in slow motion
  if ($speed < 0.5 && $fps == 60)
    $fps = 30;

  // Keep the time extension constant
  $videoExtendTime = $videoExtendTime * $speed;
  
  if ($all_http) {
    foreach($tests as &$test) {
      if (isset($test['label']) && strlen($test['label']) && substr($test['label'], 0, 7) === 'http://')
        $test['label'] = substr($test['label'], 7);
    }
  }
  
  if ($videoEnd > 0) {
    $videoEnd += $videoExtendTime;
    $frameCount = ceil(($videoEnd * $fps / 1000) / $speed);
    CalculateVideoDimensions($tests);
    $im = imagecreatetruecolor($width, $height);
    if ($im !== false) {
      RenderFrames($tests, $frameCount, $im);
      imagedestroy($im);
    }
  }
}

/**
* Figure out the dimensions of the resulting video
* 
*/
function CalculateVideoDimensions(&$tests) {
  global $width, $height, $minThumbnailSize, $padding, $labelHeight, $timeHeight, $timePadding, $rowPadding, $maxAspectRatio, $biggestThumbnail, $bottomMargin, $combineTimeLabel;
  
  $count = count($tests);
  if ($maxAspectRatio < 1) {
    // all mobile (narrow)
    if ($count <= 12)
      $rows = ceil($count / 6);
    elseif ($count <= 21)
      $rows = ceil($count / 7);
    elseif ($count <= 40)
      $rows = ceil($count / 8);
    else
      $rows = max(floor(sqrt($count) / 1.5), 1);
  } else {
    // wide-aspect (desktop)
    if ($count <= 9)
      $rows = ceil($count / 3);
    elseif ($count <= 16)
      $rows = ceil($count / 4);
    elseif ($count <= 25)
      $rows = ceil($count / 5);
    else
      $rows = max(floor(sqrt($count)), 1);
  }
  $columns = max(ceil($count / $rows), 1);
  
  $cellWidth = min($biggestThumbnail + $padding, max(floor($width / $columns), $minThumbnailSize + $padding));
  $cellHeight = min($biggestThumbnail + $padding + $labelHeight + $timeHeight + $rowPadding, max(floor(($height - (($labelHeight + $timeHeight + $rowPadding) * $rows)) / $rows), $minThumbnailSize + $padding));
  
  $videoWidth = ($cellWidth * $columns) + $padding;
  $width = floor(($videoWidth + 7) / 8) * 8;  // Multiple of 8
  
  // Tighten the row sizes to fit each video (keep columns fixed for labeling)
  $row_h = array();
  foreach ($tests as $position => &$test) {
    $row = floor($position / $columns);
    $column = $position % $columns;
    if (isset($row_h[$row]) && $row_h[$row] > 0)
      $row_h[$row] = min($row_h[$row], $test['aspect']);
    else
      $row_h[$row] = $test['aspect'];
  }
  $height = 0;
  foreach ($row_h as $row => $aspect) {
    if ($aspect > 0)
      $row_h[$row] = min($cellHeight, ceil($cellWidth / $aspect));
    else
      $row_h[$row] = $cellHeight;
    $height += $row_h[$row];
  }
  $videoHeight = $bottomMargin + $height + $padding + (($labelHeight + $timeHeight) * $rows) + ($rowPadding * ($rows - 1));
  $height = floor(($videoHeight + 7) / 8) * 8;  // Multiple of 8

  // figure out the left and right margins
  $left = floor(($width - $videoWidth) / 2);
  $top = floor(($height - $videoHeight) / 2);

  // Figure out the placement of each video  
  $y = $top + $labelHeight;
  foreach ($tests as $position => &$test) {
    $row = floor($position / $columns);
    $column = $position % $columns;
    if ($column == 0 && $row > 0)
      $y += $row_h[$row - 1] + $timeHeight + $labelHeight + $rowPadding;
    
    // if it is the last thumbnail, make sure it takes the bottom-right slot
    if ($position == $count - 1)
      $column = $columns - 1;
    
    // Thumbnail image
    $test['thumbRect'] = array();
    $test['thumbRect']['x'] = $left + ($column * $cellWidth) + $padding;
    $test['thumbRect']['y'] = $y + $padding;
    $test['thumbRect']['width'] = $cellWidth - $padding;
    $test['thumbRect']['height'] = $row_h[$row] - $padding;
    
    // Label
    if ($labelHeight > 0) {
      $test['labelRect'] = array();
      $test['labelRect']['x'] = $left + ($column * $cellWidth) + $padding;
      $test['labelRect']['y'] = $y - $labelHeight + $padding;
      $test['labelRect']['width'] = $cellWidth - $padding;
      $test['labelRect']['height'] = $labelHeight - $padding;
      $test['labelRect']['align'] = 'center';
    }
    
    // Time
    if ($timeHeight > 0) {
      $test['timeRect'] = array();
      $test['timeRect']['x'] = $left + ($column * $cellWidth) + $padding;
      $test['timeRect']['y'] = $y + $timePadding + $row_h[$row];
      $test['timeRect']['width'] = $cellWidth - $padding;
      $test['timeRect']['height'] = $timeHeight - $timePadding;
      $test['timeRect']['align'] = 'center';
      $test['timeRect']['even'] = $position % 2;
      
      if ($combineTimeLabel) {
        $test['labelRect'] = array();
        $test['labelRect']['x'] = $left + ($column * $cellWidth) + $padding;
        $test['labelRect']['y'] = $y + $timePadding + $row_h[$row];
        $test['labelRect']['width'] = floor(($cellWidth - $padding) / 2);
        $test['labelRect']['height'] = $timeHeight - $timePadding;
        $test['labelRect']['align'] = 'left';

        $test['timeRect']['align'] = 'right';
        $test['timeRect']['width'] = floor(($cellWidth - $padding) / 2);
        $test['timeRect']['x'] += $test['labelRect']['width'];
      }
    }
  }
}

/**
* Render the actual video frames
* 
* @param mixed $tests
* @param mixed $frameCount
* @param mixed $im
*/
function RenderFrames(&$tests, $frameCount, $im) {
  global $width, $height, $backgroundColor, $videoPath, $image_bytes, $textColor, $encodeFormat,
         $encoderSpeed, $fps, $labelHeight, $forceBackgroundColor, $forceTextColor, $combineTimeLabel,
         $evenTextBackground, $oddTextBackground, $bgEvenText, $bgOddText;

  // allocate the background and foreground colors
  $bgcolor = isset($tests[0]['bg']) ? html2rgb($tests[0]['bg']) : html2rgb('000000');
  $color = isset($tests[0]['text']) ? html2rgb($tests[0]['text']) : html2rgb('ffffff');
  if (isset($forceBackgroundColor))
    $bgcolor = html2rgb($forceBackgroundColor);
  if (isset($forceTextColor))
    $color = html2rgb($forceTextColor);
  $bgEvenTextColor = isset($evenTextBackground) ? html2rgb($evenTextBackground) : $bgcolor;
  $bgOddTextColor = isset($oddTextBackground) ? html2rgb($oddTextBackground) : $bgcolor;
  
  // prepare the image
  $backgroundColor = imagecolorallocate($im, $bgcolor[0], $bgcolor[1], $bgcolor[2]);
  $textColor = imagecolorallocate($im, $color[0], $color[1], $color[2]);
  $bgEvenText = imagecolorallocate($im, $bgEvenTextColor[0], $bgEvenTextColor[1], $bgEvenTextColor[2]);
  $bgOddText = imagecolorallocate($im, $bgOddTextColor[0], $bgOddTextColor[1], $bgOddTextColor[2]);
  imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $backgroundColor);
  
  // figure out what a good interval for keyframes would be based on the video length
  $keyInt = min(max(6, $frameCount / 30), 240);
  
  // set up ffmpeg
  $descriptors = array(0 => array("pipe", "r"));
  $videoFile = realpath($videoPath) . '/render.mp4';
  if (is_file($videoFile))
    unlink($videoFile);
  $codec = $encodeFormat == 'jpg' ? 'mjpeg' : $encodeFormat;
  $command = "ffmpeg -f image2pipe -vcodec $codec -r $fps -i - ".
                  "-vcodec libx264 -r $fps -crf 24 -g $keyInt ".
                  "-preset $encoderSpeed -y \"$videoFile\"";
  $ffmpeg = proc_open($command, $descriptors, $pipes);
  if (is_resource($ffmpeg)){
    if ($labelHeight > 0 || $combineTimeLabel)
      DrawLabels($tests, $im);
    for ($frame = 0; $frame < $frameCount; $frame++) {
      RenderFrame($tests, $frame, $im);
      if (isset($image_bytes))
        fwrite($pipes[0], $image_bytes);
    }
    fclose($pipes[0]);
    proc_close($ffmpeg);
  }
}

/**
* Render an individual frame
* 
* @param mixed $tests
* @param mixed $frame
* @param mixed $im
*/
function RenderFrame(&$tests, $frame, $im) {
  global $videoPath, $image_bytes, $encodeFormat, $fps, $speed;
  static $firstImage = true;
  $updated = false;
  $frameTime = ceil(($frame * 1000 / $fps) * $speed);
  foreach ($tests as &$test) {
    if (DrawTest($test, $frameTime, $im))
      $updated = true;
  }
  if ($updated) {
    if ($firstImage) {
      imagepng($im, "$videoPath/video.png");
      $firstImage = false;
    }
    ob_start();
    if ($encodeFormat == 'jpg')
      imagejpeg($im, NULL, 85);
    else
      imagepng($im);
    $image_bytes = ob_get_contents();
    ob_end_clean();
  }
}

/**
* Draw the labels for all of the tests
* 
*/
function DrawLabels($tests, $im) {
  global $min_font_size, $labelFont, $textColor, $forceFontSize, $bgEvenText, $bgOddText;
  // First, go through and pick a font size that will fit all of the labels
  if ($forceFontSize) {
    $font_size = $forceFontSize;
  } else {
    $maxLabelLen = 30;
    do {
      $font_size = GetLabelFontSize($tests);
      if ($font_size < $min_font_size) {
        // go through and trim the length of all the labels
        foreach($tests as &$test) {
          if (isset($test['labelRect']) && isset($test['label']) && strlen($test['label']) > $maxLabelLen) {
            $test['label'] = substr($test['label'], 0, $maxLabelLen) . '...';
          }
        }
        $maxLabelLen--;
      }
    } while($font_size < $min_font_size && $maxLabelLen > 1);
  }
  
  if ($font_size > $min_font_size) {
    foreach($tests as $index => &$test) {
      if (isset($test['labelRect']) && isset($test['label']) && strlen($test['label'])) {
        $rect = $test['labelRect'];
        $bgColor = ($index % 2) ? $bgEvenText : $bgOddText;
        imagefilledrectangle($im, $rect['x'], $rect['y'], $rect['x'] + $rect['width'], $rect['y'] + $rect['height'], $bgColor);
        $pos = CenterText($im, $rect['x'], $rect['y'], $rect['width'], $rect['height'], $font_size, $test['label'], $labelFont, null, $test['labelRect']['align']);
        if (isset($pos))
          imagettftext($im, $font_size, 0, $pos['x'],  $pos['y'], $textColor, $labelFont, $test['label']);
      }
    }
  }
}

function GetLabelFontSize($tests) {
  global $labelFont;
  $font_size = null;
  foreach($tests as $test) {
    if (isset($test['labelRect']) && isset($test['label']) && strlen($test['label'])) {
      $size = GetFontSize($test['labelRect']['width'], $test['labelRect']['height'], $test['label'], $labelFont);
      if (!isset($font_size) || $size < $font_size)
        $font_size = $size;
    }
  }
  return $font_size;
}

/**
* Draw the appropriate thumbnail for the given frame
* 
* @param mixed $test
* @param mixed $frameTime
* @param mixed $im
*/
function DrawTest(&$test, $frameTime, $im) {
  global $backgroundColor;
  $updated = false;

  // find the closest video frame <= the target time
  $frame_ms = null;
  foreach ($test['frames'] as $ms => $frame) {
    if ($ms <= $frameTime && $ms <= $test['end'] && (!isset($frame_ms) || $ms > $frame_ms))
      $frame_ms = $ms;
  }
  $path = null;
  if (isset($frame_ms))
    $path = $test['frames'][$frame_ms]['path'];
  
  $need_grey = false;
  if (!isset($test['done']) && $frameTime > $test['end']) {
    $need_grey = true;
    $test['done'] = true;
  }
    
  // see if we actually need to draw anything
  if (isset($path) && (!isset($test['lastFrame']) || $test['lastFrame'] !== $path || $need_grey)) {
    $test['lastFrame'] = $path;
    if (strtolower(substr($path, -4)) == '.png')
      $thumb = imagecreatefrompng("./$path");
    else
      $thumb = imagecreatefromjpeg("./$path");
    if ($thumb) {
      if ($need_grey)
        imagefilter($thumb, IMG_FILTER_GRAYSCALE);
      // Scale and center the thumbnail aspect-correct in the area reserved for it
      $rect = $test['thumbRect'];
      $thumb_w = imagesx($thumb);
      $thumb_h = imagesy($thumb);
      $scale = min($rect['width'] / $thumb_w, $rect['height'] / $thumb_h);
      $w = min(floor($thumb_w * $scale), $rect['width']);
      $h = min(floor($thumb_h * $scale), $rect['height']);
      $x = $rect['x'] + floor(($rect['width'] - $w) / 2);
      $y = $rect['y'] + floor(($rect['height'] - $h) / 2);
      imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $backgroundColor);
      fastimagecopyresampled($im, $thumb, $x, $y, 0, 0, $w, $h, $thumb_w, $thumb_h, 4);
      imagedestroy($thumb);
      $updated = true;
    }
  }

  if (isset($test['timeRect']) && $frameTime <= $test['end'] && DrawFrameTime($test, $frameTime, $im, $test['timeRect']))
    $updated = true;

  return $updated;
}

/**
* Draw the time ticker below the video.  We need to draw the
* time, period and fraction separately so we can keep the period
* fixed in place and not have things move around.
* 
* @param mixed $test
* @param mixed $frameTime
* @param mixed $im
* @param mixed $rect
*/
function DrawFrameTime(&$test, $frameTime, $im, $rect) {
  global $timeHeight, $backgroundColor, $timeFont, $textColor, $fps, $fractionTime, $timeSeconds, $bgEvenText, $bgOddText, $stopTime, $stopText;
  static $font_size = 0;
  static $ascent = 0;
  $updated = false;
  $suffix = $timeSeconds ? 's' : '';
  
  if (!$font_size)
    $font_size = GetFontSize($rect['width'], $rect['height'], "000.00", $timeFont);
  if (!$ascent && $font_size) {
    $box = imagettfbbox($font_size, 0, $timeFont, "12345678.90");
    $ascent = abs($box[7]);
  }
  if (!isset($test['periodRect'])) {
    $test['periodRect'] = array();
    $pos = CenterText($im, $rect['x'], $rect['y'], $rect['width'], $rect['height'], $font_size, "000.00$suffix", $timeFont, $ascent, $rect['align']);
    $test['periodRect']['y'] = $pos['y'];
    $posText = $rect['align'] == 'right' ? ".00$suffix" : '.';
    $pos = CenterText($im, $rect['x'], $rect['y'], $rect['width'], $rect['height'], $font_size, $posText, $timeFont, $ascent, $rect['align']);
    $test['periodRect']['x'] = $pos['x'];
    $box = imagettfbbox($font_size, 0, $timeFont, '.');
    $test['periodRect']['width'] = abs($box[4] - $box[0]);
  }
  
  $seconds = floor($frameTime / 1000);
  $fraction = floor($frameTime / (1000 / $fractionTime)) % $fractionTime;
  if ($fractionTime == 100)
    $fraction = sprintf("%02d", $fraction);
  elseif ($fractionTime == 1000)
    $fraction = sprintf("%03d", $fraction);
  if (!isset($test['endText']) &&
      isset($stopTime) &&
      isset($test['pageData'][$test['run']][$test['cached']][$stopTime]) &&
      $frameTime >= $test['pageData'][$test['run']][$test['cached']][$stopTime]) {
    $prefix = isset($stopText) ? "$stopText " : '';
    $test['endText'] = "$prefix$seconds.$fraction$suffix";
  }
  $time = isset($test['endText']) ? $test['endText'] : "$seconds.$fraction";
  if (!isset($test['last_time']) || $test['last_time'] !== $time) {
    $updated = true;
    $test['last_time'] = $time;
    
    // erase the last time
    $bgColor = $rect['even'] ? $bgEvenText : $bgOddText;
    imagefilledrectangle($im, $rect['x'], $rect['y'], $rect['x'] + $rect['width'], $rect['y'] + $rect['height'], $bgColor);
    
    if (isset($test['endText'])) {
      $pos = CenterText($im, $rect['x'], $rect['y'], $rect['width'], $rect['height'], $font_size, $test['endText'], $timeFont, $ascent, $rect['align']);
      if (isset($pos))
        imagettftext($im, $font_size, 0, $pos['x'],  $pos['y'], $textColor, $timeFont, $test['endText']);
    } else {
      // draw the period
      imagettftext($im, $font_size, 0, $test['periodRect']['x'],  $test['periodRect']['y'], $textColor, $timeFont, '.');
      
      // draw the seconds
      $box = imagettfbbox($font_size, 0, $timeFont, $seconds);
      $s_width = abs($box[4] - $box[0]);
      $box = imagettfbbox($font_size, 0, $timeFont, "$seconds.");
      $pad = abs($box[4] - $box[0]) - $s_width;
      imagettftext($im, $font_size, 0, $test['periodRect']['x'] + $test['periodRect']['width'] - $s_width - $pad,  $test['periodRect']['y'], $textColor, $timeFont, $seconds);
      
      //draw the fraction
      $box = imagettfbbox($font_size, 0, $timeFont, "$fraction$suffix");
      $t_width = abs($box[4] - $box[0]);
      $box = imagettfbbox($font_size, 0, $timeFont, ".$fraction$suffix");
      $pad = abs($box[4] - $box[0]) - $t_width + 1;
      imagettftext($im, $font_size, 0, $test['periodRect']['x'] + $pad,  $test['periodRect']['y'], $textColor, $timeFont, "$fraction$suffix");
    }
  }
  
  return $updated;
}

function GetFontSize($width, $height, $text, $font) {
  global $forceFontSize;
  
  if ($forceFontSize) {
    $size = $forceFontSize;
  } else {
    $small = 0;
    $big = 100;
    $size = 50;
    do {
      $last_size = $size;
      $box = imagettfbbox($size, 0, $font, $text);
      $w = abs($box[4] - $box[0]);
      $h = abs($box[5] - $box[1]);
      if ($w < $width && $h < $height) {
        $small = $size;
        $size = floor($size + (($big - $size) / 2));
      } else {
        $big = $size;
        $size = floor($size - (($size - $small) / 2));
      }
    } while ($last_size !== $size && $size > 0);
  }
  
  return $size;
}

function CenterText($im, $x, $y, $w, $h, $size, $text, $font, $ascent = null, $align) {
  global $textPadding;
  $ret = null;
  if (!$size)
    $size = GetFontSize($w, $h, $text);
  if ($size) {
    $box = imagettfbbox($size, 0, $font, $text);
    if (!isset($ascent))
      $ascent = abs($box[7]);
    $ret = array();
    $out_w = abs($box[4] - $box[0]);
    $out_h = abs($box[5] - $box[1]);
    if ($align == 'left')
      $ret['x'] = $x + $textPadding;
    elseif ($align == 'right')
      $ret['x'] = floor($x + ($w - $out_w - $textPadding));
    else
      $ret['x'] = floor($x + (($w - $out_w) / 2));
    $ret['y'] = floor($y + (($h - $out_h) / 2)) + $ascent;
  }
  return $ret;
}
?>
