<?php
/**
* @name PWAssist.php
* Task: create service worker js code with all resources listed in "caching list"
* @author Alexander Selifonov, <alex [at] selifan {dot} ru>
* @link https://github.com/selifan/PWAssist
* @version 0.10
* Created 2017-12-03
* Updated 2018-01-10
*/

class PWAssist {

    const MANIFEST_NAME = 'manifest.json';
    const PWA_CONFIGFILE = './pwa_config.xml';
    const VERSION = '0.10';

    static $BR = '<br>';

    private static $_inConcole = false;
    private static $_fl = array();

    private static $_skip_keys = array(
      'created_date','fileTypes','ignoreFolders','ignoreFiles','fileSizeLimit','dynamicTest'
    );

    // default configuration
    private static $defaultCfg = array(
      'appName' => 'My Application'
      ,'copyRight' => 'My Company'
      ,'author' => 'Noname'
      ,'appId'  => 'myPWA'
      ,'appDesc' => 'My Progressive Web Application'
      ,'appShortName' => 'My PWA'
      ,'appVersion' => '1'
      ,'swTemplate' => 'PWAssist.sw-template.js'
      ,'swFilename' => 'service-worker.js'
      ,'fileTypes' => 'js,htm,html,css,png,gif,jpg,ttf,woff,eot,svg,json'
      ,'ignoreFolders' =>  '.git,tmp,temp'
      ,'ignoreFiles' => '.gitignore,_temp.*,todo.*,manifest.json,*.log,*.tmp'
      ,'fileSizeLimit' => '2M'
      ,'startUri'    => './'
      ,'startFolder' => './'
      ,'cacheName' => 'mypwa'
      ,'dynamicPart' => '?refresh=,&refresh='
       // manifest parameters
      ,'lang' => 'en'
      ,'iconResolutions'=> '48,128,192,256'
      ,'orientation' => 'landscape'
      ,'baseIcon' => 'app.png'
      ,'iconFilenameTemplate' => 'img/myapp{size}.png'
      ,'backgroundColor' => '#CCCCFF'
      ,'themeColor'  => '#0404A0'
      ,'pngQuality' => 0
    );

    private static $_swTemplates = array();

    private static $cfg = array();
    /**
    * Add or replace ignore-folders names
    *
    * @param mixed $items folder list to add (comma separated string or array)
    * @param mixed $clean if true, standard folders list will be cleared
    */
    public static function addIgnoreFolders($items, $clean = false) {
        self::_addItemsArray($items, $clean, 'ignoreFolders');
    }
    /**
    * Add or replace "ignore" file names
    *
    * @param mixed $items file mask list to add (comma separated string or array)
    * @param mixed $clean if true, standard folders list will be cleared
    */
    public static function addIgnoreFiles($items, $clean = false) {
        self::_addItemsArray($items, $clean, 'ignoreFiles');
    }

    /**
    * Add (or replace) default "supported" file extensions
    *
    * @param mixed $types file extensions to be handled (comma separated string or array)
    * @param mixed $clean if true, standard extension list will be cleared
    */
    public static function addFileTypes($items, $clean = false) {
        self::_addItemsArray($items, $clean, 'fileTypes');
    }

    private static function _addItemsArray($items, $clean, $key) {
        $parArr = (is_string($items)?explode(',', $items) : (is_array($items) ? $items : array()));
        $tmpArr = explode(',', self::$cfg[$key]);
        if ($clean) $tmpArr = array();
        foreach($parArr as $onet) {
            if (!in_array($onet, $tmpArr))
                $tmpArr[] = $onet;
        }
        self::$cfg[$key] = implode(',',$tmpArr);
    }

