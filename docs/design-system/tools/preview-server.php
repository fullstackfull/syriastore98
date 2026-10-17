<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$dir = getenv('SS_PREVIEW_DIR') ?: (sys_get_temp_dir() . '/ss-preview');

// /frame/<file>.html?w=390&h=760 -> wraps the preview in an iframe of EXACTLY w
// CSS px. Chromium refuses a window narrower than 500px here, but an iframe
// gets a true viewport of its own, so media queries evaluate at the real width.
if (preg_match('#^/frame/([A-Za-z0-9._-]+\.html)$#', $uri, $m)) {
    $w = (int)($_GET['w'] ?? 390); $h = (int)($_GET['h'] ?? 760);
    $src = '/preview/' . $m[1] . (isset($_GET['diag']) ? '?diag=1' : '');
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><style>
      html,body{margin:0;padding:0;background:#c9d4d3}
      iframe{width:{$w}px;height:{$h}px;border:0;display:block;background:#fff}
      #relay{font:11px monospace;white-space:pre-wrap;margin:0;padding:4px;background:#000;color:#0f0}
    </style></head><body><iframe src='{$src}'></iframe><pre id='relay'></pre>
    <script>window.addEventListener('message',function(e){document.getElementById('relay').textContent=e.data;});</script>
    </body></html>";
    return true;
}

if (preg_match('#^/preview/([A-Za-z0-9._-]+\.html)$#', $uri, $m)) {
    $f = $dir . '/' . $m[1];
    if (!is_file($f)) { http_response_code(404); echo 'no such preview'; return true; }
    $html = file_get_contents($f);
    if (isset($_GET['diag'])) {
        $diag = <<<'JS'
<script>
window.addEventListener('load', function () {
  var vw = document.documentElement.clientWidth, out = [];
  out.push('VIEWPORT=' + vw + ' SCROLLW=' + document.documentElement.scrollWidth);
  document.querySelectorAll('*').forEach(function (el) {
    var r = el.getBoundingClientRect();
    if (r.width > vw + 1 || r.right > vw + 1 || r.left < -1) {
      out.push((el.tagName.toLowerCase() + '.' + (el.className || '(none)').toString().slice(0,50))
               + ' w=' + Math.round(r.width) + ' left=' + Math.round(r.left) + ' right=' + Math.round(r.right));
    }
  });
  var pre = document.createElement('pre'); pre.id='ss-diag';
  pre.style.cssText='position:fixed;inset-block-end:0;inset-inline-start:0;z-index:9999;font:10px monospace;background:#000;color:#0f0;margin:0;padding:4px;max-width:100%;white-space:pre-wrap';
  pre.textContent = out.join('\n'); document.body.appendChild(pre);
  try { parent.postMessage(out.join('\n'), '*'); } catch(e) {}
});
</script>
JS;
        $html = str_replace('</body>', $diag . '</body>', $html);
    }
    header('Content-Type: text/html; charset=utf-8'); echo $html; return true;
}
return false;