    public static function loadConfig($options='') {

        if (is_file(self::PWA_CONFIGFILE)) {
            try {
                $xml = simplexml_load_file(self::PWA_CONFIGFILE);
            }
            catch (Exception $e) {
                die ('Wrong XML in pwa_config.xml. Job aborted: '. $e->getMessage());
            }
            if (is_object($xml))
            foreach($xml->children() as $id => $val) {
                if ($val!=='') self::$cfg[$id] = (string)$val;
            }
            else echo "Wrong XML in pwa_config.xml ignored!".self::$BR;
        }

        if (is_array($options)) foreach($options as $id => $val) {
            if (!empty($val)) self::$cfg[$id] = (string)$val;
        }
    }
    /**
    * Initialization, with optional configuration data array
    *
    * @param mixed $options associative array
    */
    public static function init($options = false) {

        self::_loadGlobalConfig();
        self::$cfg = self::$defaultCfg;
        self::$_inConcole = (empty($_SERVER['REMOTE_ADDR']));
        self::$BR = (self::$_inConcole  ? "\n" : '<br>');

        self::loadConfig($options);
        if (self::$_inConcole) {
            self::runConsole();
            exit;
        }
        $p = array_merge($_GET, $_POST);
        $action = (isset($p['action']) ? $p['action'] : '');

        if ($action === 'sw' || empty($_SERVER['REMOTE_ADDR'])) {
            $response = self::createSW();
            exit($response);
        }
        elseif ($action === 'manifest') {
            $response = self::createManifest();
            exit($response);
        }
        elseif($action === 'icons') {
            $response = self::createIcons();
            exit($response);
        }
        elseif($action == 'saveparams') {
            self::saveParameters($options);
        }
        self::htmlForm();
    }

    /**
    * Loading "global" configuration if file PWAssist-global-cfg.xml exists
    *
    */
    private static function _loadGlobalConfig() {

        $globalXml = __DIR__ . DIRECTORY_SEPARATOR . 'PWAssist-global-cfg.xml';
        if (is_file($globalXml)) {
            $xml = simplexml_load_file($globalXml);
            foreach($xml->children() as $id => $val) {
                self::$defaultCfg[$id] = (string)$val;
            }
            if (isset($xml->swTemplates)) {
                foreach($xml->swTemplates->children() as $swfile) {
                    $src = isset($swfile['src']) ? (string)$swfile['src'] : '';
                    if ($src !=='') {
                        $title = (isset($swfile['title']) ? (string)$swfile['title'] : $src);
                        self::$_swTemplates[$src] = ($title ? $title : $src);
                    }
                }
            }
        }
    }

    /**
    * Running console mode (called from shell, batch scripts etc)
    *
    */
    public static function runConsole() {
        global $argv;
        if (count($argv) < 2) {
            echo "Calling from shell syntax:\n  php $arg[0] all|sw|icons|manifest\n"
              . "  all - perform all operatins\n"
              . "  sw - generate servide worker js module\n"
              . "  icons - create icons for all registered resolutions\n"
              . "  man (or manifest) - create manifest.json\n"
              ;
        }
        else foreach($argv as $i=> $arg) {
            if ($i == 0) echo "Running $arg...\n";
            else switch(strtolower($arg)) {
                case 'all':
                    echo "All: performing all jobs" . self::$BR;
                    echo self::createIcons() . self::$BR;
                    echo self::createManifest() . self::$BR;
                    echo self::createSW() . self::$BR;
                    break;
                case 'sw':
                    echo self::createSW() . self::$BR;
                    break;
                case 'icons':
                    echo self::createIcons() . self::$BR;
                    break;
                case 'man': case 'manifest':
                    echo self::createManifest() . self::$BR;
                    break;
                default:
                    echo "unsupported command: $arg" . self::$BR;
                    break;
            }
        }
    }

    public static function htmlForm() {
        if (self::$_inConcole) return;
        $jsname = self::$cfg['swFilename'];
        $thisfile = $_SERVER['REQUEST_URI'];
        $quest = strpos($thisfile, '?');
        if ($quest) $thisfile = substr($thisfile, 0,$quest);
        $homeLink = self::$cfg['startUri'];
        $appid = self::$cfg['appId'];
        $appname = self::$cfg['appName'];
        $appShortName = self::$cfg['appShortName'];
        $appdesc = self::$cfg['appDesc'];
        $hcfg = array();
        foreach(self::$cfg as $key=>$val) {
            $hcfg[$key] = (is_array($val) ? implode(',', $val) : $val);
        }
        $templateInput = '';

        if(count(self::$_swTemplates) == 0) {
            $templateInput = "<input type=\"text\" name=\"swTemplate\" style=\"width:15em\" value=\"$hcfg[swTemplate]\"/>";
        }
        else {
            $swt = array_merge( array('' => 'Built-in Template'), self::$_swTemplates);
            $templateInput = "<select name=\"swTemplate\" style=\"width:25em\">";

            foreach($swt as $src=>$title) {
                $sel = (($hcfg['swTemplate'] == $src) ? 'selected="selected"':'');
                $templateInput.= "<option value=\"$src\" $sel>$title</option>";
            }
            $templateInput .= '</select>';
        }

        $html = <<< EOHTM
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en-EN" lang="en-EN" >
<head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8" />
<style type="text/css">
body, html {
    font-family: tahoma,verdana,arial,helvetica,sans-serif;
    font-size: 11pt;
}
.log { border: 1px solid #aaa; background: #eee; width:auto; height:400px; overflow:auto }
.pwaform { float: left; width:auto; border: 1px solid #999; margin:0 10px 10px 0; padding:8px;}
input[type=button] { border: 1px solid #999; background: #ddd; margin: 0 0.5em 0.5em 0;}
input[type=submit] { border: 1px solid #999; background: #ddd; margin: 0 0.5em 0.5em 0;}
input[type=text] { font-size: 9pt; border: 1px solid #999; background: #fff; }
table.formtable td:first-child { white-space: nowrap; text-align:right; padding-right:0.3em; }
hr { color:#aaa; height:0; size:1px; }
</style>

<script type="text/javascript">
function generate(mode) {
    var xhttp = new XMLHttpRequest();
    console.log('start XHR');
    xhttp.onreadystatechange = function() {
        if (this.readyState == 4) {
          if(this.status == 200) {
            document.getElementById("log").innerHTML = xhttp.responseText;
            console.log('response OK');
          }
          else console.log('error returned:' + this.status, xhttp.responseText);
        }
    };
    xhttp.open("POST", "$thisfile?action="+mode, true);
    xhttp.send();
}
</script>

<title>PWA assistant, application: $appname / $appdesc</title>
</head>
<body>

<h2>PWA assistant, application: $appname / $appdesc</h2>
<a href="$homeLink">Back to aplication</a>
<br><br>
<div class="pwaform" style="width:550px">
Create :
<input type="button" value="Icons" onclick="generate('icons')" />
<input type="button" value="Service worker file" onclick="generate('sw')" />
<input type="button" value="manifest.json" onclick="generate('manifest')" />
<br>
Result:
<div class="log" id="log" style="width:99%; min-height:100px; max-height:200px; overflow:auto">
</div>
</div>
<form id="pwaparams" method="post" action="$thisfile">
<input type="hidden" name="action" value="saveparams" />
<div class="pwaform" style="width:550px; min-height:400px; background-color:#eee;">
<table class="formtable">
  <tr>
    <td>App id</td><td style="width:90%"><input type="text" name="appId" style="width:10em" value="$hcfg[appId]" /></td>
  </tr>
  <tr>
    <td>App Name</td><td><input type="text" name="appName" style="width:10em" value="$hcfg[appName]"/></td>
  </tr>
  <tr>
    <td>App Short Name</td><td><input type="text" name="appShortName" style="width:10em" value="$hcfg[appShortName]"/></td>
  </tr>
  <tr>
    <td>App Description</td><td><input type="text" name="appDesc" style="width:25em" value="$hcfg[appDesc]"/></td>
  </tr>
  <tr>
    <td>Start URI</td><td><input type="text" name="startUri" style="width:15em" value="$hcfg[startUri]"/></td>
  </tr>
  <tr>
    <td>Home Folder</td><td><input type="text" name="startFolder" style="width:15em" value="$hcfg[startFolder]"/></td>
  </tr>
  <tr>
    <td>App Version</td><td><input type="text" name="appVersion" style="width:4em" value="$hcfg[appVersion]"/></td>
  </tr>
  <tr>
    <td>Lang</td><td><input type="text" name="lang" style="width:4em" value="$hcfg[lang]"/></td>
  </tr>
  <tr>
    <td>SW Template File</td><td>$templateInput</td>
  </tr>
  <tr>
    <td>Service Worker Filename</td><td><input type="text" name="swFilename" style="width:15em" value="$hcfg[swFilename]"/></td>
  </tr>

  <tr>
    <td>Cached File Extensions</td><td><input type="text" name="fileTypes" style="width:25em" value="$hcfg[fileTypes]"/></td>
  </tr>

  <tr>
    <td>Folders To Ignore</td><td><input type="text" name="ignoreFolders" style="width:25em" value="$hcfg[ignoreFolders]"/></td>
  </tr>
  <tr>
    <td>Cached Files Size Limit</td><td><input type="text" name="fileSizeLimit" style="width:10em" value="$hcfg[fileSizeLimit]"/></td>
  </tr>
  <tr>
    <td>Files To Ignore</td><td><input type="text" name="ignoreFiles" style="width:25em" value="$hcfg[ignoreFiles]"/></td>
  </tr>
  <tr>
    <td>Cache Name</td><td><input type="text" name="cacheName" style="width:25em" value="$hcfg[cacheName]"/></td>
  </tr>
  <tr>
    <td>Source Icon File</td><td><input type="text" name="baseIcon" style="width:25em" value="$hcfg[baseIcon]"/></td>
  </tr>
  <tr>
    <td>All Icon Sizes</td><td><input type="text" name="iconResolutions" style="width:25em" value="$hcfg[iconResolutions]"/></td>
  </tr>
  <tr>
    <td>Icon Filename Template</td><td><input type="text" name="iconFilenameTemplate" style="width:25em" value="$hcfg[iconFilenameTemplate]"/></td>
  </tr>
  <tr>
    <td>Background Color</td><td><input type="text" name="backgroundColor" style="width:10em" value="$hcfg[backgroundColor]"/></td>
  </tr>
  <tr>
    <td>Theme Color</td><td><input type="text" name="themeColor" style="width:10em" value="$hcfg[themeColor]"/></td>
  </tr>
  <tr>
    <td>Dynamic Requests Contain:</td><td><input type="text" name="dynamicPart" style="width:25em" value="$hcfg[dynamicPart]"/></td>
  </tr>

  <tr><td colspan="2"><hr noshade>
    <input type="submit" value="Save parameters" />
  </td></tr>
</table>
</div>
</form>
</body>
</html>
EOHTM;
        die($html);
    }
    /**
    * Performs the job -
    * (re)creates service worker file
    */
    public static function createSW() {

        $log = [];
        self::$_fl = array("'" . self::$cfg['startUri'] . "'");
        self::_scanDir(self::$cfg['startFolder']);
        $dateCreate = date('d.m.Y H:i:s');
        $filelist = implode(",\n  ", self::$_fl);
        $sw_tplname = __DIR__ . DIRECTORY_SEPARATOR . self::$cfg['swTemplate'];
        $dynamicTest = "";
        $dynamicPart = explode(',', self::$cfg['dynamicPart']);
        foreach($dynamicPart as $dypart) {
            $dynamicTest .= "\n  if(e.request.url.indexOf('$dypart') > -1) isDynamic = true;";
        }

        if (!empty(self::$cfg['swTemplate']) && is_file($sw_tplname)) $swCode = file_get_contents($sw_tplname);
        else {
            if (!empty(self::$cfg['swTemplate']))
                return ('SW generating failed: template file not found :' . self::$BR . $sw_tplname);
            $swCode = <<< EOJS
// Service Worker for PWA {app_name} {app_desc}
// Generated {created_date} by PWAssist.php
// Author {author}
// Copyright {copyright}
// version: {version}
var dataCacheName = 'data-{cachename}-v{version}';
var cacheName = '{cachename}-{version}';

var filesToCache = [
  {filelist}
];

self.addEventListener('install', function(e) {
  console.log('[ServiceWorker] Install');
  e.waitUntil(
    caches.open(cacheName).then(function(cache) {
      console.log('[ServiceWorker] Caching app shell');
      return cache.addAll(filesToCache);
    })
  );
});

self.addEventListener('activate', function(e) {
  console.log('[ServiceWorker] Activate');
  e.waitUntil(
    caches.keys().then(function(keyList) {
      return Promise.all(keyList.map(function(key) {
        if (key !== cacheName && key !== dataCacheName) {
          console.log('[ServiceWorker] Removing old cache', key);
          return caches.delete(key);
        }
      }));
    })
  );
  return self.clients.claim();
});

self.addEventListener('fetch', function(e) {
  console.log('[Service Worker] Fetch', e.request.url);
  // All requests with "dynamic parts" in parameter treated as non-cached, to be performed!
  var isDynamic = false;
  {dynamicTest}
  if (isDynamic) {
    /* When the request URL contains "dynamic mark", the app is asking for fresh data */
    e.respondWith(
      caches.open(dataCacheName).then(function(cache) {
        return fetch(e.request).then(function(response){
          cache.put(e.request.url, response.clone());
          return response;
        });
      })
    );
  } else {
    /*
     * The app is asking for app shell files. In this scenario the app uses the
     * "Cache, falling back to the network" offline strategy
     */
    e.respondWith(
      caches.match(event.request).then(function(resp) {
        return resp || fetch(event.request).then(function(response) {
          return caches.open(dataCacheName).then(function(cache) {
            cache.put(event.request, response.clone());
            return response;
          });
        });
      })
    );
  }
});
EOJS;
        }
        $appname = (self::$cfg['appName']) ? self::$cfg['appName'] :
          ucwords(str_replace(array('-','_'), ' ', self::$cfg['cacheName']));
        $replarr = array(
         '{created_date}'  => date('d.m.Y H:i:s')
         ,'{version}'      => self::$cfg['appVersion']
         ,'{author}'       => self::$cfg['author']
         ,'{copyright}'    => self::$cfg['copyRight']
         ,'{cachename}'    => self::$cfg['cacheName']
         ,'{app_name}'     => $appname
         ,'{app_desc}'     => self::$cfg['appDesc']
         ,'{filelist}' => $filelist
         ,'{dynamicTest}'  => $dynamicTest
        );

        # add user-defined cfg values to replace list
        foreach(self::$cfg as $key => $val) {
            if (is_string($val) && !in_array($key, self::$_skip_keys, true))
                $replarr["{{$key}}"] = $val;
        }

        $swCode = strtr($swCode, $replarr);
        file_put_contents(self::$cfg['swFilename'], $swCode);
        return (
          'File created: '. self::$cfg['swFilename'] . self::$BR . '- Files to be cached by service worker: '
            . count(self::$_fl) . self::$BR
        );

    }

    public static function saveParameters($options='') {

        $pars = array_merge($_GET, $_POST);
        $cfg = self::$defaultCfg;
        // save updated cfg to XML:
        $cfgbody = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<PWA-config>\n";
        foreach ($cfg as $key=>$val) {
            # if (is_array($val)) $val = implode(',', $val);
            $newval = (isset($pars[$key]) ? trim($pars[$key]) : $val);
            if ($newval === $val)
                continue; // don't save default values

            $cfgbody .= "  <$key>" . htmlentities($newval) . "</$key>\n";
        }
        $cfgbody .= "</PWA-config>";
        file_put_contents(self::PWA_CONFIGFILE, $cfgbody);
        self::loadConfig($options); // re-read changed configuration
    }
    /**
    * Re-generating mainfest and icon for all resolutions
    *
    */
    public static function createManifest() {
       $cfg = self::$cfg;
       $iconsbody = array();

       foreach(explode(',',$cfg['iconResolutions']) as $res) {
           $rr = "{$res}x{$res}";
           $iconName = str_replace('{size}', "$res" , $cfg['iconFilenameTemplate']);
           $iconsbody[] = "{ \"src\": \"$iconName\", \"sizes\": \"$rr\", \"type\": \"image/png\" }";
       }
       $iconsText = implode("\n    ,", $iconsbody);
       $manifestBody = <<< EOJSON
{
  "name": "$cfg[appName]",
  "short_name": "$cfg[appShortName]",
  "lang": "$cfg[lang]",
  "description": "$cfg[appDesc]",
  "icons": [
    $iconsText
  ],
  "start_url": "$cfg[startUri]",
  "display": "standalone",
  "orientation": "$cfg[orientation]",
  "background_color": "$cfg[backgroundColor]",
  "theme_color": "$cfg[themeColor]"
}
EOJSON;

        file_put_contents(($cfg['startFolder'] . self::MANIFEST_NAME), $manifestBody);
        return "Manifest createdm size: " .self::MANIFEST_NAME
          . ', size: ' . filesize($cfg['startFolder'] . self::MANIFEST_NAME);
    }

    private static function createIcons() {
        include_once('basefunc.php');
        if (empty(self::$cfg['baseIcon'])) return '';
        if (!function_exists('imagecopyresized')) return 'GD not installed in Your PHP configuration, icons not created';

        if (!is_file(self::$cfg['baseIcon']))
            return self::$cfg['baseIcon'] . " - Base icon file not found, Icons creation impossible";
        $ftype = strtolower(substr(self::$cfg['baseIcon'], -4));
        switch ($ftype) {
            case '.png':
                $srcImg = imagecreatefrompng(self::$cfg['baseIcon']);
                break;
            case '.gif':
                $srcImg = imagecreatefromgif(self::$cfg['baseIcon']);
                break;
            case 'webp':
                $srcImg = imagecreatefromwebp(self::$cfg['baseIcon']);
                break;
            case '.jpg': case 'jpeg':
                $srcImg = imagecreatefromjpeg(self::$cfg['baseIcon']);
                break;
            default:
                return "Unsupported Icon image type";
        }

        list($srcWidth, $srcHeight) = getimagesize(self::$cfg['baseIcon']);
        $ret = '';
        foreach(explode(',', self::$cfg['iconResolutions']) as $res) {
            $newSize = (int)$res;
            $rr = "{$newSize}x{$newSize}";
            $iconName = str_replace('{size}', "$res" , self::$cfg['iconFilenameTemplate']);
            $dirnm = dirname($iconName);
            if (!is_dir($dirnm)) @mkdir($dirnm);
            if (!is_dir($dirnm)) $ret .= "Cannot create folder for icon file $iconName".self::$BR;
            else { # resize picture according to passed scale
                $img2 = imagecreatetruecolor($newSize,$newSize);
                imagealphablending($img2, false);
                imagesavealpha($img2, true); // saving transparent pixels
                // imagecopyresampled($newimg, $img,0,0,0,0,$sizeX,$sizeY,$old_x,$old_y); imagecopyresized
                imagecopyresampled($img2,$srcImg,0,0,0,0,$newSize,$newSize,$srcWidth,$srcHeight);
                $result = imagepng($img2,$iconName, self::$cfg['pngQuality']);
                $ret .= ($result) ? "Icon $iconName created".self::$BR : "File $iconName creation error".self::$BR;
                imagedestroy($img2);
            }
        }
        imagedestroy($srcImg);

        return $ret;
    }

    private static function _scanDir($foldername) {

        global $argv;
        $fmask = $foldername . (substr($foldername,-1)=='/' ? '':'/') . '*';
        $files = array_filter(glob($fmask), 'is_file');
        $sizeLimit = self::decodeNumeric(self::$cfg['fileSizeLimit']);
        // echo "files:<pre>".print_r( $files, 1) . '</pre>';

        if (self::$_inConcole)
            $thisfile = $argv[0];
        else
            $thisfile = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['REQUEST_URI'];

        $fileTypes = explode(',', self::$cfg['fileTypes']);
        $ignoreFiles = explode(',', self::$cfg['ignoreFiles']);
        $ignoreFolders = explode(',', self::$cfg['ignoreFolders']);

        $uriFolder = self::$cfg['startUri'];
        if (substr($uriFolder, -1) !=='/') $uriFolder.= '/';

        // echo "this file is: ".$thisfile . '<br>';
        $quest = strpos($thisfile, '?');
        if ($quest>0) $thisfile = substr($thisfile, 0,$quest);

        if (count($files)) foreach($files as $onef) {
            $basename = basename($onef);
            if ($basename === self::$cfg['swFilename']) continue;
            if ($basename === self::MANIFEST_NAME) continue;
            if ($sizeLimit>0 && filesize($onef) > $sizeLimit)
                continue;
            if (realpath($onef) === realpath($thisfile)) continue;
            foreach($ignoreFiles as $igf) {
                if (fnmatch($igf, $basename)) continue 2;
            }
            foreach($fileTypes as $fext) {
                if (fnmatch("*.$fext", $basename)) {
                    $onef = $uriFolder . substr($onef, strlen(self::$cfg['startFolder']));
                    self::$_fl[] = "'$onef'";
                    break;
                }
            }
        }


        $dirs = array_filter(glob($fmask), 'is_dir');
        if (count($dirs)) foreach($dirs as $oned) {
            # $basen = basename($oned);
            foreach($ignoreFolders as $igf) {
                if (fnmatch($igf, basename($oned))) continue 2;
            }
            self::_scanDir($oned);
        }
    }

    public static function decodeNumeric($par) {
        $ret = intval($par);
        if (strtoupper(substr($par,-1)) === 'K') $ret *= 1024;
        elseif (strtoupper(substr($par,-1)) === 'M') $ret *= 1024*1024;
        return $ret;
    }

    public static function getConfig() {
        return self::$cfg;
    }
} // PWAssist end
